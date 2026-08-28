<?php

require_once __DIR__ . "/lib/folio_schema_lib.php";

const HELP_TEXT = <<<'EOT'
Usage: create_map.php [options]

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

EOT;

if (in_array("--help", array_slice($argv, 1)) || in_array("-h", array_slice($argv, 1))) {
    echo HELP_TEXT;
    exit(0);
}

$markMapped = !in_array("--no-text-annotation", array_slice($argv, 1));
$emptyMap = in_array("--map-template-only", array_slice($argv, 1));
$compact = in_array("--compact", array_slice($argv, 1));

// --map-template-only is its own mode: nothing is ever mapped, so there's
// nothing for --compact to remove or --no-text-annotation to suppress. Both
// are ignored (rather than silently accepted as no-op combinations) so the
// switches don't multiply into combinations that produce identical output.
if ($emptyMap && ($compact || !$markMapped)) {
    fwrite(STDERR, "Note: --map-template-only ignores --compact and --no-text-annotation.\n");
    $compact = false;
    $markMapped = true;
}

$rows = [];
$object_keys = [];
$required_fields = [];
$arrayBaseLegacy = [];

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
    // A field inside an array instance (e.g. additionalCallNumbers[1].callNumber)
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

function ask_count($fullPath) {
    echo "How many instances of '$fullPath' should be included in the map? [2]: ";
    $response = trim(fgets(STDIN));
    return $response !== "" ? (int)$response : 2;
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

// Prompts for a single leaf row. Rows that belong to an array (their
// folio_field contains one or more "[N]" markers) share a single base
// legacy name across all instances of that array: the first instance is
// prompted normally (default "Not mapped") and, if a real name is given,
// "_1" is appended to it. Every later instance re-uses that base name (with
// its own "_N" suffix) as the suggested default, but is still prompted so
// it can be overridden.
function prompt_leaf(&$row, $isRequired) {
    global $arrayBaseLegacy;
    $folioField = $row["folio_field"];
    $note = required_note($folioField, $isRequired);
    preg_match_all('/\[(\d+)\]/', $folioField, $indexMatches);
    $indices = $indexMatches[1];

    if (empty($indices)) {
        $progress = " (#" . next_prompt_number() . ")";
        echo "Enter legacy field for '$folioField'$progress$note [Not mapped]: ";
        $answer = trim(fgets(STDIN));
        apply_answer($row, $answer !== "" ? $answer : "Not mapped");
        return;
    }

    $template = preg_replace('/\[\d+\]/', '', $folioField);
    $suffix = "_" . implode("_", $indices);

    if (!array_key_exists($template, $arrayBaseLegacy)) {
        $progress = " (#" . next_prompt_number() . ")";
        echo "Enter legacy field for '$folioField'$progress$note [Not mapped]: ";
        $answer = trim(fgets(STDIN));
        $base = $answer !== "" ? $answer : "Not mapped";
        $arrayBaseLegacy[$template] = $base;
        if ($base === "Not mapped") {
            $row["legacy_field"] = "Not mapped";
        } elseif (parse_answer($base)[0] === "value") {
            apply_answer($row, $base);
        } else {
            $row["legacy_field"] = $base . $suffix;
        }
    } else {
        $base = $arrayBaseLegacy[$template];
        if ($base === "Not mapped") {
            $row["legacy_field"] = "Not mapped";
        } else {
            $suggestion = parse_answer($base)[0] === "value" ? $base : $base . $suffix;
            $progress = " (#" . next_prompt_number() . ")";
            echo "Enter legacy field for '$folioField'$progress$note [$suggestion]: ";
            $answer = trim(fgets(STDIN));
            apply_answer($row, $answer !== "" ? $answer : $suggestion);
        }
    }
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
    $count = $forceSilent ? 0 : ask_count($fullPath);
    $effectiveCount = $count > 0 ? $count : 1;
    $childSilent = $forceSilent || $count === 0;

    if (!empty($items["properties"])) {
        for ($idx = 1; $idx <= $effectiveCount; $idx++) {
            build_and_prompt_instance($items, "{$fullPath}[{$idx}]", $childSilent);
        }
    } else {
        $leafSchema = $items ? $items : $sub;
        if (($leafSchema["type"] ?? null) === "object") {
            // object with no defined structure -> excluded from output
            $object_keys[] = $fullPath;
            return;
        }
        for ($idx = 1; $idx <= $effectiveCount; $idx++) {
            $indexedPath = "{$fullPath}[{$idx}]";
            $rowIdx = build_row_index($indexedPath, $leafSchema, $isRequired);
            if ($childSilent) {
                auto_unmap($rows[$rowIdx]);
            } else {
                prompt_leaf($rows[$rowIdx], $isRequired);
            }
        }
    }
}

// One specific array-of-objects instance (e.g. additionalCallNumbers[1]).
// Its own direct leaf fields are batched: any "REQUIRED if used" field among
// them is prompted first; if none end up mapped, the rest of the instance's
// fields (including further nested arrays/objects) are auto-set to
// "Not mapped" with no further prompting -- an unused required field means
// the whole instance is unused. Nested arrays/objects otherwise recurse and
// ask their own questions normally.
function build_and_prompt_instance($node, $path, $silent) {
    global $object_keys, $rows;
    $nodeRequired = $node["required"] ?? [];
    $props = $node["properties"] ?? [];
    ksort($props);

    $leafItems = [];
    $arrayItems = [];
    $nestedObjItems = [];
    foreach ($props as $key => $sub) {
        if ($key === "metadata" || $key === "_version" || !empty($sub["readonly"]) || !empty($sub["readOnly"])) {
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
            build_and_prompt_object($sub, "$path.$key", true);
        }
        return;
    }

    $requiredLeaves = array_values(array_filter($leafItems, function ($p) use ($nodeRequired) { return in_array($p[0], $nodeRequired); }));
    $otherLeaves = array_values(array_filter($leafItems, function ($p) use ($nodeRequired) { return !in_array($p[0], $nodeRequired); }));

    $anyRequiredMapped = false;
    foreach ($requiredLeaves as [$key, $sub]) {
        $rowIdx = build_row_index("$path.$key", $sub, true);
        prompt_leaf($rows[$rowIdx], true);
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
            prompt_leaf($rows[$rowIdx], false);
        }
    }

    foreach ($arrayItems as [$key, $sub]) {
        handle_array($sub, "$path.$key", in_array($key, $nodeRequired), $childSilent);
    }
    foreach ($nestedObjItems as [$key, $sub]) {
        build_and_prompt_object($sub, "$path.$key", $childSilent);
    }
}

// Top-level schema and any plain (non-array) nested object. No batching or
// reordering among siblings here -- each leaf is handled independently, in
// schema order. (The "required means the rest gets skipped" behavior is
// scoped to array instances, since unlike an array a plain object's fields
// generally aren't optional as a group.)
function build_and_prompt_object($node, $path, $silent) {
    global $object_keys, $rows;
    $nodeRequired = $node["required"] ?? [];
    $props = $node["properties"] ?? [];
    ksort($props);
    foreach ($props as $key => $sub) {
        if ($key === "metadata" || $key === "_version" || !empty($sub["readonly"]) || !empty($sub["readOnly"])) {
            continue;
        }
        $fullPath = $path ? "$path.$key" : $key;
        $isRequired = in_array($key, $nodeRequired);
        $subType = $sub["type"] ?? null;
        if ($subType === "array") {
            handle_array($sub, $fullPath, $isRequired, $silent);
        } elseif (!empty($sub["properties"])) {
            build_and_prompt_object($sub, $fullPath, $silent);
        } elseif ($subType === "object") {
            $object_keys[] = $fullPath;
        } else {
            $rowIdx = build_row_index($fullPath, $sub, $isRequired);
            if ($silent) {
                auto_unmap($rows[$rowIdx]);
            } else {
                prompt_leaf($rows[$rowIdx], $isRequired);
            }
        }
    }
}

[$inputPath, $loaded] = prompt_for_resource(
    "Enter path or URL to the schema file: ",
    function ($path) use ($HTTP_HEADERS) { return load_schema($path, $HTTP_HEADERS); }
);
[$schema, $outputDir, $outputStem, $repo] = $loaded;

$outputFolderName = prompt_with_default("Enter folder to save the maps to [mapping]: ", "mapping");

if ($repo) {
    echo "Resolving \$ref pointers against $repo on GitHub...\n";
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

$mappingDir = $outputDir === "" ? $outputFolderName : rtrim($outputDir, "/\\") . "/" . $outputFolderName;
if (!is_dir($mappingDir)) {
    mkdir($mappingDir, 0777, true);
}
$outputPath = $mappingDir . "/" . $outputStem . "_mapping.json";

// --map-template-only skips all interactive prompting: every field is included with
// legacy_field left as "Not mapped" (reusing the same silent auto-unmap path
// already used for unused/skipped array instances).
build_and_prompt_object($schema, "", $emptyMap);

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
