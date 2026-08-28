import html
import json
import os
import posixpath
import re
import sys
import urllib.error
import urllib.request
from urllib.parse import urlparse

from .yaml_lite import parse_yaml, resolve_pointer, dereference_local

HTTP_HEADERS = {"User-Agent": "folio-schema-tools"}

# Unauthenticated GitHub API requests are capped at 60/hour; set GITHUB_TOKEN
# (any personal access token works, no scopes needed for public repos) to
# raise that to 5000/hour. Added only for requests actually going to GitHub
# -- sending it to other hosts (e.g. the s3.amazonaws.com doc pages) can
# make them reject the request outright, since they don't expect a GitHub
# token in that header.
_github_token = os.environ.get("GITHUB_TOKEN")


def _is_github_host(url):
    host = urlparse(url).hostname or ""
    return host == "api.github.com" or host.endswith(".githubusercontent.com")


def _request_headers(url):
    headers = dict(HTTP_HEADERS)
    if _github_token and _is_github_host(url):
        headers["Authorization"] = f"token {_github_token}"
    return headers


def prompt_required(prompt_text):
    try:
        value = input(prompt_text).strip()
    except EOFError:
        value = ""
    if not value:
        print("Error: no input provided.", file=sys.stderr)
        sys.exit(1)
    return value


def prompt_with_default(prompt_text, default):
    try:
        value = input(prompt_text).strip()
    except EOFError:
        value = ""
    return value if value else default


# Prompts for a file path or URL, then runs `action` on it (e.g. load_schema
# or reading a file) -- action is expected to sys.exit(1), with its own
# error message, if the resource can't be found/read. Rather than ending
# the script on the first typo, this re-prompts up to max_attempts times in
# total before giving up for good.
def prompt_for_resource(prompt_text, action, max_attempts=3):
    for attempt in range(1, max_attempts + 1):
        value = prompt_required(prompt_text)
        try:
            return value, action(value)
        except SystemExit:
            remaining = max_attempts - attempt
            if remaining > 0:
                print(f"Please try again ({remaining} attempt{'s' if remaining != 1 else ''} left).", file=sys.stderr)
            else:
                print("Too many failed attempts. Exiting.", file=sys.stderr)
                sys.exit(1)

# These two schemas are standardized and identical across every FOLIO module,
# so they're hardcoded rather than looked up.
KNOWN_SCHEMAS = {
    "tags.schema": {
        "type": "object",
        "description": "arbitrary tags associated with this record",
        "properties": {
            "tagList": {
                "description": "List of tags",
                "type": "array",
                "items": {"type": "string"}
            }
        },
        "additionalProperties": False
    },
    "metadata.schema": {
        "type": "object",
        "readonly": True,
        "description": "Metadata about creation and changes to records, provided by the server (client should not provide)",
        "properties": {
            "createdDate": {"type": "string", "description": "Date and time when the record was created"},
            "createdByUserId": {"type": "string", "description": "ID of the user who created the record (when available)"},
            "createdByUsername": {"type": "string", "description": "Username of the user who created the record (when available)"},
            "updatedDate": {"type": "string", "description": "Date and time when the record was last updated"},
            "updatedByUserId": {"type": "string", "description": "ID of the user who last updated the record (when available)"},
            "updatedByUsername": {"type": "string", "description": "Username of the user who last updated the record (when available)"}
        },
        "additionalProperties": False
    }
}


def fetch_json(url):
    try:
        req = urllib.request.Request(url, headers=_request_headers(url))
        with urllib.request.urlopen(req, timeout=15) as f:
            return json.loads(f.read().decode("utf-8"))
    except Exception:
        return None


class RefResolver:
    """Resolves $ref pointers in a FOLIO module's schema tree by locating the
    referenced file anywhere in that module's GitHub repo and inlining it."""

    def __init__(self, repo):
        self.repo = repo
        self.tree_paths = None
        self.file_cache = {}

    def _load_tree(self):
        if self.tree_paths is not None:
            return
        self.tree_paths = []
        for branch in ("master", "main"):
            data = fetch_json(f"https://api.github.com/repos/{self.repo}/git/trees/{branch}?recursive=1")
            if data and data.get("tree"):
                self.tree_paths = [t["path"] for t in data["tree"] if t.get("type") == "blob"]
                break

    def _find_path(self, basename):
        self._load_tree()
        candidates = [p for p in self.tree_paths if posixpath.basename(p) == basename]
        if not candidates:
            return None
        # prefer paths that live under a "schemas" directory
        candidates.sort(key=lambda p: 0 if "/schemas/" in p else 1)
        return candidates[0]

    def fetch_ref(self, ref_value):
        basename = posixpath.basename(ref_value)
        if basename in KNOWN_SCHEMAS:
            return KNOWN_SCHEMAS[basename]
        if basename in self.file_cache:
            return self.file_cache[basename]
        path = self._find_path(basename)
        if not path:
            self.file_cache[basename] = None
            return None
        resolved = None
        for branch in ("master", "main"):
            resolved = fetch_json(f"https://raw.githubusercontent.com/{self.repo}/{branch}/{path}")
            if resolved is not None:
                break
        self.file_cache[basename] = resolved
        return resolved

    def dereference(self, node, depth=0):
        if depth > 6:
            return node
        if isinstance(node, list):
            return [self.dereference(v, depth + 1) for v in node]
        if not isinstance(node, dict):
            return node

        ref = node.get("$ref")
        if ref:
            resolved = self.fetch_ref(ref)
            if resolved is not None:
                merged = dict(resolved)
                for k, v in node.items():
                    if k != "$ref":
                        merged[k] = v
                node = merged

        return {k: self.dereference(v, depth + 1) for k, v in node.items()}


# Redoc-rendered OpenAPI doc pages (.../api/{repo}/s/{page}.html) don't
# embed a scrapeable schema block like raml2html pages do. Across the FOLIO
# ERM modules we've checked (mod-agreements, mod-licenses), the module's
# GitHub repo also publishes a bundled, self-contained OpenAPI YAML for
# each doc page at "docs/API/yamls/{page}.yaml" -- try that exact path
# first, then fall back to a basename search of the whole repo tree
# (preferring anything still under a "yamls" directory, in case the doc
# page's name doesn't match the primary bundle for some module).
def _discover_openapi_yaml_url(repo, page):
    tree_paths = []
    branch_found = None
    for branch in ("master", "main"):
        data = fetch_json(f"https://api.github.com/repos/{repo}/git/trees/{branch}?recursive=1")
        if data and data.get("tree"):
            tree_paths = [t["path"] for t in data["tree"] if t.get("type") == "blob"]
            branch_found = branch
            break

    candidates = [p for p in (f"docs/API/yamls/{page}.yaml", f"docs/API/yamls/{page}.yml") if p in tree_paths]
    if not candidates:
        candidates = [
            p for p in tree_paths
            if posixpath.splitext(posixpath.basename(p))[0] == page
            and posixpath.splitext(p)[1].lower() in (".yaml", ".yml")
        ]
        candidates.sort(key=lambda p: (0 if "/yamls/" in p else 1, len(p)))

    if not candidates:
        return None
    return f"https://raw.githubusercontent.com/{repo}/{branch_found}/{candidates[0]}"


# A Redoc operation-permalink fragment, e.g. "#tag/Agreements/operation/postSA"
# -- the tag is only there for the reader's benefit, since operationId is
# expected to be unique within a document.
_OPERATION_ANCHOR_RE = re.compile(r"^tag/[^/]+/operation/([^/]+)$")


def _find_operation_schema(doc, operation_id):
    for path_item in (doc.get("paths") or {}).values():
        if not isinstance(path_item, dict):
            continue
        for op in path_item.values():
            if not isinstance(op, dict) or op.get("operationId") != operation_id:
                continue
            content = (op.get("requestBody") or {}).get("content") or {}
            media = content.get("application/json") or next(iter(content.values()), None)
            if isinstance(media, dict) and "schema" in media:
                return media["schema"]
    return None


# A raml2html doc page embeds one request-body schema per endpoint, keyed
# by an anchor. An OpenAPI/YAML document has no such single embedded block,
# so either a JSON-pointer fragment (e.g. "#/components/schemas/Agreement")
# or a Redoc operation-permalink fragment (e.g.
# "#tag/Agreements/operation/postSA") is required instead, to say which
# schema in the document to map -- whether the YAML lives at a URL or a
# local file path.
def _load_openapi_yaml(input_path, is_url, fragment):
    base_path = input_path.split("#", 1)[0]
    if is_url:
        try:
            req = urllib.request.Request(base_path, headers=_request_headers(base_path))
            with urllib.request.urlopen(req) as f:
                raw = f.read().decode("utf-8")
        except (urllib.error.URLError, ValueError, OSError) as e:
            print(f"Error: could not fetch URL '{base_path}': {e}", file=sys.stderr)
            sys.exit(1)
        output_dir = os.getcwd()
    else:
        try:
            with open(base_path) as f:
                raw = f.read()
        except OSError:
            print(f"Error: could not find or read schema file '{base_path}'", file=sys.stderr)
            sys.exit(1)
        output_dir = os.path.dirname(base_path)

    doc = parse_yaml(raw)

    op_match = _OPERATION_ANCHOR_RE.match(fragment)
    if op_match:
        operation_id = op_match.group(1)
        target = _find_operation_schema(doc, operation_id)
        if target is None:
            print(f"Error: could not find operation '{operation_id}' with a JSON request body in '{base_path}'", file=sys.stderr)
            sys.exit(1)
        schema = dereference_local(doc, target)
        # Prefer the referenced component schema's own name (e.g.
        # "Agreement") for the output file name -- the raw operationId
        # (e.g. "postSA") doesn't carry that context. Falls back to the
        # operationId if the request body isn't a single named $ref.
        output_stem = operation_id
        if isinstance(target, dict):
            ref = target.get("$ref")
            if isinstance(ref, str) and ref.startswith("#/"):
                output_stem = ref.rstrip("/").rsplit("/", 1)[-1]
        return schema, output_dir, output_stem, None

    pointer = "#" + fragment
    try:
        target = resolve_pointer(doc, pointer)
    except (KeyError, IndexError, ValueError, TypeError):
        print(f"Error: could not resolve pointer '{pointer}' in '{base_path}'", file=sys.stderr)
        sys.exit(1)

    schema = dereference_local(doc, target)
    output_stem = fragment.rstrip("/").rsplit("/", 1)[-1]
    return schema, output_dir, output_stem, None


def load_schema(input_path):
    parsed = urlparse(input_path)
    is_url = parsed.scheme in ("http", "https")

    is_yaml = os.path.splitext(parsed.path)[1].lower() in (".yaml", ".yml")
    is_openapi_fragment = parsed.fragment.startswith("/") or _OPERATION_ANCHOR_RE.match(parsed.fragment)
    if is_yaml and is_openapi_fragment:
        return _load_openapi_yaml(input_path, is_url, parsed.fragment)

    # A Redoc OpenAPI doc page (as opposed to a raml2html page) given with a
    # JSON-pointer or operation-permalink fragment -- auto-discover the
    # module's bundled YAML and load the pointed-to schema from that
    # instead of trying to scrape the (unscrapeable) HTML page itself.
    openapi_match = re.search(r"/api/([^/]+)/s/([^/]+)\.html$", parsed.path)
    if is_url and openapi_match:
        if not is_openapi_fragment:
            print(
                "Error: this looks like a Redoc/OpenAPI doc page, which doesn't embed a "
                "scrapeable schema block. Give either a JSON-pointer fragment "
                "(e.g. '#/components/schemas/Agreement') or a Redoc operation "
                "permalink fragment (e.g. '#tag/Agreements/operation/postSA').",
                file=sys.stderr,
            )
            sys.exit(1)
        repo = f"folio-org/{openapi_match.group(1)}"
        page = openapi_match.group(2)
        yaml_url = _discover_openapi_yaml_url(repo, page)
        if not yaml_url:
            print(f"Error: could not find a bundled OpenAPI YAML for page '{page}' in {repo}", file=sys.stderr)
            sys.exit(1)
        return _load_openapi_yaml(yaml_url + "#" + parsed.fragment, True, parsed.fragment)

    if not is_url:
        try:
            with open(input_path) as f:
                schema = json.load(f)
        except OSError:
            print(f"Error: could not find or read schema file '{input_path}'", file=sys.stderr)
            sys.exit(1)
        except json.JSONDecodeError as e:
            print(f"Error: '{input_path}' is not valid JSON: {e}", file=sys.stderr)
            sys.exit(1)
        input_dir, input_filename = os.path.split(input_path)
        output_stem = os.path.splitext(input_filename)[0].replace("_schema", "")
        return schema, input_dir, output_stem, None

    try:
        req = urllib.request.Request(input_path, headers=_request_headers(input_path))
        with urllib.request.urlopen(req) as f:
            raw = f.read().decode("utf-8")
    except (urllib.error.URLError, ValueError, OSError) as e:
        print(f"Error: could not fetch URL '{input_path}': {e}", file=sys.stderr)
        sys.exit(1)

    # FOLIO doc pages are hosted as https://s3.amazonaws.com/foliodocs/api/{repo}/{view}/{page}.html
    # -- "r" (raml2html, one page per module) and "p" (raml2html, one page
    # per resource) both embed the same kind of scrapeable schema block.
    repo_match = re.search(r"/api/([^/]+)/[rp]/", parsed.path)
    repo = f"folio-org/{repo_match.group(1)}" if repo_match else None

    anchor = parsed.fragment
    if anchor:
        # raml2html doc page (e.g. .../item-storage.html#item_storage_items_post) --
        # the raw schema JSON for that endpoint's request body is embedded in the
        # page as a literal <pre><code>{...}</code></pre> block right after the
        # "<anchor>_request" tab pane.
        pattern = re.compile(
            r'id="' + re.escape(anchor) + r'_request".*?<pre><code>(.*?)</code></pre>',
            re.S
        )
        match = pattern.search(raw)
        if not match:
            print(f"Error: could not find a schema block for anchor '{anchor}' on the page", file=sys.stderr)
            sys.exit(1)
        schema_text = html.unescape(match.group(1))
        try:
            schema = json.loads(schema_text)
        except json.JSONDecodeError as e:
            print(f"Error: schema block for anchor '{anchor}' is not valid JSON: {e}", file=sys.stderr)
            sys.exit(1)
        output_stem = anchor
    else:
        try:
            schema = json.loads(raw)
        except json.JSONDecodeError as e:
            print(f"Error: content at '{input_path}' is not valid JSON: {e}", file=sys.stderr)
            sys.exit(1)
        output_stem = os.path.splitext(os.path.basename(parsed.path))[0].replace("_schema", "")

    output_stem = output_stem.replace("_post", "")

    return schema, os.getcwd(), output_stem, repo


# A field is "actively mapped" if it has a real legacy_field name or a
# literal value. Returns just the bracket's inner content (no brackets):
# the legacy_field name as-is, the value in double quotes, or <true>/<false>
# for a boolean value. Returns "" if the field isn't actively mapped.
def mapped_mark_content(row):
    if not row:
        return ""
    legacy = row.get("legacy_field", "Not mapped")
    if legacy and legacy != "Not mapped":
        return str(legacy)
    value = row.get("value", "")
    if value != "" and value is not None:
        if isinstance(value, bool):
            return "<" + ("true" if value else "false") + ">"
        return '"' + str(value) + '"'
    return ""


# The schema-type note shown per field when listing a template-only key
# list (nothing is ever mapped there, so there's nothing for the usual mark
# to show -- this describes the field's shape instead). Mirrors the
# required/type/enum/pattern/format formatting used for JSON descriptions,
# except enum options are comma-delimited here rather than pipe-delimited.
def type_annotation(subschema, is_required=False, required_if_used=False):
    type_val = subschema.get("type")
    if isinstance(type_val, list):
        type_str = ",".join(type_val)
    else:
        type_str = type_val if type_val else "unknown"
    note = f"type: {type_str}"

    enum_val = subschema.get("enum")
    if enum_val:
        enum_str = ",".join(str(e).lower() if isinstance(e, bool) else str(e) for e in enum_val)
        note += f" enum: {enum_str}"

    format_val = subschema.get("format")
    if format_val:
        note += f"  format: {format_val}"

    pattern_val = subschema.get("pattern")
    if pattern_val:
        uuid_patterns = (
            r"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$",
            r"^[a-f0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$",
        )
        note += " pattern: " + ("UUID" if pattern_val in uuid_patterns else pattern_val)

    if subschema.get("x-reference-data"):
        note += "  (reference data)"

    if is_required:
        note = ("Required if used. " if required_if_used else "REQUIRED ") + note

    return note


def _build_key_tree(node, path, rows_by_field, schema_type_info=False):
    tree = []
    node_required = set(node.get("required") or [])
    for key, val in sorted((node.get("properties") or {}).items()):
        if key == "legacyIdentifier":
            suffix = " (added for f_m_t)"
        elif val.get("readonly") or val.get("readOnly") or key == "_version":
            suffix = " (readonly)"
        else:
            suffix = ""
        full_path = f"{path}.{key}" if path else key
        items = val.get("items") or {}
        is_array = val.get("type") == "array"
        # Arrays are prompted per-instance, but the key list only shows the
        # field's structural position once -- instance 1 is used as the
        # representative row to check for an active mapping.
        lookup_path = f"{full_path}[1]" if is_array else full_path
        content = mapped_mark_content(rows_by_field.get(lookup_path))
        is_required = key in node_required
        type_note = type_annotation(val, is_required, "[" in full_path) if schema_type_info else ""
        if is_array and items.get("properties"):
            children = _build_key_tree(items, f"{full_path}[1]", rows_by_field, schema_type_info)
        elif val.get("properties"):
            children = _build_key_tree(val, full_path, rows_by_field, schema_type_info)
        else:
            children = []
        mapped = bool(content) or any(c["mapped"] for c in children)
        tree.append({
            "key": key, "suffix": suffix, "content": content, "type_note": type_note,
            "children": children, "mapped": mapped,
        })
    return tree


def _flatten_key_tree(tree, depth, show_marks, compact, schema_type_info=False):
    lines = []
    for node in tree:
        if compact and not node["mapped"]:
            continue
        prefix = ("  " * depth) + ("|" if depth > 0 else "")
        mark = f"  [{node['content']}]" if (show_marks and node["content"]) else ""
        type_suffix = f"  {node['type_note']}" if (schema_type_info and node["type_note"]) else ""
        lines.append(prefix + node["key"] + node["suffix"] + mark + type_suffix)
        lines.extend(_flatten_key_tree(node["children"], depth + 1, show_marks, compact, schema_type_info))
    return lines


# rows_by_field drives both the display marks and the --compact filtering,
# so it's built the same way regardless of whether marks are shown --
# show_marks and compact are independent toggles on top of the same data.
# If rows_by_field is unavailable (e.g. the provided file didn't parse),
# compact filtering is skipped rather than dropping every field.
def build_key_lines(node, rows_by_field=None, show_marks=True, compact=False, schema_type_info=False):
    tree = _build_key_tree(node, "", rows_by_field or {}, schema_type_info)
    return _flatten_key_tree(tree, 0, show_marks, compact and rows_by_field is not None, schema_type_info)


# Special case: the mod-user-import "import" request body wraps the actual
# per-user record in a "users" array alongside unrelated batch-control
# fields (totalRecords, deactivateMissingUsers, updateOnlyPresentFields,
# sourceType). For this one schema, map the individual user record instead
# of the batch wrapper.
USER_IMPORT_URL = "https://s3.amazonaws.com/foliodocs/api/mod-user-import/r/import.html#user_import_post"

def apply_user_import_exception(schema, input_path):
    if input_path != USER_IMPORT_URL:
        return schema
    users_items = (schema.get("properties") or {}).get("users", {}).get("items")
    if not users_items:
        return schema
    new_schema = dict(users_items)
    new_props = dict(new_schema.get("properties") or {})
    for excluded in ("totalRecords", "deactivateMissingUsers", "updateOnlyPresentFields", "sourceType"):
        new_props.pop(excluded, None)
    new_schema["properties"] = new_props
    return new_schema
