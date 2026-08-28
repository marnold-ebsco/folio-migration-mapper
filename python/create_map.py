import json
import os
import re
import sys

from lib.folio_schema_lib import (
    prompt_required,
    RefResolver,
    load_schema,
    build_key_lines,
    mapped_mark_content,
    apply_user_import_exception,
)

HELP_TEXT = """\
Usage: create_map.py [options]

Interactively builds a folio_migration_tools mapping JSON file (and a plain
key-list .txt file) from a FOLIO JSON schema. Prompts for the schema file or
URL, then for a legacy field name (or literal value) for each mappable field.

Options:
  --no-text-annotation    Don't annotate the .txt key list with each field's
                          active mapping (its legacy_field name, in
                          brackets, or its literal value). Annotations are
                          shown by default.
  --compact               Remove fields with no active mapping from both the
                          .txt key list and the JSON map. Off by default
                          (all fields are included in both).
  --map-template-only     A separate mode: skips all interactive prompting
                          and writes one full map with every field present
                          and legacy_field left as "Not mapped". Always
                          produces the same output regardless of
                          --compact/--no-text-annotation, since a map with
                          nothing mapped has nothing to compact or
                          annotate -- those two switches are ignored (with
                          a notice) if given alongside this one.
  --help, -h              Show this help message and exit.
"""

if "--help" in sys.argv[1:] or "-h" in sys.argv[1:]:
    print(HELP_TEXT, end="")
    sys.exit(0)

mark_mapped = "--no-text-annotation" not in sys.argv[1:]
empty_map = "--map-template-only" in sys.argv[1:]
compact = "--compact" in sys.argv[1:]

# --map-template-only is its own mode: nothing is ever mapped, so there's
# nothing for --compact to remove or --no-text-annotation to suppress. Both
# are ignored (rather than silently accepted as no-op combinations) so the
# switches don't multiply into combinations that produce identical output.
if empty_map and (compact or not mark_mapped):
    print("Note: --map-template-only ignores --compact and --no-text-annotation.", file=sys.stderr)
    compact = False
    mark_mapped = True

input_path = prompt_required("Enter path or URL to the schema file: ")
schema, output_dir, output_stem, repo = load_schema(input_path)

mapping_dir = os.path.join(output_dir, "mapping")
os.makedirs(mapping_dir, exist_ok=True)
output_path = os.path.join(mapping_dir, output_stem + "_mapping.json")

if repo:
    print(f"Resolving $ref pointers against {repo} on GitHub...")
    schema = RefResolver(repo).dereference(schema)

schema = apply_user_import_exception(schema, input_path)

# Hardcoded field, added to every map. Injected into the schema itself so it
# sorts alphabetically alongside the schema's own fields; its description is
# blanked out after walk() builds the row, since it isn't a real schema field.
schema.setdefault("properties", {})["legacyIdentifier"] = {"type": "string"}
schema.setdefault("required", [])
if "legacyIdentifier" not in schema["required"]:
    schema["required"].append("legacyIdentifier")

rows = []
object_keys = []
required_fields = []
array_base_legacy = {}

def build_description(subschema, is_required, required_if_used=False):
    base = subschema.get("description", "")
    enum_val = subschema.get("enum")
    format_val = subschema.get("format")
    default_val = subschema.get("default")
    pattern_val = subschema.get("pattern")

    if enum_val:
        type_part = "type: enum: " + "|".join(str(e).lower() if isinstance(e, bool) else str(e) for e in enum_val)
    else:
        type_val = subschema.get("type")
        if isinstance(type_val, list):
            type_str = ",".join(type_val)
        else:
            type_str = type_val if type_val else "unknown"
        type_part = f"type: {type_str}"

    full = base + "  " + type_part if base else type_part

    if format_val:
        full += "  format: " + str(format_val)
    if default_val is not None:
        default_str = str(default_val).lower() if isinstance(default_val, bool) else str(default_val)
        full += "  default: " + default_str
    if pattern_val:
        uuid_patterns = (
            r"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$",
            r"^[a-f0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$",
        )
        if pattern_val in uuid_patterns:
            full += " pattern: UUID"
        else:
            full += " pattern: " + pattern_val
    if is_required:
        full = ("Required if used " if required_if_used else "REQUIRED ") + full

    return full

def build_row(path, subschema, is_required):
    if is_required:
        required_fields.append(path)
    row = {
        "folio_field": path,
        "legacy_field": "Not mapped",
        "value": "",
        "description": build_description(subschema, is_required, required_if_used="[" in path)
    }
    rows.append(row)
    return row

def auto_unmap(row):
    row["legacy_field"] = "Not mapped"
    row["value"] = ""

def is_unmapped_row(row):
    legacy = row.get("legacy_field", "Not mapped")
    value = row.get("value", "")
    return legacy == "Not mapped" and (value == "" or value is None)

def required_note(folio_field, is_required):
    if not is_required:
        return ""
    # A field inside an array instance (e.g. additionalCallNumbers[1].callNumber)
    # is only required if that optional array instance is actually used --
    # the array itself isn't required, so the note is qualified accordingly.
    return " (REQUIRED if used)" if "[" in folio_field else " (REQUIRED)"

# An answer can also be a literal value rather than a legacy field name:
#   - "some text" or 'some text' -> legacy_field stays "Not mapped"; "value"
#                      becomes the string "some text" (quotes stripped)
#   - true / false (any case) -> legacy_field stays "Not mapped"; "value"
#                      becomes the JSON boolean true/false (unquoted)
def parse_answer(raw):
    if len(raw) >= 2 and raw[0] == '"' and raw[-1] == '"':
        return "value", raw[1:-1]
    if len(raw) >= 2 and raw[0] == "'" and raw[-1] == "'":
        return "value", raw[1:-1]
    if raw.lower() == "true":
        return "value", True
    if raw.lower() == "false":
        return "value", False
    return "name", raw

def apply_answer(row, resolved):
    kind, val = parse_answer(resolved)
    if kind == "value":
        row["value"] = val
        row["legacy_field"] = "Not mapped"
    else:
        row["legacy_field"] = val

def ask_count(full_path):
    response = input(f"How many instances of '{full_path}' should be included in the map? [2]: ").strip()
    return int(response) if response else 2

# Since arrays are now sized just-in-time, the eventual total row count
# isn't known in advance -- so instead of a "(N left)" countdown, this just
# counts up: how many legacy-field prompts have been shown so far.
prompted_count = 0

def next_prompt_number():
    global prompted_count
    prompted_count += 1
    return prompted_count

# Prompts for a single leaf row. Rows that belong to an array (their
# folio_field contains one or more "[N]" markers) share a single base
# legacy name across all instances of that array: the first instance is
# prompted normally (default "Not mapped") and, if a real name is given,
# "_1" is appended to it. Every later instance re-uses that base name (with
# its own "_N" suffix) as the suggested default, but is still prompted so
# it can be overridden.
def prompt_leaf(row, is_required):
    folio_field = row["folio_field"]
    note = required_note(folio_field, is_required)
    indices = re.findall(r"\[(\d+)\]", folio_field)
    if not indices:
        progress = f" (#{next_prompt_number()})"
        answer = input(f"Enter legacy field for '{folio_field}'{progress}{note} [Not mapped]: ").strip()
        apply_answer(row, answer if answer else "Not mapped")
        return

    template = re.sub(r"\[\d+\]", "", folio_field)
    suffix = "_" + "_".join(indices)

    if template not in array_base_legacy:
        progress = f" (#{next_prompt_number()})"
        answer = input(f"Enter legacy field for '{folio_field}'{progress}{note} [Not mapped]: ").strip()
        base = answer if answer else "Not mapped"
        array_base_legacy[template] = base
        if base == "Not mapped":
            row["legacy_field"] = "Not mapped"
        elif parse_answer(base)[0] == "value":
            apply_answer(row, base)
        else:
            row["legacy_field"] = base + suffix
    else:
        base = array_base_legacy[template]
        if base == "Not mapped":
            row["legacy_field"] = "Not mapped"
        else:
            suggestion = base if parse_answer(base)[0] == "value" else base + suffix
            progress = f" (#{next_prompt_number()})"
            answer = input(f"Enter legacy field for '{folio_field}'{progress}{note} [{suggestion}]: ").strip()
            apply_answer(row, answer if answer else suggestion)

# Arrays are handled just-in-time: the "how many instances" question for a
# given array is asked right when that array is reached, immediately before
# its own field prompts -- not as a separate batch of questions up front.
# Entering 0 still creates one placeholder instance (so the schema structure
# is documented in the map), but every field in it is set to "Not mapped"
# automatically, with no prompting at all.
def handle_array(sub, full_path, is_required, force_silent):
    items = sub.get("items", {})
    count = 0 if force_silent else ask_count(full_path)
    effective_count = count if count > 0 else 1
    child_silent = force_silent or count == 0

    if items.get("properties"):
        for idx in range(1, effective_count + 1):
            build_and_prompt_instance(items, f"{full_path}[{idx}]", child_silent)
    else:
        leaf_schema = items if items else sub
        if leaf_schema.get("type") == "object":
            # object with no defined structure -> excluded from output
            object_keys.append(full_path)
            return
        for idx in range(1, effective_count + 1):
            indexed_path = f"{full_path}[{idx}]"
            row = build_row(indexed_path, leaf_schema, is_required)
            if child_silent:
                auto_unmap(row)
            else:
                prompt_leaf(row, is_required)

# One specific array-of-objects instance (e.g. additionalCallNumbers[1]).
# Its own direct leaf fields are batched: any "REQUIRED if used" field among
# them is prompted first; if none end up mapped, the rest of the instance's
# fields (including further nested arrays/objects) are auto-set to
# "Not mapped" with no further prompting -- an unused required field means
# the whole instance is unused. Nested arrays/objects otherwise recurse and
# ask their own questions normally.
def build_and_prompt_instance(node, path, silent):
    node_required = set(node.get("required", []))
    leaf_items, array_items, nested_obj_items = [], [], []
    for key, sub in sorted((node.get("properties") or {}).items()):
        if key == "metadata" or key == "_version" or sub.get("readonly") or sub.get("readOnly"):
            continue
        sub_type = sub.get("type")
        if sub_type == "array":
            array_items.append((key, sub))
        elif sub.get("properties"):
            nested_obj_items.append((key, sub))
        elif sub_type == "object":
            object_keys.append(f"{path}.{key}")
        else:
            leaf_items.append((key, sub))

    if silent:
        for key, sub in leaf_items:
            row = build_row(f"{path}.{key}", sub, key in node_required)
            auto_unmap(row)
        for key, sub in array_items:
            handle_array(sub, f"{path}.{key}", key in node_required, force_silent=True)
        for key, sub in nested_obj_items:
            build_and_prompt_object(sub, f"{path}.{key}", silent=True)
        return

    required_leaves = [(k, s) for k, s in leaf_items if k in node_required]
    other_leaves = [(k, s) for k, s in leaf_items if k not in node_required]

    any_required_mapped = False
    for key, sub in required_leaves:
        row = build_row(f"{path}.{key}", sub, True)
        prompt_leaf(row, True)
        if not is_unmapped_row(row):
            any_required_mapped = True

    child_silent = bool(required_leaves) and not any_required_mapped

    for key, sub in other_leaves:
        row = build_row(f"{path}.{key}", sub, False)
        if child_silent:
            auto_unmap(row)
        else:
            prompt_leaf(row, False)

    for key, sub in array_items:
        handle_array(sub, f"{path}.{key}", key in node_required, force_silent=child_silent)
    for key, sub in nested_obj_items:
        build_and_prompt_object(sub, f"{path}.{key}", silent=child_silent)

# Top-level schema and any plain (non-array) nested object. No batching or
# reordering among siblings here -- each leaf is handled independently, in
# schema order. (The "required means the rest gets skipped" behavior is
# scoped to array instances, since unlike an array a plain object's fields
# generally aren't optional as a group.)
def build_and_prompt_object(node, path, silent):
    node_required = set(node.get("required", []))
    for key, sub in sorted((node.get("properties") or {}).items()):
        if key == "metadata" or key == "_version" or sub.get("readonly") or sub.get("readOnly"):
            continue
        full_path = f"{path}.{key}" if path else key
        is_required = key in node_required
        sub_type = sub.get("type")
        if sub_type == "array":
            handle_array(sub, full_path, is_required, force_silent=silent)
        elif sub.get("properties"):
            build_and_prompt_object(sub, full_path, silent)
        elif sub_type == "object":
            object_keys.append(full_path)
        else:
            row = build_row(full_path, sub, is_required)
            if silent:
                auto_unmap(row)
            else:
                prompt_leaf(row, is_required)

# --map-template-only skips all interactive prompting: every field is included with
# legacy_field left as "Not mapped" (reusing the same silent auto-unmap path
# already used for unused/skipped array instances).
build_and_prompt_object(schema, "", silent=empty_map)

for row in rows:
    if row["folio_field"] == "legacyIdentifier":
        row["description"] = "not part of FOLIO schema. Added to work with folio_migration_tools"

# rows_by_field backs both the key-list marks and --compact filtering.
rows_by_field = {row["folio_field"]: row for row in rows}

key_list_path = os.path.join(mapping_dir, output_stem + ".txt")
with open(key_list_path, "w") as f:
    f.write("\n".join(build_key_lines(schema, rows_by_field=rows_by_field, show_marks=mark_mapped, compact=compact, schema_type_info=empty_map)) + "\n")

# --compact drops fields with no active mapping from the JSON map too.
if compact:
    rows = [r for r in rows if mapped_mark_content(r)]

output = {"data": rows}

with open(output_path, "w") as f:
    json.dump(output, f, indent=3)

print(f"Wrote {len(rows)} mapping rows to {output_path}")
if object_keys:
    print("The following keys are objects with no defined structure in the schema (e.g. custom fields, or unresolved $ref) and were excluded from the output: " + ", ".join(object_keys))
print(f"Wrote key list to {key_list_path}")
