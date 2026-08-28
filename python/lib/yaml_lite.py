import re

# A deliberately minimal YAML reader for OpenAPI documents: plain block
# mappings and sequences, scalars, and "|" literal block scalars. No
# anchors/aliases, flow collections beyond "[]"/"{}", or multi-document
# streams -- everything the FOLIO OpenAPI specs we've seen actually use.


# Matches a genuine "key:" prefix (quoted or bare) so a sequence item's
# inline content can be told apart from a plain scalar that happens to
# contain a colon (e.g. a bare "https://..." string has no "key:" prefix).
_KV_START_RE = re.compile(r"^(?:\"[^\"]*\"|'[^']*'|[^:\s]+):(\s|$)")


def _indent_of(line):
    return len(line) - len(line.lstrip(" "))


def _skip_blank(lines, i):
    while i < len(lines) and lines[i].strip() == "":
        i += 1
    return i


def _parse_scalar(text):
    text = text.strip()
    if text == "":
        return None
    if text == "[]":
        return []
    if text == "{}":
        return {}
    if len(text) >= 2 and text[0] == '"' and text[-1] == '"':
        return text[1:-1]
    if len(text) >= 2 and text[0] == "'" and text[-1] == "'":
        return text[1:-1].replace("''", "'")
    low = text.lower()
    if low == "true":
        return True
    if low == "false":
        return False
    if low in ("null", "~"):
        return None
    if re.match(r"^-?\d+$", text):
        return int(text)
    if re.match(r"^-?\d+\.\d+$", text):
        return float(text)
    return text


def _strip_quotes_key(key):
    key = key.strip()
    if len(key) >= 2 and key[0] == key[-1] and key[0] in ("'", '"'):
        return key[1:-1]
    return key


def _split_key_value(content):
    content = content.rstrip()
    if content.startswith('"'):
        end = content.index('"', 1)
        key, rest = content[1:end], content[end + 1:]
    elif content.startswith("'"):
        end = content.index("'", 1)
        key, rest = content[1:end], content[end + 1:]
    else:
        idx = content.find(":")
        key, rest = content[:idx], content[idx:]
    rest = rest.strip()
    if rest.startswith(":"):
        rest = rest[1:].strip()
    return key, rest


def _parse_literal_block(lines, i, parent_indent):
    block_indent = None
    content_lines = []
    while i < len(lines):
        line = lines[i]
        if line.strip() == "":
            content_lines.append("")
            i += 1
            continue
        cur_indent = _indent_of(line)
        if cur_indent <= parent_indent:
            break
        if block_indent is None:
            block_indent = cur_indent
        content_lines.append(line[block_indent:])
        i += 1
    while content_lines and content_lines[-1] == "":
        content_lines.pop()
    return "\n".join(content_lines), i


_BLOCK_SCALAR_RE = re.compile(r"^[|>][+-]?\d*$")


def _consume_kv(lines, i, indent, content, result):
    key, val_text = _split_key_value(content)
    key = _strip_quotes_key(key)
    if val_text == "":
        i += 1
        value, i = _parse_block(lines, i, indent + 1)
        result[key] = value
    elif _BLOCK_SCALAR_RE.match(val_text):
        i += 1
        value, i = _parse_literal_block(lines, i, indent)
        result[key] = value
    else:
        result[key] = _parse_scalar(val_text)
        i += 1
    return i


def _parse_mapping(lines, i, indent, first_override=None):
    result = {}
    if first_override is not None:
        i = _consume_kv(lines, i, indent, first_override, result)
    while True:
        i = _skip_blank(lines, i)
        if i >= len(lines):
            break
        line = lines[i]
        if _indent_of(line) != indent:
            break
        content = line[indent:]
        if content.startswith("- "):
            break
        i = _consume_kv(lines, i, indent, content, result)
    return result, i


def _parse_sequence(lines, i, indent):
    result = []
    while True:
        i = _skip_blank(lines, i)
        if i >= len(lines):
            break
        line = lines[i]
        if _indent_of(line) != indent:
            break
        content = line[indent:]
        if not content.startswith("-"):
            break
        rest = content[1:]
        if rest.startswith(" "):
            rest = rest[1:]
        item_indent = indent + 2
        if rest.strip() == "":
            i += 1
            value, i = _parse_block(lines, i, item_indent)
            result.append(value)
        elif _KV_START_RE.match(rest):
            value, i = _parse_mapping(lines, i, item_indent, first_override=rest)
            result.append(value)
        else:
            result.append(_parse_scalar(rest))
            i += 1
    return result, i


def _parse_block(lines, i, min_indent):
    i = _skip_blank(lines, i)
    if i >= len(lines):
        return None, i
    line = lines[i]
    cur_indent = _indent_of(line)
    if cur_indent < min_indent:
        return None, i
    content = line[cur_indent:]
    if content.startswith("- "):
        return _parse_sequence(lines, i, cur_indent)
    return _parse_mapping(lines, i, cur_indent)


def parse_yaml(text):
    lines = []
    for raw_line in text.split("\n"):
        line = raw_line.rstrip()
        if line.strip() == "---" or line.lstrip().startswith("#"):
            continue
        lines.append(line)
    value, _ = _parse_block(lines, 0, 0)
    return value


def resolve_pointer(root, pointer):
    if not pointer.startswith("#/"):
        raise ValueError(f"unsupported pointer: {pointer}")
    node = root
    for part in pointer[2:].split("/"):
        part = part.replace("~1", "/").replace("~0", "~")
        node = node[int(part)] if isinstance(node, list) else node[part]
    return node


# Resolves local "$ref": "#/..." pointers and collapses "oneOf" branches (a
# oneOf that includes a bare {"$ref": ...} branch alongside inline
# alternatives -- e.g. a field that's either a plain string or a full
# Refdata object -- picks the first non-$ref branch, since that's the
# simpler shape to map). A "currently resolving" set guards against
# circular $refs (e.g. two schemas that reference each other) by cutting
# the cycle short rather than recursing forever.
def dereference_local(root, node, resolving=None):
    if resolving is None:
        resolving = frozenset()
    if isinstance(node, list):
        return [dereference_local(root, v, resolving) for v in node]
    if not isinstance(node, dict):
        return node

    if isinstance(node.get("oneOf"), list) and node["oneOf"]:
        branches = node["oneOf"]
        chosen = next(
            (b for b in branches if not (isinstance(b, dict) and set(b.keys()) == {"$ref"})),
            branches[0],
        )
        merged = dict(chosen) if isinstance(chosen, dict) else {}
        for k, v in node.items():
            if k != "oneOf":
                merged[k] = v
        return dereference_local(root, merged, resolving)

    ref = node.get("$ref")
    if isinstance(ref, str) and ref.startswith("#/"):
        if ref in resolving:
            return {}
        target = dereference_local(root, resolve_pointer(root, ref), resolving | {ref})
        merged = dict(target) if isinstance(target, dict) else {}
        for k, v in node.items():
            if k != "$ref":
                merged[k] = v
        return dereference_local(root, merged, resolving)

    return {k: dereference_local(root, v, resolving) for k, v in node.items()}
