import json
import os
import sys

from lib.folio_schema_lib import (
    prompt_required,
    prompt_with_default,
    prompt_for_schema_resource,
    prompt_for_data_file,
    load_data_file_columns,
    build_display_names,
    format_field_grid,
    RefResolver,
    load_schema,
    build_key_lines,
    mapped_mark_content,
    apply_user_import_exception,
    is_readonly,
)

HELP_TEXT = """\
Usage: create_map.py [options]

Interactively builds a folio_migration_tools mapping JSON file (and a plain
key-list .txt file) from a FOLIO JSON schema. Prompts you to pick the object
to map (Users, Holdings, Items, or Other to enter a path/URL), then for a
legacy field name (or literal value) for each mappable field.

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
  --data-file PATH        Skip the "Enter path to a TSV data file..."
                          prompt and use PATH directly as the legacy-field
                          picker's column source. A bad/empty path exits
                          immediately (no retry prompts, since this is a
                          fixed command-line value, not something to retype).
  --help, -h              Show this help message and exit.
"""

if "--help" in sys.argv[1:] or "-h" in sys.argv[1:]:
    print(HELP_TEXT, end="")
    sys.exit(0)

mark_mapped = "--no-text-annotation" not in sys.argv[1:]
empty_map = "--map-template-only" in sys.argv[1:]
compact = "--compact" in sys.argv[1:]

data_file_arg = None
if "--data-file" in sys.argv[1:]:
    idx = sys.argv.index("--data-file")
    if idx + 1 >= len(sys.argv):
        print("Error: --data-file requires a value.", file=sys.stderr)
        sys.exit(1)
    data_file_arg = sys.argv[idx + 1]

# --map-template-only is its own mode: nothing is ever mapped, so there's
# nothing for --compact to remove or --no-text-annotation to suppress. Both
# are ignored (rather than silently accepted as no-op combinations) so the
# switches don't multiply into combinations that produce identical output.
if empty_map and (compact or not mark_mapped):
    print("Note: --map-template-only ignores --compact and --no-text-annotation.", file=sys.stderr)
    compact = False
    mark_mapped = True

# Asked first, before anything else: a TSV data file to pick legacy fields
# from instead of typing them. Blank (or --data-file omitted) skips it
# entirely -- everything below behaves exactly as it did before this
# feature existed.
if data_file_arg is not None:
    try:
        data_file_result = (data_file_arg, load_data_file_columns(data_file_arg))
    except OSError as e:
        print(f"Error: couldn't read '{data_file_arg}': {e}", file=sys.stderr)
        sys.exit(1)
    except ValueError as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)
else:
    data_file_result = prompt_for_data_file()

if data_file_result:
    data_file_path, data_columns = data_file_result
    data_display_names = build_display_names(data_columns)
    data_usage_counts = [0] * len(data_columns)
    data_usage_fields = [[] for _ in data_columns]
else:
    data_file_path, data_columns = None, []
    data_display_names, data_usage_counts, data_usage_fields = [], [], []
grid_shown = False

input_path, (schema, output_dir, output_stem, repo) = prompt_for_schema_resource(load_schema)

# Blank stays in the mapping folder itself; a value creates that as a
# subfolder of mapping, rather than replacing it.
subfolder = prompt_with_default("Enter folder to save the maps to (blank for mapping itself): ", "")
mapping_dir = os.path.join(output_dir, "mapping", subfolder) if subfolder else os.path.join(output_dir, "mapping")
os.makedirs(mapping_dir, exist_ok=True)
output_path = os.path.join(mapping_dir, output_stem + "_mapping.json")

if repo:
    print(f"Resolving $ref pointers against {repo} on GitHub...\n")
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

# Filled by build_and_prompt_object's top-level (defer_arrays=True) walk:
# every array field reached while sweeping scalars -- no matter how deep in
# nested plain objects -- is queued here instead of being prompted on the
# spot, so it waits for the ENTIRE schema's scalar fields to be done first.
# Handled as one batch, in discovery order, after that walk finishes (see
# bottom of file). This is schema-wide, not just per nesting level: it's
# what makes array prompts a single global "last" phase rather than each
# object level getting its own early switch-over.
deferred_arrays = []

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
    if subschema.get("x-reference-data"):
        full += "  (reference data)"
    if is_required:
        full = ("Required if used. " if required_if_used else "REQUIRED ") + full

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
    # A field inside an array instance (e.g. additionalCallNumbers[0].callNumber)
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

# Lists the fields each instance of an array would have, for "?" at the
# "how many instances" prompt -- so you can see what you're about to map
# before committing to a count. An array of objects lists its top-level
# properties (same metadata/_version/readonly exclusions used everywhere
# else); an array of scalars just names the single value's type, since
# there's nothing else to list.
def describe_array_item_fields(items):
    props = items.get("properties")
    if not props:
        item_type = items.get("type", "unknown")
        return f"Each instance of this array is a single {item_type} value (no sub-fields)."
    lines = ["Fields in each instance:"]
    for key, sub in sorted(props.items()):
        if key in ("metadata", "_version") or is_readonly(sub):
            continue
        sub_type = sub.get("type", "object" if sub.get("properties") else "unknown")
        if isinstance(sub_type, list):
            sub_type = ",".join(sub_type)
        lines.append(f"  {key} ({sub_type})")
    return "\n".join(lines)

def ask_count(full_path, items):
    while True:
        response = input(f"How many instances of '{full_path}' should be included in the map? [2] (?=show fields): ").strip()
        if not response:
            return 2
        if response == "?":
            print(describe_array_item_fields(items))
            continue
        try:
            return int(response)
        except ValueError:
            print(f"Error: '{response}' isn't a whole number. Enter a number, or press enter for 2.", file=sys.stderr)

# Since arrays are now sized just-in-time, the eventual total row count
# isn't known in advance -- so instead of a "(N left)" countdown, this just
# counts up: how many legacy-field prompts have been shown so far.
prompted_count = 0

def next_prompt_number():
    global prompted_count
    prompted_count += 1
    return prompted_count

# Backs the "#N" correction command: every leaf prompt registers itself
# here (row, is_required) under its own number the moment it's first
# asked, so a later prompt can jump back and re-run that exact question.
prompt_registry = {}

def describe_current_answer(row):
    if row["legacy_field"] != "Not mapped":
        return f"legacy field '{row['legacy_field']}'"
    if row["value"] != "" and row["value"] is not None:
        return f"literal {json.dumps(row['value'])}"
    return "Not mapped"

# Shown once, the first time any leaf prompt appears -- not on every one,
# since that would be noisy across a schema with 100+ fields.
correction_hint_shown = False

def record_usage(folio_field, resolved):
    if resolved in data_columns:
        idx = data_columns.index(resolved)
        data_usage_counts[idx] += 1
        data_usage_fields[idx].append(folio_field)

# Reverses record_usage -- needed when a "#N" correction overwrites a row
# that had previously consumed a data column, so that column's usage
# count/log entry doesn't double-count the old answer alongside the new
# one. A no-op for a fresh row (whose previous value is always "Not
# mapped", never a data column) or a correction that didn't involve a
# column either time.
def unrecord_usage(folio_field, previous_value):
    if previous_value in data_columns:
        idx = data_columns.index(previous_value)
        if folio_field in data_usage_fields[idx]:
            data_usage_fields[idx].remove(folio_field)
            data_usage_counts[idx] -= 1

# The actual input loop for one leaf prompt, shared by a fresh prompt and
# a "#N" correction re-ask of an already-answered one. On a fresh prompt,
# blank means "Not mapped" (unchanged default). On a correction, blank
# means "leave it as it is" instead -- jumping back to double-check an
# answer shouldn't risk silently wiping it if you just hit enter.
#
# "#N" is checked first, ahead of the normal answer handling, so it works
# the same whether you're answering for the first time or mid-correction
# (nested "#N" within a correction just recurses one level deeper, which
# is fine for interactive use). When a data file is loaded, a bare number
# is always resolved as a picker index, not as literal text -- an
# out-of-range number (e.g. "0", or past the end of the list) is a
# mistake, not a hardcoded field name, so it re-prompts with a note
# rather than silently becoming legacy_field "0".
def ask_leaf(number, row, is_required, is_correction=False):
    global grid_shown, correction_hint_shown
    folio_field = row["folio_field"]
    note = required_note(folio_field, is_required)
    if data_columns and not grid_shown:
        print(format_field_grid(data_columns, data_display_names, data_usage_counts))
        print("(* = already used; fields can be reused. Enter a number, \"text\"/'text', true/false, a field name, or ? to relist.)")
        grid_shown = True
    if not correction_hint_shown:
        print("(Tip: type #N at any prompt to jump back and correct prompt number N's answer.)")
        correction_hint_shown = True
    hint = " (number/\"text\"/true/false/?/#N): " if data_columns else " (#N to correct an earlier answer): "

    while True:
        default_desc = f"[current: {describe_current_answer(row)}, blank keeps it]" if is_correction else "[Not mapped]"
        answer = input(f"Enter legacy field for '{folio_field}' (#{number}){note} {default_desc}{hint}").strip()

        if data_columns and answer.lower() in ("?", "list"):
            print(format_field_grid(data_columns, data_display_names, data_usage_counts))
            continue

        if answer.startswith("#") and answer[1:].isdigit():
            target_n = int(answer[1:])
            entry = prompt_registry.get(target_n)
            if not entry:
                print(f"Error: there's no prompt #{target_n} yet.", file=sys.stderr)
            else:
                target_row, target_required = entry
                print(f"--- Correcting #{target_n} ('{target_row['folio_field']}'); current answer: {describe_current_answer(target_row)} ---")
                ask_leaf(target_n, target_row, target_required, is_correction=True)
                print(f"--- Back to #{number} ---")
            continue

        if data_columns and answer.isdigit():
            idx = int(answer)
            if not (1 <= idx <= len(data_columns)):
                print(
                    f"Note: {idx} is out of range (1-{len(data_columns)}); enter a number from the list, "
                    "a field name, a quoted literal, true/false, or press enter to keep/clear it.",
                    file=sys.stderr,
                )
                continue
            answer = data_columns[idx - 1]

        if not answer:
            if not is_correction:
                unrecord_usage(folio_field, row["legacy_field"])
                apply_answer(row, "Not mapped")
            return

        unrecord_usage(folio_field, row["legacy_field"])
        apply_answer(row, answer)
        if not is_unmapped_row(row):
            record_usage(folio_field, answer)
        return

# Prompts for a single leaf row. Every row -- including every instance of
# an array field -- is prompted independently with a plain "Not mapped"
# default. Nothing is ever guessed or suggested from a prior instance's
# answer: whatever the user types (or the "Not mapped" default) is used
# exactly as given, with no suffix appended.
def prompt_leaf(row, is_required):
    number = next_prompt_number()
    prompt_registry[number] = (row, is_required)
    ask_leaf(number, row, is_required)

# Arrays are handled just-in-time: the "how many instances" question for a
# given array is asked right when that array is reached, immediately before
# its own field prompts -- not as a separate batch of questions up front.
# Entering 0 still creates one placeholder instance (so the schema structure
# is documented in the map), but every field in it is set to "Not mapped"
# automatically, with no prompting at all.
def handle_array(sub, full_path, is_required, force_silent):
    items = sub.get("items", {})
    count = 0 if force_silent else ask_count(full_path, items)
    effective_count = count if count > 0 else 1
    child_silent = force_silent or count == 0

    if items.get("properties"):
        for idx in range(effective_count):
            build_and_prompt_instance(items, f"{full_path}[{idx}]", child_silent)
    else:
        leaf_schema = items if items else sub
        if leaf_schema.get("type") == "object":
            # object with no defined structure -> excluded from output
            object_keys.append(full_path)
            return
        for idx in range(effective_count):
            indexed_path = f"{full_path}[{idx}]"
            row = build_row(indexed_path, leaf_schema, is_required)
            if child_silent:
                auto_unmap(row)
            else:
                prompt_leaf(row, is_required)

# One specific array-of-objects instance (e.g. additionalCallNumbers[0]).
# Its own direct leaf fields are batched: any "REQUIRED if used" field among
# them is prompted first; if none end up mapped, the rest of the instance's
# fields (including further nested arrays/objects) are auto-set to
# "Not mapped" with no further prompting -- an unused required field means
# the whole instance is unused. Within an instance, all non-array fields
# (leaves and nested plain objects) are prompted first, in alphabetical
# order; array fields always come last, each announced with its own
# "Switching to array fields" banner and a visible per-field break.
def build_and_prompt_instance(node, path, silent):
    node_required = set(node.get("required", []))
    leaf_items, array_items, nested_obj_items = [], [], []
    for key, sub in sorted((node.get("properties") or {}).items()):
        if key == "metadata" or key == "_version" or is_readonly(sub):
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
            build_and_prompt_object(sub, f"{path}.{key}", silent=True, defer_arrays=False)
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

    for key, sub in nested_obj_items:
        build_and_prompt_object(sub, f"{path}.{key}", silent=child_silent, defer_arrays=False)

    if array_items and not child_silent:
        print(f"\n--- Switching to array fields under '{path}' ---\n")
    for key, sub in array_items:
        if not child_silent:
            print(f"--- {path}.{key} ---")
        handle_array(sub, f"{path}.{key}", key in node_required, force_silent=child_silent)

# Top-level schema and any plain (non-array) nested object. No batching or
# reordering among leaves here -- each one is handled independently. (The
# "required means the rest gets skipped" behavior is scoped to array
# instances, since unlike an array a plain object's fields generally
# aren't optional as a group.) Fields are split into two alphabetical
# groups: all non-array fields (leaves and nested plain objects) first,
# then all array fields.
#
# defer_arrays controls where that second group goes:
#   - True (the default, used for the top-level walk starting from the
#     schema root): array fields are queued onto the module-level
#     deferred_arrays list instead of being prompted here, and nested plain
#     objects are recursed into with defer_arrays still True -- so every
#     array anywhere in the schema, at any depth, waits for the whole
#     schema's scalar sweep to finish. They're all handled together, after
#     one banner, once that walk returns (see bottom of file).
#   - False: the original immediate, per-level behavior -- this level's own
#     array fields are handled right here, with their own local "Switching
#     to array fields" banner. Used when build_and_prompt_object is called
#     for a nested plain object *inside* an array instance
#     (build_and_prompt_instance): by that point the global scalar/array
#     split has already happened, so further nesting just behaves as before.
def build_and_prompt_object(node, path, silent, defer_arrays=True):
    node_required = set(node.get("required", []))
    scalar_items, array_items = [], []
    for key, sub in sorted((node.get("properties") or {}).items()):
        if key == "metadata" or key == "_version" or is_readonly(sub):
            continue
        full_path = f"{path}.{key}" if path else key
        sub_type = sub.get("type")
        if sub_type == "array":
            array_items.append((key, sub, full_path))
        elif sub.get("properties"):
            scalar_items.append(("nested", key, sub, full_path))
        elif sub_type == "object":
            object_keys.append(full_path)
        else:
            scalar_items.append(("leaf", key, sub, full_path))

    for kind, key, sub, full_path in scalar_items:
        is_required = key in node_required
        if kind == "nested":
            build_and_prompt_object(sub, full_path, silent, defer_arrays=defer_arrays)
        else:
            row = build_row(full_path, sub, is_required)
            if silent:
                auto_unmap(row)
            else:
                prompt_leaf(row, is_required)

    if defer_arrays:
        for key, sub, full_path in array_items:
            deferred_arrays.append((full_path, sub, key in node_required, silent))
        return

    if array_items and not silent:
        label = f" under '{path}'" if path else ""
        print(f"\n--- Switching to array fields{label} ---\n")
    for key, sub, full_path in array_items:
        is_required = key in node_required
        if not silent:
            print(f"--- {full_path} ---")
        handle_array(sub, full_path, is_required, force_silent=silent)

# --map-template-only skips all interactive prompting: every field is included with
# legacy_field left as "Not mapped" (reusing the same silent auto-unmap path
# already used for unused/skipped array instances).
build_and_prompt_object(schema, "", silent=empty_map)

# Every array field queued during that walk (deferred_arrays), handled as
# one global batch now that the schema's entire scalar sweep is done --
# this is the single schema-wide "Switching to array fields" moment.
if deferred_arrays and not empty_map:
    print("\n--- Switching to array fields ---\n")
for full_path, sub, is_required, silent in deferred_arrays:
    if not silent:
        print(f"--- {full_path} ---")
    handle_array(sub, full_path, is_required, force_silent=silent)

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
print()
if object_keys:
    print("The following keys are objects with no defined structure in the schema (e.g. custom fields, or unresolved $ref) and were excluded from the output: " + ", ".join(object_keys))
    print()
print(f"Wrote key list to {key_list_path}")

if data_columns:
    log_path = os.path.join(mapping_dir, output_stem + "_field_usage.log")
    used = [(data_display_names[i], data_usage_fields[i]) for i in range(len(data_columns)) if data_usage_fields[i]]
    unused = [data_display_names[i] for i in range(len(data_columns)) if not data_usage_fields[i]]
    with open(log_path, "w") as f:
        f.write(f"Data file: {data_file_path}\n\n")
        f.write("USED COLUMNS:\n")
        for name, fields in used:
            f.write(f"  {name} -> {', '.join(fields)}\n")
        if not used:
            f.write("  (none)\n")
        f.write("\nUNUSED COLUMNS:\n")
        f.write(("  " + ", ".join(unused) + "\n") if unused else "  (none)\n")
    print()
    print(f"Wrote field usage log to {log_path}")

# REQUIRED (unconditional) fields left "Not mapped" are worth flagging --
# unlike "REQUIRED if used" fields inside an array instance, which are
# routinely left unmapped whenever that instance goes unused (by design,
# not a gap), an unconditional REQUIRED field being unmapped is always
# worth a second look. This is informational only -- nothing earlier in
# the script stops you from leaving a required field unmapped.
unmapped_required = [f for f in required_fields if "[" not in f and is_unmapped_row(rows_by_field[f])]
if unmapped_required:
    print()
    print("Warning: these REQUIRED fields were left \"Not mapped\": " + ", ".join(unmapped_required))
