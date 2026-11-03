<?php

require_once __DIR__ . "/lib/folio_schema_lib.php";

const HELP_TEXT = <<<'EOT'
Usage: create_map.php [options]

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

EOT;

if (in_array("--help", array_slice($argv, 1)) || in_array("-h", array_slice($argv, 1))) {
    echo HELP_TEXT;
    exit(0);
}

$markMapped = !in_array("--no-text-annotation", array_slice($argv, 1));
$emptyMap = in_array("--map-template-only", array_slice($argv, 1));
$compact = in_array("--compact", array_slice($argv, 1));

$dataFileArg = null;
$dataFileFlagIdx = array_search("--data-file", $argv);
if ($dataFileFlagIdx !== false) {
    if ($dataFileFlagIdx + 1 >= count($argv)) {
        fwrite(STDERR, "Error: --data-file requires a value.\n");
        exit(1);
    }
    $dataFileArg = $argv[$dataFileFlagIdx + 1];
}

// --map-template-only is its own mode: nothing is ever mapped, so there's
// nothing for --compact to remove or --no-text-annotation to suppress. Both
// are ignored (rather than silently accepted as no-op combinations) so the
// switches don't multiply into combinations that produce identical output.
if ($emptyMap && ($compact || !$markMapped)) {
    fwrite(STDERR, "Note: --map-template-only ignores --compact and --no-text-annotation.\n");
    $compact = false;
    $markMapped = true;
}

// Asked first, before anything else: a TSV data file to pick legacy fields
// from instead of typing them. Blank (or --data-file omitted) skips it
// entirely -- everything below behaves exactly as it did before this
// feature existed.
if ($dataFileArg !== null) {
    try {
        $dataFileResult = [$dataFileArg, load_data_file_columns($dataFileArg)];
    } catch (RuntimeException $e) {
        fwrite(STDERR, "Error: couldn't read '$dataFileArg': " . $e->getMessage() . "\n");
        exit(1);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    $dataFileResult = prompt_for_data_file();
}

if ($dataFileResult !== null) {
    [$dataFilePath, $dataColumns] = $dataFileResult;
    $dataDisplayNames = build_display_names($dataColumns);
    $dataUsageCounts = array_fill(0, count($dataColumns), 0);
    $dataUsageFields = array_fill(0, count($dataColumns), []);
} else {
    $dataFilePath = null;
    $dataColumns = [];
    $dataDisplayNames = [];
    $dataUsageCounts = [];
    $dataUsageFields = [];
}
$gridShown = false;

$rows = [];
$object_keys = [];
$required_fields = [];

// Filled by build_and_prompt_object's top-level ($deferArrays=true) walk:
// every array field reached while sweeping scalars -- no matter how deep in
// nested plain objects -- is queued here instead of being prompted on the
// spot, so it waits for the ENTIRE schema's scalar fields to be done first.
// Handled as one batch, in discovery order, after that walk finishes (see
// bottom of file). This is schema-wide, not just per nesting level: it's
// what makes array prompts a single global "last" phase rather than each
// object level getting its own early switch-over.
$deferred_arrays = [];

function build_description($subschema, $isRequired, $requiredIfUsed = false) {
    $base = $subschema["description"] ?? "";
    $enumVal = $subschema["enum"] ?? null;
    $formatVal = $subschema["format"] ?? null;
    $defaultVal = $subschema["default"] ?? null;
    $patternVal = $subschema["pattern"] ?? null;

    if ($enumVal) {
        $typePart = "type: enum: " . implode("|", array_map(function ($e) {
            return is_bool($e) ? ($e ? "true" : "false") : (string)$e;
        }, $enumVal));
    } else {
        $typeVal = $subschema["type"] ?? null;
        if (is_array($typeVal)) {
            $typeStr = implode(",", $typeVal);
        } else {
            $typeStr = $typeVal ? $typeVal : "unknown";
        }
        $typePart = "type: $typeStr";
    }

    $full = $base ? "$base  $typePart" : $typePart;

    if ($formatVal) {
        $full .= "  format: $formatVal";
    }
    if ($defaultVal !== null) {
        $defaultStr = is_bool($defaultVal) ? ($defaultVal ? "true" : "false") : (string)$defaultVal;
        $full .= "  default: $defaultStr";
    }
    if ($patternVal) {
        $uuidPatterns = [
            '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$',
            '^[a-f0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$',
        ];
        if (in_array($patternVal, $uuidPatterns)) {
            $full .= " pattern: UUID";
        } else {
            $full .= " pattern: $patternVal";
        }
    }
    if (!empty($subschema["x-reference-data"])) {
        $full .= "  (reference data)";
    }
    if ($isRequired) {
        $full = ($requiredIfUsed ? "Required if used. " : "REQUIRED ") . $full;
    }

    return $full;
}

// Appends a new row and returns its index in $rows. Callers mutate it via
// $rows[$idx] (passed by reference to auto_unmap()/prompt_leaf()) rather
// than a returned copy, since PHP won't let a plain return value alias
// back into the array it came from.
function build_row_index($path, $subschema, $isRequired) {
    global $rows, $required_fields;
    if ($isRequired) {
        $required_fields[] = $path;
    }
    $rows[] = [
        "folio_field" => $path,
        "legacy_field" => "Not mapped",
        "value" => "",
        "description" => build_description($subschema, $isRequired, strpos($path, "[") !== false)
    ];
    return count($rows) - 1;
}

function auto_unmap(&$row) {
    $row["legacy_field"] = "Not mapped";
    $row["value"] = "";
}

function is_unmapped_row($row) {
    $legacy = $row["legacy_field"] ?? "Not mapped";
    $value = $row["value"] ?? "";
    return $legacy === "Not mapped" && ($value === "" || $value === null);
}

function required_note($folioField, $isRequired) {
    if (!$isRequired) {
        return "";
    }
    // A field inside an array instance (e.g. additionalCallNumbers[0].callNumber)
    // is only required if that optional array instance is actually used --
    // the array itself isn't required, so the note is qualified accordingly.
    return strpos($folioField, "[") !== false ? " (REQUIRED if used)" : " (REQUIRED)";
}

// An answer can also be a literal value rather than a legacy field name:
//   - "some text" or 'some text' -> legacy_field stays "Not mapped"; "value"
//                      becomes the string "some text" (quotes stripped)
//   - true / false (any case) -> legacy_field stays "Not mapped"; "value"
//                      becomes the JSON boolean true/false (unquoted)
function parse_answer($raw) {
    if (strlen($raw) >= 2 && $raw[0] === '"' && $raw[strlen($raw) - 1] === '"') {
        return ["value", substr($raw, 1, -1)];
    }
    if (strlen($raw) >= 2 && $raw[0] === "'" && $raw[strlen($raw) - 1] === "'") {
        return ["value", substr($raw, 1, -1)];
    }
    if (strtolower($raw) === "true") {
        return ["value", true];
    }
    if (strtolower($raw) === "false") {
        return ["value", false];
    }
    return ["name", $raw];
}

function apply_answer(&$row, $resolved) {
    [$kind, $val] = parse_answer($resolved);
    if ($kind === "value") {
        $row["value"] = $val;
        $row["legacy_field"] = "Not mapped";
    } else {
        $row["legacy_field"] = $val;
    }
}

// Lists the fields each instance of an array would have, for "?" at the
// "how many instances" prompt -- so you can see what you're about to map
// before committing to a count. An array of objects lists its top-level
// properties (same metadata/_version/readonly exclusions used everywhere
// else); an array of scalars just names the single value's type, since
// there's nothing else to list.
function describe_array_item_fields($items) {
    $props = $items["properties"] ?? null;
    if (!$props) {
        $itemType = $items["type"] ?? "unknown";
        return "Each instance of this array is a single $itemType value (no sub-fields).";
    }
    ksort($props);
    $lines = ["Fields in each instance:"];
    foreach ($props as $key => $sub) {
        if ($key === "metadata" || $key === "_version" || is_readonly($sub)) {
            continue;
        }
        $subType = $sub["type"] ?? (!empty($sub["properties"]) ? "object" : "unknown");
        if (is_array($subType)) {
            $subType = implode(",", $subType);
        }
        $lines[] = "  $key ($subType)";
    }
    return implode("\n", $lines);
}

function ask_count($fullPath, $items) {
    global $dataColumns, $dataDisplayNames, $dataUsageCounts;
    while (true) {
        echo "How many instances of '$fullPath' should be included in the map? [2] (?=show fields): ";
        $response = trim(fgets(STDIN));
        if ($response === "") {
            return 2;
        }
        if ($response === "?") {
            echo describe_array_item_fields($items) . "\n";
            if (!empty($dataColumns)) {
                // Also show what's actually in the data file -- knowing the
                // schema's field names isn't enough to decide whether this
                // array is worth mapping at all (e.g. no point in sizing
                // electronicAccess if there's no URL-like column to map its
                // 'uri' field from).
                echo "\n";
                echo format_field_grid($dataColumns, $dataDisplayNames, $dataUsageCounts) . "\n";
            }
            continue;
        }
        if (ctype_digit($response)) {
            return (int)$response;
        }
        fwrite(STDERR, "Error: '$response' isn't a whole number. Enter a number, or press enter for 2.\n");
    }
}

// Since arrays are now sized just-in-time, the eventual total row count
// isn't known in advance -- so instead of a "(N left)" countdown, this just
// counts up: how many legacy-field prompts have been shown so far.
$promptedCount = 0;

function next_prompt_number() {
    global $promptedCount;
    $promptedCount++;
    return $promptedCount;
}

// Backs the "#N" correction command: every leaf prompt registers itself
// here (rowIdx, isRequired) under its own number the moment it's first
// asked, so a later prompt can jump back and re-run that exact question.
// Stores the index into $rows rather than the row itself, since a plain
// PHP array value wouldn't alias back into $rows the way a reference does.
$prompt_registry = [];

function describe_current_answer($row) {
    if ($row["legacy_field"] !== "Not mapped") {
        return "legacy field '" . $row["legacy_field"] . "'";
    }
    if ($row["value"] !== "" && $row["value"] !== null) {
        return "literal " . json_encode($row["value"]);
    }
    return "Not mapped";
}

// Shown once, the first time any leaf prompt appears -- not on every one,
// since that would be noisy across a schema with 100+ fields.
$correction_hint_shown = false;

function record_usage($folioField, $resolved) {
    global $dataColumns, $dataUsageCounts, $dataUsageFields;
    $idx = array_search($resolved, $dataColumns);
    if ($idx !== false) {
        $dataUsageCounts[$idx]++;
        $dataUsageFields[$idx][] = $folioField;
    }
}

// Reverses record_usage() -- needed when a "#N" correction overwrites a
// row that had previously consumed a data column, so that column's usage
// count/log entry doesn't double-count the old answer alongside the new
// one. A no-op for a fresh row (whose previous value is always "Not
// mapped", never a data column) or a correction that didn't involve a
// column either time.
function unrecord_usage($folioField, $previousValue) {
    global $dataColumns, $dataUsageCounts, $dataUsageFields;
    $idx = array_search($previousValue, $dataColumns);
    if ($idx !== false) {
        $pos = array_search($folioField, $dataUsageFields[$idx]);
        if ($pos !== false) {
            unset($dataUsageFields[$idx][$pos]);
            $dataUsageFields[$idx] = array_values($dataUsageFields[$idx]);
            $dataUsageCounts[$idx]--;
        }
    }
}

// The actual input loop for one leaf prompt, shared by a fresh prompt and
// a "#N" correction re-ask of an already-answered one. On a fresh prompt,
// blank means "Not mapped" (unchanged default). On a correction, blank
// means "leave it as it is" instead -- jumping back to double-check an
// answer shouldn't risk silently wiping it if you just hit enter.
//
// "#N" is checked first, ahead of the normal answer handling, so it works
// the same whether you're answering for the first time or mid-correction
// (nested "#N" within a correction just recurses one level deeper, which
// is fine for interactive use). When a data file is loaded, a bare number
// is always resolved as a picker index, not as literal text -- an
// out-of-range number (e.g. "0", or past the end of the list) is a
// mistake, not a hardcoded field name, so it re-prompts with a note
// rather than silently becoming legacy_field "0".
function ask_leaf($number, &$row, $isRequired, $isCorrection = false) {
    global $correction_hint_shown, $prompt_registry, $rows, $gridShown, $dataColumns, $dataDisplayNames, $dataUsageCounts;
    $folioField = $row["folio_field"];
    $note = required_note($folioField, $isRequired);
    if (!empty($dataColumns) && !$gridShown) {
        echo format_field_grid($dataColumns, $dataDisplayNames, $dataUsageCounts) . "\n";
        echo "(* = already used; fields can be reused. Enter a number, \"text\"/'text', true/false, a field name, or ? to relist.)\n";
        $gridShown = true;
    }
    if (!$correction_hint_shown) {
        echo "(Tip: type #N at any prompt to jump back and correct prompt number N's answer.)\n";
        $correction_hint_shown = true;
    }
    $hint = !empty($dataColumns) ? " (number/\"text\"/true/false/?/#N): " : " (#N to correct an earlier answer): ";

    while (true) {
        $defaultDesc = $isCorrection
            ? "[current: " . describe_current_answer($row) . ", blank keeps it]"
            : "[Not mapped]";
        echo "Enter legacy field for '$folioField' (#$number)$note $defaultDesc$hint";
        $answer = trim(fgets(STDIN));

        if (!empty($dataColumns) && in_array(strtolower($answer), ["?", "list"])) {
            echo format_field_grid($dataColumns, $dataDisplayNames, $dataUsageCounts) . "\n";
            continue;
        }

        if (strlen($answer) > 1 && $answer[0] === "#" && ctype_digit(substr($answer, 1))) {
            $targetN = (int)substr($answer, 1);
            $entry = $prompt_registry[$targetN] ?? null;
            if ($entry === null) {
                fwrite(STDERR, "Error: there's no prompt #$targetN yet.\n");
            } else {
                [$targetIdx, $targetRequired] = $entry;
                echo "--- Correcting #$targetN ('" . $rows[$targetIdx]["folio_field"] . "'); current answer: " . describe_current_answer($rows[$targetIdx]) . " ---\n";
                ask_leaf($targetN, $rows[$targetIdx], $targetRequired, true);
                echo "--- Back to #$number ---\n";
            }
            continue;
        }

        if (!empty($dataColumns) && ctype_digit($answer)) {
            $idx = (int)$answer;
            if ($idx < 1 || $idx > count($dataColumns)) {
                fwrite(
                    STDERR,
                    "Note: $idx is out of range (1-" . count($dataColumns) . "); enter a number from the list, " .
                    "a field name, a quoted literal, true/false, or press enter to keep/clear it.\n"
                );
                continue;
            }
            $answer = $dataColumns[$idx - 1];
        }

        if ($answer === "") {
            if (!$isCorrection) {
                unrecord_usage($folioField, $row["legacy_field"]);
                apply_answer($row, "Not mapped");
            }
            return;
        }

        unrecord_usage($folioField, $row["legacy_field"]);
        apply_answer($row, $answer);
        if (!is_unmapped_row($row)) {
            record_usage($folioField, $answer);
        }
        return;
    }
}

// Prompts for a single leaf row. Every row -- including every instance of
// an array field -- is prompted independently with a plain "Not mapped"
// default. Nothing is ever guessed or suggested from a prior instance's
// answer: whatever the user types (or the "Not mapped" default) is used
// exactly as given, with no suffix appended.
//
// Takes $rowIdx (every call site already has it from build_row_index's
// return value) rather than the row itself, so the index -- not just a
// disconnected copy -- is what gets stored in $prompt_registry for later
// "#N" corrections to reach back into $rows by reference.
function prompt_leaf($rowIdx, $isRequired) {
    global $prompt_registry, $rows;
    $number = next_prompt_number();
    $prompt_registry[$number] = [$rowIdx, $isRequired];
    ask_leaf($number, $rows[$rowIdx], $isRequired);
}

// Arrays are handled just-in-time: the "how many instances" question for a
// given array is asked right when that array is reached, immediately before
// its own field prompts -- not as a separate batch of questions up front.
// Entering 0 still creates one placeholder instance (so the schema structure
// is documented in the map), but every field in it is set to "Not mapped"
// automatically, with no prompting at all.
function handle_array($sub, $fullPath, $isRequired, $forceSilent) {
    global $object_keys, $rows;
    $items = $sub["items"] ?? [];
    $count = $forceSilent ? 0 : ask_count($fullPath, $items);
    $effectiveCount = $count > 0 ? $count : 1;
    $childSilent = $forceSilent || $count === 0;

    if (!empty($items["properties"])) {
        for ($idx = 0; $idx < $effectiveCount; $idx++) {
            build_and_prompt_instance($items, "{$fullPath}[{$idx}]", $childSilent);
        }
    } else {
        $leafSchema = $items ? $items : $sub;
        if (($leafSchema["type"] ?? null) === "object") {
            // object with no defined structure -> excluded from output
            $object_keys[] = $fullPath;
            return;
        }
        for ($idx = 0; $idx < $effectiveCount; $idx++) {
            $indexedPath = "{$fullPath}[{$idx}]";
            $rowIdx = build_row_index($indexedPath, $leafSchema, $isRequired);
            if ($childSilent) {
                auto_unmap($rows[$rowIdx]);
            } else {
                prompt_leaf($rowIdx, $isRequired);
            }
        }
    }
}

// One specific array-of-objects instance (e.g. additionalCallNumbers[0]).
// Its own direct leaf fields are batched: any "REQUIRED if used" field among
// them is prompted first; if none end up mapped, the rest of the instance's
// fields (including further nested arrays/objects) are auto-set to
// "Not mapped" with no further prompting -- an unused required field means
// the whole instance is unused. Within an instance, all non-array fields
// (leaves and nested plain objects) are prompted first, in alphabetical
// order; array fields always come last, each announced with its own
// "Switching to array fields" banner and a visible per-field break.
function build_and_prompt_instance($node, $path, $silent) {
    global $object_keys, $rows;
    $nodeRequired = $node["required"] ?? [];
    $props = $node["properties"] ?? [];
    ksort($props);

    $leafItems = [];
    $arrayItems = [];
    $nestedObjItems = [];
    foreach ($props as $key => $sub) {
        if ($key === "metadata" || $key === "_version" || is_readonly($sub)) {
            continue;
        }
        $subType = $sub["type"] ?? null;
        if ($subType === "array") {
            $arrayItems[] = [$key, $sub];
        } elseif (!empty($sub["properties"])) {
            $nestedObjItems[] = [$key, $sub];
        } elseif ($subType === "object") {
            $object_keys[] = "$path.$key";
        } else {
            $leafItems[] = [$key, $sub];
        }
    }

    if ($silent) {
        foreach ($leafItems as [$key, $sub]) {
            $rowIdx = build_row_index("$path.$key", $sub, in_array($key, $nodeRequired));
            auto_unmap($rows[$rowIdx]);
        }
        foreach ($arrayItems as [$key, $sub]) {
            handle_array($sub, "$path.$key", in_array($key, $nodeRequired), true);
        }
        foreach ($nestedObjItems as [$key, $sub]) {
            build_and_prompt_object($sub, "$path.$key", true, false);
        }
        return;
    }

    $requiredLeaves = array_values(array_filter($leafItems, function ($p) use ($nodeRequired) { return in_array($p[0], $nodeRequired); }));
    $otherLeaves = array_values(array_filter($leafItems, function ($p) use ($nodeRequired) { return !in_array($p[0], $nodeRequired); }));

    $anyRequiredMapped = false;
    foreach ($requiredLeaves as [$key, $sub]) {
        $rowIdx = build_row_index("$path.$key", $sub, true);
        prompt_leaf($rowIdx, true);
        if (!is_unmapped_row($rows[$rowIdx])) {
            $anyRequiredMapped = true;
        }
    }

    $childSilent = !empty($requiredLeaves) && !$anyRequiredMapped;

    foreach ($otherLeaves as [$key, $sub]) {
        $rowIdx = build_row_index("$path.$key", $sub, false);
        if ($childSilent) {
            auto_unmap($rows[$rowIdx]);
        } else {
            prompt_leaf($rowIdx, false);
        }
    }

    foreach ($nestedObjItems as [$key, $sub]) {
        build_and_prompt_object($sub, "$path.$key", $childSilent, false);
    }

    if (!empty($arrayItems) && !$childSilent) {
        echo "\n--- Switching to array fields under '$path' ---\n\n";
    }
    foreach ($arrayItems as [$key, $sub]) {
        if (!$childSilent) {
            echo "--- $path.$key ---\n";
        }
        handle_array($sub, "$path.$key", in_array($key, $nodeRequired), $childSilent);
    }
}

// Top-level schema and any plain (non-array) nested object. No batching or
// reordering among leaves here -- each one is handled independently. (The
// "required means the rest gets skipped" behavior is scoped to array
// instances, since unlike an array a plain object's fields generally
// aren't optional as a group.) Fields are split into two alphabetical
// passes: all non-array fields (leaves and nested plain objects) first,
// then all array fields.
//
// $deferArrays controls where that second group goes:
//   - true (the default, used for the top-level walk starting from the
//     schema root): array fields are queued onto the module-level
//     $deferred_arrays list instead of being prompted here, and nested
//     plain objects are recursed into with $deferArrays still true -- so
//     every array anywhere in the schema, at any depth, waits for the
//     whole schema's scalar sweep to finish. They're all handled
//     together, after one banner, once that walk returns (see bottom of
//     file).
//   - false: the original immediate, per-level behavior -- this level's
//     own array fields are handled right here, with their own local
//     "Switching to array fields" banner. Used when
//     build_and_prompt_object is called for a nested plain object
//     *inside* an array instance (build_and_prompt_instance): by that
//     point the global scalar/array split has already happened, so
//     further nesting just behaves as before.
function build_and_prompt_object($node, $path, $silent, $deferArrays = true) {
    global $object_keys, $rows, $deferred_arrays;
    $nodeRequired = $node["required"] ?? [];
    $props = $node["properties"] ?? [];
    ksort($props);

    $scalarItems = [];
    $arrayItems = [];
    foreach ($props as $key => $sub) {
        if ($key === "metadata" || $key === "_version" || is_readonly($sub)) {
            continue;
        }
        $fullPath = $path ? "$path.$key" : $key;
        $subType = $sub["type"] ?? null;
        if ($subType === "array") {
            $arrayItems[] = [$key, $sub, $fullPath];
        } elseif (!empty($sub["properties"])) {
            $scalarItems[] = ["nested", $key, $sub, $fullPath];
        } elseif ($subType === "object") {
            $object_keys[] = $fullPath;
        } else {
            $scalarItems[] = ["leaf", $key, $sub, $fullPath];
        }
    }

    foreach ($scalarItems as [$kind, $key, $sub, $fullPath]) {
        $isRequired = in_array($key, $nodeRequired);
        if ($kind === "nested") {
            build_and_prompt_object($sub, $fullPath, $silent, $deferArrays);
        } else {
            $rowIdx = build_row_index($fullPath, $sub, $isRequired);
            if ($silent) {
                auto_unmap($rows[$rowIdx]);
            } else {
                prompt_leaf($rowIdx, $isRequired);
            }
        }
    }

    if ($deferArrays) {
        foreach ($arrayItems as [$key, $sub, $fullPath]) {
            $deferred_arrays[] = [$fullPath, $sub, in_array($key, $nodeRequired), $silent];
        }
        return;
    }

    if (!empty($arrayItems) && !$silent) {
        $label = $path ? " under '$path'" : "";
        echo "\n--- Switching to array fields$label ---\n\n";
    }
    foreach ($arrayItems as [$key, $sub, $fullPath]) {
        $isRequired = in_array($key, $nodeRequired);
        if (!$silent) {
            echo "--- $fullPath ---\n";
        }
        handle_array($sub, $fullPath, $isRequired, $silent);
    }
}

[$inputPath, $loaded] = prompt_for_schema_resource(
    function ($path) use ($HTTP_HEADERS) { return load_schema($path, $HTTP_HEADERS); }
);
[$schema, $outputDir, $outputStem, $repo] = $loaded;

// Blank stays in the mapping folder itself; a value creates that as a
// subfolder of mapping, rather than replacing it.
$subfolder = prompt_with_default("Enter folder to save the maps to (blank for mapping itself): ", "");

if ($repo) {
    echo "Resolving \$ref pointers against $repo on GitHub...\n\n";
    $resolver = new RefResolver($repo, $HTTP_HEADERS, $KNOWN_SCHEMAS);
    $schema = $resolver->dereference($schema);
}

$schema = apply_user_import_exception($schema, $inputPath);

// Hardcoded field, added to every map. Injected into the schema itself so it
// sorts alphabetically alongside the schema's own fields; its description is
// blanked out after walk() builds the row, since it isn't a real schema field.
if (!isset($schema["properties"])) {
    $schema["properties"] = [];
}
$schema["properties"]["legacyIdentifier"] = ["type" => "string"];
if (!isset($schema["required"])) {
    $schema["required"] = [];
}
if (!in_array("legacyIdentifier", $schema["required"])) {
    $schema["required"][] = "legacyIdentifier";
}

$mappingSuffix = $subfolder !== "" ? "mapping/$subfolder" : "mapping";
$mappingDir = $outputDir === "" ? $mappingSuffix : rtrim($outputDir, "/\\") . "/" . $mappingSuffix;
if (!is_dir($mappingDir)) {
    mkdir($mappingDir, 0777, true);
}
$outputPath = $mappingDir . "/" . $outputStem . "_mapping.json";

// --map-template-only skips all interactive prompting: every field is included with
// legacy_field left as "Not mapped" (reusing the same silent auto-unmap path
// already used for unused/skipped array instances).
build_and_prompt_object($schema, "", $emptyMap);

// Every array field queued during that walk ($deferred_arrays), handled as
// one global batch now that the schema's entire scalar sweep is done --
// this is the single schema-wide "Switching to array fields" moment.
if (!empty($deferred_arrays) && !$emptyMap) {
    echo "\n--- Switching to array fields ---\n\n";
}
foreach ($deferred_arrays as [$fullPath, $sub, $isRequired, $silent]) {
    if (!$silent) {
        echo "--- $fullPath ---\n";
    }
    handle_array($sub, $fullPath, $isRequired, $silent);
}

foreach ($rows as &$row) {
    if ($row["folio_field"] === "legacyIdentifier") {
        $row["description"] = "not part of FOLIO schema. Added to work with folio_migration_tools";
    }
}
unset($row);

// $rowsByField backs both the key-list marks and --compact filtering.
$rowsByField = [];
foreach ($rows as $row) {
    $rowsByField[$row["folio_field"]] = $row;
}

$keyListPath = $mappingDir . "/" . $outputStem . ".txt";
$keyLines = build_key_lines($schema, $rowsByField, $markMapped, $compact, $emptyMap);
file_put_contents($keyListPath, implode("\n", $keyLines) . "\n");

// --compact drops fields with no active mapping from the JSON map too.
if ($compact) {
    $rows = array_values(array_filter($rows, function ($r) {
        return mapped_mark_content($r) !== "";
    }));
}

$output = ["data" => $rows];

file_put_contents($outputPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Wrote " . count($rows) . " mapping rows to $outputPath\n";
echo "\n";
if (!empty($object_keys)) {
    echo "The following keys are objects with no defined structure in the schema (e.g. custom fields, or unresolved \$ref) and were excluded from the output: " . implode(", ", $object_keys) . "\n";
    echo "\n";
}
echo "Wrote key list to $keyListPath\n";

if (!empty($dataColumns)) {
    $logPath = "$mappingDir/{$outputStem}_field_usage.log";
    $used = [];
    $unused = [];
    foreach ($dataColumns as $i => $col) {
        if (!empty($dataUsageFields[$i])) {
            $used[] = [$dataDisplayNames[$i], $dataUsageFields[$i]];
        } else {
            $unused[] = $dataDisplayNames[$i];
        }
    }
    $log = "Data file: $dataFilePath\n\n";
    $log .= "USED COLUMNS:\n";
    if (!empty($used)) {
        foreach ($used as [$name, $fields]) {
            $log .= "  $name -> " . implode(", ", $fields) . "\n";
        }
    } else {
        $log .= "  (none)\n";
    }
    $log .= "\nUNUSED COLUMNS:\n";
    $log .= !empty($unused) ? "  " . implode(", ", $unused) . "\n" : "  (none)\n";
    file_put_contents($logPath, $log);
    echo "\n";
    echo "Wrote field usage log to $logPath\n";
}

// REQUIRED (unconditional) fields left "Not mapped" are worth flagging --
// unlike "REQUIRED if used" fields inside an array instance, which are
// routinely left unmapped whenever that instance goes unused (by design,
// not a gap), an unconditional REQUIRED field being unmapped is always
// worth a second look. This is informational only -- nothing earlier in
// the script stops you from leaving a required field unmapped.
$unmappedRequired = array_values(array_filter($required_fields, function ($f) use ($rowsByField) {
    return strpos($f, "[") === false && is_unmapped_row($rowsByField[$f]);
}));
if (!empty($unmappedRequired)) {
    echo "\n";
    echo "Warning: these REQUIRED fields were left \"Not mapped\": " . implode(", ", $unmappedRequired) . "\n";
}
