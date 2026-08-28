import json
import os
import re
import sys

from lib.folio_schema_lib import (
    prompt_required,
    prompt_with_default,
    prompt_for_resource,
    RefResolver,
    load_schema,
    build_key_lines,
    mapped_mark_content,
    apply_user_import_exception,
)

HELP_TEXT = """\
Usage: verify_map.py [options]

Builds an in-memory reference ("master") map straight from a FOLIO JSON
schema, then runs 12 validation checks comparing it against an existing
mapping file. Prompts for the schema file or URL, then for the path to the
map file to verify.

Options:
  --no-text-annotation    Don't annotate the master .txt key list with each
                          field's active mapping (its legacy_field name, in
                          brackets, or its literal value) as found in the
                          *provided* map file. Annotations are shown by
                          default.
  --compact               Remove fields with no active mapping in the
                          provided file from both the master .txt key list
                          and the master JSON map. Off by default (all
                          fields are included in both). Only affects the
                          written master files, never the checks
                          themselves.
  --help, -h              Show this help message and exit.
"""

if "--help" in sys.argv[1:] or "-h" in sys.argv[1:]:
    print(HELP_TEXT, end="")
    sys.exit(0)

rows = []
array_counts = {}
required_fields = []


def build_description(subschema, is_required):
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
        full = "REQUIRED " + full

    return full


def add_leaf(path, subschema, is_required=False):
    if is_required:
        required_fields.append(path)
    rows.append({
        "folio_field": path,
        "legacy_field": "Not mapped",
        "value": "",
        "description": build_description(subschema, is_required)
    })


def walk(node, path):
    props = node.get("properties")
    if not props:
        return
    node_required = set(node.get("required", []))
    for key, sub in sorted(props.items()):
        if key == "metadata" or key == "_version":
            continue
        if sub.get("readonly") or sub.get("readOnly"):
            continue
        full_path = f"{path}.{key}" if path else key
        is_required = key in node_required
        sub_type = sub.get("type")
        if sub_type == "array":
            items = sub.get("items", {})
            template_key = re.sub(r"\[\d+\]", "", full_path)
            count = array_counts.get(template_key, 2)
            if items.get("properties"):
                for idx in range(1, count + 1):
                    walk(items, f"{full_path}[{idx}]")
            else:
                leaf_schema = items if items else sub
                if leaf_schema.get("type") == "object":
                    continue  # unstructured object array -> excluded from output
                for idx in range(1, count + 1):
                    add_leaf(f"{full_path}[{idx}]", leaf_schema, is_required)
        elif sub.get("properties"):
            walk(sub, full_path)
        elif sub_type == "object":
            continue  # unstructured object -> excluded from output
        else:
            add_leaf(full_path, sub, is_required)


def discover_arrays(node, path, seen):
    for key, sub in sorted((node.get("properties") or {}).items()):
        if key == "metadata" or key == "_version" or sub.get("readonly") or sub.get("readOnly"):
            continue
        full_path = f"{path}.{key}" if path else key
        items = sub.get("items", {})
        if sub.get("type") == "array":
            if items.get("type") == "object" and not items.get("properties"):
                continue
            if full_path not in seen:
                seen.append(full_path)
            if items.get("properties"):
                discover_arrays(items, full_path, seen)
        elif sub.get("properties"):
            discover_arrays(sub, full_path, seen)
    return seen


# This tool only ever asks for the schema, the map file to verify, and
# where to write the master map/report. Everything else (array instance
# counts for the master map, etc.) is derived automatically, with no
# further prompts.

input_path, (schema, output_dir, output_stem, repo) = prompt_for_resource(
    "Enter path or URL to the schema file: ", load_schema
)

# Blank stays in the mapping folder itself; a value creates that as a
# subfolder of mapping, rather than replacing it.
subfolder = prompt_with_default("Enter folder to save the maps to (blank for mapping itself): ", "")


def _read_map_file(path):
    try:
        with open(path) as f:
            return f.read()
    except OSError:
        print(f"Error: could not find or read map file '{path}'", file=sys.stderr)
        sys.exit(1)


# Read the map file to verify (via raw text, not just the parsed structure)
# so its actual array sizes can inform how many instances the master map
# needs, without ever having to ask.
provided_path, raw_contents = prompt_for_resource(
    "Enter path to the existing map file to verify: ", _read_map_file
)
lines = raw_contents.split("\n")

# Line number of each "data" array element's opening brace, in order. Every
# row object in our pretty-printed format starts on its own line containing
# only "{" -- the first such line is the top-level object itself, so the
# remaining ones map 1:1, in order, to data[0], data[1], data[2], ...
object_open_lines = []
for i, line in enumerate(lines):
    if re.match(r"^\s*\{\s*$", line):
        object_open_lines.append(i + 1)
if object_open_lines:
    object_open_lines.pop(0)  # drop the top-level object's own brace

json_error_msg = None
try:
    parsed = json.loads(raw_contents)
except json.JSONDecodeError as e:
    parsed = None
    json_error_msg = str(e)

is_valid_json = json_error_msg is None and isinstance(parsed, dict) and isinstance(parsed.get("data"), list)

# Collect provided folio_field values, with line numbers, from the raw text.
# This is done via regex (not the parsed structure) so it still works even
# if the file is not valid JSON.
provided_field_lines = {}  # folio_field => [line numbers]
for i, line in enumerate(lines):
    m = re.search(r'"folio_field"\s*:\s*"([^"]*)"', line)
    if m:
        provided_field_lines.setdefault(m.group(1), []).append(i + 1)
provided_fields = list(provided_field_lines.keys())

# ---------------------------------------------------------------------------
# Step 2: build the "master" map straight from the schema (same logic as
# gen_map.py / create_map.py), including a hardcoded legacyIdentifier field,
# so it matches what create_map.py would actually produce.
#
# Array instance counts are derived from the provided file itself (the
# highest index actually used for each array, with a floor of 2) rather than
# prompted for.
# ---------------------------------------------------------------------------

if repo:
    print(f"Resolving $ref pointers against {repo} on GitHub...\n")
    schema = RefResolver(repo).dereference(schema)

schema = apply_user_import_exception(schema, input_path)

schema.setdefault("properties", {})["legacyIdentifier"] = {"type": "string"}
schema.setdefault("required", [])
if "legacyIdentifier" not in schema["required"]:
    schema["required"].append("legacyIdentifier")


def max_index_in_provided(template, fields):
    segments = template.split(".")
    regex_parts = []
    for i, seg in enumerate(segments):
        q = re.escape(seg)
        regex_parts.append(f"{q}\\[(\\d+)\\]" if i == len(segments) - 1 else f"{q}\\[\\d+\\]")
    regex = re.compile("^" + r"\.".join(regex_parts))
    best = 0
    for field in fields:
        m = regex.match(field)
        if m:
            best = max(best, int(m.group(1)))
    return best


array_field_names = discover_arrays(schema, "", [])
for name in array_field_names:
    array_counts[name] = max(2, max_index_in_provided(name, provided_fields))

walk(schema, "")

# Write the master map and key list out to the "mapping" folder, same as
# gen_map.py / create_map.py, so this run's reference map is available for
# inspection or reuse afterward. Named "..._master..." (rather than
# gen_map's usual "..._mapping.json") so it can never collide with, and
# overwrite, the provided map file being verified.
mapping_dir = os.path.join(output_dir, "mapping", subfolder) if subfolder else os.path.join(output_dir, "mapping")
os.makedirs(mapping_dir, exist_ok=True)

# The key list (and, with --compact, the master map itself) reflect what's
# actively mapped in the *provided* file being verified, not the
# always-unmapped master rows -- rows_by_field is None only if the provided
# file didn't parse, in which case marks/compact are both skipped.
mark_mapped = "--no-text-annotation" not in sys.argv[1:]
compact = "--compact" in sys.argv[1:]
provided_rows_by_field = None
if is_valid_json:
    provided_rows_by_field = {
        obj["folio_field"]: obj
        for obj in parsed["data"]
        if isinstance(obj, dict) and "folio_field" in obj
    }

key_list_path = os.path.join(mapping_dir, output_stem + "_master.txt")
with open(key_list_path, "w") as f:
    f.write("\n".join(build_key_lines(schema, rows_by_field=provided_rows_by_field, show_marks=mark_mapped, compact=compact)) + "\n")

# The rest of this script's checks need the *full* master reference, so
# --compact only trims what gets written to the master map file, never the
# in-memory `rows` used for comparisons below.
output_rows = rows
if compact and provided_rows_by_field is not None:
    output_rows = [r for r in rows if mapped_mark_content(provided_rows_by_field.get(r["folio_field"]))]

master_map_path = os.path.join(mapping_dir, output_stem + "_master_mapping.json")
with open(master_map_path, "w") as f:
    json.dump({"data": output_rows}, f, indent=3)

print(f"Wrote master map to {master_map_path}")
print(f"Wrote key list to {key_list_path}")

master_fields = [r["folio_field"] for r in rows]

# Template (bracket indices stripped) set, used for the "spelled exactly
# like the schema" check -- this way a provided field with a higher array
# index than the master happened to generate isn't flagged as a typo.
master_template_set = {re.sub(r"\[\d+\]", "", f) for f in master_fields}

print("\n========================================")
print(f"VERIFICATION RESULTS: {provided_path}")
print("========================================")

# ---------------------------------------------------------------------------
# Check 1: master fields missing from the provided file (exact match).
# ---------------------------------------------------------------------------

provided_field_set = set(provided_fields)
required_field_set = set(required_fields)
# Required fields are reported under check 8 (missing or unmapped) instead,
# whether they're absent entirely or just present-but-blank.
missing_from_provided = [f for f in master_fields if f not in provided_field_set and f not in required_field_set]
print("\n1. Master fields missing from provided file:")
print("   " + (", ".join(missing_from_provided) if missing_from_provided else "None."))

# ---------------------------------------------------------------------------
# Check 2: malformed JSON.
# ---------------------------------------------------------------------------

print("\n2. JSON validity:")
if not is_valid_json:
    if json_error_msg is not None:
        print(f"   MALFORMED JSON: {json_error_msg}")
    else:
        print('   MALFORMED: file is valid JSON but has no top-level "data" array.')
    print("   (Remaining checks that need parsed data will be skipped or limited to text-based scanning.)")
else:
    print("   OK.")

# ---------------------------------------------------------------------------
# Check 3: extra fields on each object (informational only, not modified).
# ---------------------------------------------------------------------------

print("\n3. Extra (non-standard) fields on objects:")
standard_keys = {
    "folio_field", "legacy_field", "value", "description",
    "fallback_legacy_field", "fallback_value", "rules", "rules_apply_scope",
}
extra_field_notes = []
if is_valid_json:
    for i, obj in enumerate(parsed["data"]):
        if not isinstance(obj, dict):
            continue
        extra = [k for k in obj.keys() if k not in standard_keys]
        if extra:
            label = obj.get("folio_field", "(no folio_field)")
            start_line = object_open_lines[i] if i < len(object_open_lines) else None
            end_line = object_open_lines[i + 1] if i + 1 < len(object_open_lines) else len(lines) + 1
            extra_with_lines = []
            for key in extra:
                key_line = None
                if start_line is not None:
                    for ln in range(start_line, end_line):
                        if ln - 1 < len(lines) and re.match(r'^\s*"' + re.escape(key) + r'"\s*:', lines[ln - 1]):
                            key_line = ln
                            break
                extra_with_lines.append(f"{key} (line {key_line})" if key_line is not None else key)
            extra_field_notes.append(f"{label}: " + ", ".join(extra_with_lines))
if not extra_field_notes:
    print("   None." if is_valid_json else "   Skipped (JSON did not parse).")
else:
    for note in extra_field_notes:
        print(f"   {note}")

# ---------------------------------------------------------------------------
# Check 4: duplicate folio_field values, with line numbers.
# ---------------------------------------------------------------------------

print("\n4. Duplicate folio_field values:")
duplicates_found = False
for field, field_lines in provided_field_lines.items():
    if len(field_lines) > 1:
        duplicates_found = True
        print(f"   '{field}' appears on lines " + ", ".join(str(n) for n in field_lines))
if not duplicates_found:
    print("   None.")

# ---------------------------------------------------------------------------
# Check 5: missing array elements (gaps).
#
# For every field with at least one "[N]" index, group by "family" (the text
# up to and including the opening "[" of the LAST index) and by "subfield"
# (family + wildcard index + trailing suffix). The expected index set for a
# family is the union of indices seen across every sibling subfield sharing
# that family; any subfield missing one of those indices gets its
# reconstructed field name reported as missing.
# ---------------------------------------------------------------------------

print("\n5. Missing array elements (gaps):")
families = {}  # familyKey => {"indices": set(), "subfields": {subfieldKey: {"indices": set(), "suffix": str}}}
for field in provided_fields:
    matches = list(re.finditer(r"\[(\d+)\]", field))
    if not matches:
        continue
    last = matches[-1]
    digit = int(last.group(1))
    bracket_start = last.start()
    bracket_end = last.end()

    family_key = field[:bracket_start] + "["
    suffix = field[bracket_end:]
    subfield_key = family_key + "N]" + suffix

    family = families.setdefault(family_key, {"indices": set(), "subfields": {}})
    family["indices"].add(digit)
    sub = family["subfields"].setdefault(subfield_key, {"indices": set(), "suffix": suffix})
    sub["indices"].add(digit)

gaps_found = False
for family_key, family in families.items():
    expected = sorted(family["indices"])
    for subfield_key, sub in family["subfields"].items():
        missing = [idx for idx in expected if idx not in sub["indices"]]
        for missing_idx in missing:
            gaps_found = True
            print(f"   {family_key}{missing_idx}]{sub['suffix']} is missing")
if not gaps_found:
    print("   None.")

# ---------------------------------------------------------------------------
# Check 6: quoted boolean values.
# ---------------------------------------------------------------------------

print("\n6. Quoted boolean values:")
quoted_bool_found = False
for i, line in enumerate(lines):
    m = re.search(r'"value"\s*:\s*"(true|false)"', line, re.IGNORECASE)
    if m:
        quoted_bool_found = True
        print(f'   Line {i + 1}: "value": "{m.group(1)}" should be unquoted')
if not quoted_bool_found:
    print("   None.")

# ---------------------------------------------------------------------------
# Check 7: folio_field values not spelled exactly like the schema. Compared
# at the template level (indices stripped) so a valid field with an array
# index beyond what the master happened to generate isn't incorrectly
# flagged.
# ---------------------------------------------------------------------------

print("\n7. folio_field values not matching the schema:")
misspelled = []
for field in provided_fields:
    template = re.sub(r"\[\d+\]", "", field)
    if template not in master_template_set:
        misspelled.append(field)
if not misspelled:
    print("   None.")
else:
    for field in misspelled:
        field_lines = ", ".join(str(n) for n in provided_field_lines.get(field, []))
        print(f"   '{field}' (line {field_lines})")

# Build a folio_field => row lookup from the parsed data, for the remaining
# checks that need to inspect a specific row's contents. If a folio_field
# repeats (duplicate, already reported above) the last one wins; that's
# fine since duplicates are already flagged separately.
provided_row_by_field = {}
rows_missing_folio_field = []
rows_missing_legacy_field = []
if is_valid_json:
    for i, obj in enumerate(parsed["data"]):
        if not isinstance(obj, dict):
            continue
        if "legacy_field" not in obj:
            rows_missing_legacy_field.append(i)
        if "folio_field" not in obj:
            rows_missing_folio_field.append(i)
            continue
        provided_row_by_field[obj["folio_field"]] = obj

# ---------------------------------------------------------------------------
# Check 8: required fields missing or unmapped. Covers required fields that
# are entirely absent from the file as well as ones present with
# legacy_field "Not mapped" and no literal value given either.
#
# A required field that belongs to an array-of-objects instance (e.g.
# additionalCallNumbers[1].callNumber) is only flagged if at least one
# sibling field within that SAME instance (additionalCallNumbers[1].*) is
# actually mapped. If the whole instance is missing/unmapped, that's just
# the optional array not being used, not a violation.
# ---------------------------------------------------------------------------

UNMAPPED_DEFAULT = {"legacy_field": "Not mapped", "value": ""}


def is_unmapped_row(row):
    legacy = row.get("legacy_field", "Not mapped")
    value = row.get("value", "")
    return legacy == "Not mapped" and (value == "" or value is None)


def array_instance_key(field):
    matches = list(re.finditer(r"\[\d+\]", field))
    if not matches:
        return None
    last = matches[-1]
    return field[:last.end()]


print("\n8. Required fields missing or unmapped:")
required_unmapped = []
if is_valid_json:
    for field in required_fields:
        row = provided_row_by_field.get(field, UNMAPPED_DEFAULT)
        if not is_unmapped_row(row):
            continue  # it's mapped, fine

        instance_key = array_instance_key(field)
        if instance_key is None:
            required_unmapped.append(field)
            continue

        # Only flag if a sibling within the same array instance is mapped;
        # otherwise the whole instance is simply unused.
        for other_field, other_row in provided_row_by_field.items():
            if other_field == field or not other_field.startswith(instance_key + "."):
                continue
            if not is_unmapped_row(other_row):
                required_unmapped.append(field)
                break
if not required_unmapped:
    print("   None." if is_valid_json else "   Skipped (JSON did not parse).")
else:
    print("   " + ", ".join(required_unmapped))

# ---------------------------------------------------------------------------
# Check 9: non-string legacy_field values.
# ---------------------------------------------------------------------------

print("\n9. Non-string legacy_field values:")
non_string_legacy = []
if is_valid_json:
    for field, row in provided_row_by_field.items():
        if "legacy_field" in row and not isinstance(row["legacy_field"], str):
            non_string_legacy.append(f"{field} ({json.dumps(row['legacy_field'])})")
if not non_string_legacy:
    print("   None." if is_valid_json else "   Skipped (JSON did not parse).")
else:
    for note in non_string_legacy:
        print(f"   {note}")

# ---------------------------------------------------------------------------
# Check 10: the same legacy_field value used as the target for more than one
# distinct folio_field (excluding the "Not mapped" sentinel, which is
# expected to repeat).
# ---------------------------------------------------------------------------

print("\n10. Duplicate legacy_field targets:")
legacy_targets = {}  # legacy_field value => [folio_fields]
if is_valid_json:
    for field, row in provided_row_by_field.items():
        legacy = row.get("legacy_field")
        if not isinstance(legacy, str) or legacy == "Not mapped":
            continue
        legacy_targets.setdefault(legacy, []).append(field)
dup_targets_found = False
for legacy, fields in legacy_targets.items():
    if len(fields) > 1:
        dup_targets_found = True
        field_notes = []
        for field in fields:
            field_lines = ", ".join(str(n) for n in provided_field_lines.get(field, []))
            field_notes.append(f"{field} (line {field_lines})" if field_lines else field)
        print(f"   '{legacy}' is used for: " + ", ".join(field_notes))
if not dup_targets_found:
    print("   None." if is_valid_json else "   Skipped (JSON did not parse).")

# ---------------------------------------------------------------------------
# Check 11: rows with no folio_field key at all (wouldn't show up in the
# text-based scans used by the other checks).
# ---------------------------------------------------------------------------

print("\n11. Rows missing a folio_field key entirely:")
if not rows_missing_folio_field:
    print("   None." if is_valid_json else "   Skipped (JSON did not parse).")
else:
    for i in rows_missing_folio_field:
        obj = parsed["data"][i]
        hint = f" (legacy_field: {json.dumps(obj['legacy_field'])})" if "legacy_field" in obj else ""
        # The line number is the actionable reference; data[i] (the row's
        # position in the JSON array) only shows up as a fallback when no
        # line number could be determined for this row.
        location = f"on line {object_open_lines[i]}" if i < len(object_open_lines) else f"data[{i}]"
        print(f"   {location} has no folio_field key{hint}")

# ---------------------------------------------------------------------------
# Check 12: rows with no legacy_field key at all.
# ---------------------------------------------------------------------------

print("\n12. Rows missing a legacy_field key entirely:")
if not rows_missing_legacy_field:
    print("   None." if is_valid_json else "   Skipped (JSON did not parse).")
else:
    for i in rows_missing_legacy_field:
        obj = parsed["data"][i]
        hint = f" (folio_field: {json.dumps(obj['folio_field'])})" if "folio_field" in obj else ""
        location = f"on line {object_open_lines[i]}" if i < len(object_open_lines) else f"data[{i}]"
        print(f"   {location} has no legacy_field key{hint}")

print("\n========================================")
print("Done.")
