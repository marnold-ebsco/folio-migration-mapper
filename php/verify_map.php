<?php

require_once __DIR__ . "/lib/folio_schema_lib.php";

const HELP_TEXT = <<<'EOT'
Usage: verify_map.php [options]

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

EOT;

if (in_array("--help", array_slice($argv, 1)) || in_array("-h", array_slice($argv, 1))) {
    echo HELP_TEXT;
    exit(0);
}

$rows = [];
$arrayCounts = [];
$requiredFields = [];

function build_description($subschema, $isRequired) {
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
        $full = "REQUIRED $full";
    }

    return $full;
}

function add_leaf($path, $subschema, $isRequired = false) {
    global $rows, $requiredFields;
    if ($isRequired) {
        $requiredFields[] = $path;
    }
    $rows[] = [
        "folio_field" => $path,
        "legacy_field" => "Not mapped",
        "value" => "",
        "description" => build_description($subschema, $isRequired)
    ];
}

function walk($node, $path) {
    global $arrayCounts;
    $props = $node["properties"] ?? null;
    if (!$props) {
        return;
    }
    $nodeRequired = $node["required"] ?? [];
    ksort($props);
    foreach ($props as $key => $sub) {
        if ($key === "metadata" || $key === "_version") {
            continue;
        }
        if (!empty($sub["readonly"]) || !empty($sub["readOnly"])) {
            continue;
        }
        $fullPath = $path ? "$path.$key" : $key;
        $isRequired = in_array($key, $nodeRequired);
        $subType = $sub["type"] ?? null;
        if ($subType === "array") {
            $items = $sub["items"] ?? [];
            $templateKey = preg_replace('/\[\d+\]/', '', $fullPath);
            $count = $arrayCounts[$templateKey] ?? 2;
            if (!empty($items["properties"])) {
                for ($idx = 1; $idx <= $count; $idx++) {
                    walk($items, "{$fullPath}[{$idx}]");
                }
            } else {
                $leafSchema = $items ? $items : $sub;
                if (($leafSchema["type"] ?? null) === "object") {
                    continue; // unstructured object array -> excluded from output
                }
                for ($idx = 1; $idx <= $count; $idx++) {
                    add_leaf("{$fullPath}[{$idx}]", $leafSchema, $isRequired);
                }
            }
        } elseif (!empty($sub["properties"])) {
            walk($sub, $fullPath);
        } elseif ($subType === "object") {
            continue; // unstructured object -> excluded from output
        } else {
            add_leaf($fullPath, $sub, $isRequired);
        }
    }
}

function discover_arrays($node, $path, &$seen) {
    $props = $node["properties"] ?? [];
    ksort($props);
    foreach ($props as $key => $sub) {
        if ($key === "metadata" || $key === "_version" || !empty($sub["readonly"]) || !empty($sub["readOnly"])) {
            continue;
        }
        $fullPath = $path ? "$path.$key" : $key;
        $items = $sub["items"] ?? [];
        if (($sub["type"] ?? null) === "array") {
            if (($items["type"] ?? null) === "object" && empty($items["properties"])) {
                continue;
            }
            if (!in_array($fullPath, $seen)) {
                $seen[] = $fullPath;
            }
            if (!empty($items["properties"])) {
                discover_arrays($items, $fullPath, $seen);
            }
        } elseif (!empty($sub["properties"])) {
            discover_arrays($sub, $fullPath, $seen);
        }
    }
}

// This tool only ever asks for the schema, the map file to verify, and
// where to write the master map/report. Everything else (array instance
// counts for the master map, etc.) is derived automatically, with no
// further prompts.

[$inputPath, $loaded] = prompt_for_resource(
    "Enter path or URL to the schema file: ",
    function ($path) use ($HTTP_HEADERS) { return load_schema($path, $HTTP_HEADERS); }
);
[$schema, $outputDir, $outputStem, $repo] = $loaded;

$outputFolderName = prompt_with_default("Enter folder to save the maps to [mapping]: ", "mapping");

// Read the map file to verify (via raw text, not just the parsed structure)
// so its actual array sizes can inform how many instances the master map
// needs, without ever having to ask.
[$providedPath, $rawContents] = prompt_for_resource(
    "Enter path to the existing map file to verify: ",
    function ($path) {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            fwrite(STDERR, "Error: could not find or read map file '$path'\n");
            return null;
        }
        return $contents;
    }
);
$lines = explode("\n", $rawContents);

// Line number of each "data" array element's opening brace, in order. Every
// row object in our pretty-printed format starts on its own line containing
// only "{" -- the first such line is the top-level object itself, so the
// remaining ones map 1:1, in order, to data[0], data[1], data[2], ...
$objectOpenLines = [];
foreach ($lines as $i => $line) {
    if (preg_match('/^\s*\{\s*$/', $line)) {
        $objectOpenLines[] = $i + 1;
    }
}
array_shift($objectOpenLines); // drop the top-level object's own brace

$parsed = json_decode($rawContents, true);
$jsonError = json_last_error();
$jsonErrorMsg = json_last_error_msg();
$isValidJson = ($jsonError === JSON_ERROR_NONE) && isset($parsed["data"]) && is_array($parsed["data"]);

// Collect provided folio_field values, with line numbers, from the raw text.
// This is done via regex (not the parsed structure) so it still works even
// if the file is not valid JSON.
$providedFieldLines = []; // folio_field => [line numbers]
foreach ($lines as $i => $line) {
    if (preg_match('/"folio_field"\s*:\s*"([^"]*)"/', $line, $m)) {
        $providedFieldLines[$m[1]][] = $i + 1;
    }
}
$providedFields = array_keys($providedFieldLines);
$providedFieldSet = array_flip($providedFields);

// ---------------------------------------------------------------------------
// Step 2: build the "master" map straight from the schema (same logic as
// gen_map.php / create_map.php), including a hardcoded legacyIdentifier
// field, so it matches what create_map.php would actually produce.
//
// Array instance counts are derived from the provided file itself (the
// highest index actually used for each array, with a floor of 2) rather
// than prompted for.
// ---------------------------------------------------------------------------

if ($repo) {
    echo "Resolving \$ref pointers against $repo on GitHub...\n";
    $resolver = new RefResolver($repo, $HTTP_HEADERS, $KNOWN_SCHEMAS);
    $schema = $resolver->dereference($schema);
}

$schema = apply_user_import_exception($schema, $inputPath);

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

function max_index_in_provided($template, $providedFields) {
    $segments = explode(".", $template);
    $regexParts = [];
    foreach ($segments as $i => $seg) {
        $q = preg_quote($seg, '#');
        $regexParts[] = $i === count($segments) - 1 ? "$q\[(\d+)\]" : "$q\[\d+\]";
    }
    $regex = '#^' . implode('\.', $regexParts) . '#';
    $max = 0;
    foreach ($providedFields as $field) {
        if (preg_match($regex, $field, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $max;
}

$arrayFieldNames = [];
discover_arrays($schema, "", $arrayFieldNames);
foreach ($arrayFieldNames as $name) {
    $arrayCounts[$name] = max(2, max_index_in_provided($name, $providedFields));
}

walk($schema, "");

// Write the master map and key list out to the "mapping" folder, same as
// gen_map.php / create_map.php, so this run's reference map is available
// for inspection or reuse afterward. Named "..._master..." (rather than
// gen_map's usual "..._mapping.json") so it can never collide with, and
// overwrite, the provided map file being verified.
$mappingDir = $outputDir === "" ? $outputFolderName : rtrim($outputDir, "/\\") . "/" . $outputFolderName;
if (!is_dir($mappingDir)) {
    mkdir($mappingDir, 0777, true);
}
// The key list (and, with --compact, the master map itself) reflect what's
// actively mapped in the *provided* file being verified, not the
// always-unmapped master rows -- $providedRowsByField is null only if the
// provided file didn't parse, in which case marks/compact are both skipped.
$markMapped = !in_array("--no-text-annotation", array_slice($argv, 1));
$compact = in_array("--compact", array_slice($argv, 1));
$providedRowsByField = null;
if ($isValidJson) {
    $providedRowsByField = [];
    foreach ($parsed["data"] as $obj) {
        if (is_array($obj) && isset($obj["folio_field"])) {
            $providedRowsByField[$obj["folio_field"]] = $obj;
        }
    }
}

$keyListPath = $mappingDir . "/" . $outputStem . "_master.txt";
$keyLines = build_key_lines($schema, $providedRowsByField, $markMapped, $compact);
file_put_contents($keyListPath, implode("\n", $keyLines) . "\n");

// The rest of this script's checks need the *full* master reference, so
// --compact only trims what gets written to the master map file, never the
// $rows array used for comparisons below.
$outputRows = $rows;
if ($compact && $providedRowsByField !== null) {
    $outputRows = array_values(array_filter($rows, function ($r) use ($providedRowsByField) {
        return mapped_mark_content($providedRowsByField[$r["folio_field"]] ?? null) !== "";
    }));
}

$masterMapPath = $mappingDir . "/" . $outputStem . "_master_mapping.json";
file_put_contents($masterMapPath, json_encode(["data" => $outputRows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Wrote master map to $masterMapPath\n";
echo "Wrote key list to $keyListPath\n";

$masterFields = array_map(function ($r) { return $r["folio_field"]; }, $rows);
$masterFieldSet = array_flip($masterFields);

// Template (bracket indices stripped) set, used for the "spelled exactly
// like the schema" check -- this way a provided field with a higher array
// index than the master happened to generate isn't flagged as a typo.
$masterTemplateSet = [];
foreach ($masterFields as $f) {
    $masterTemplateSet[preg_replace('/\[\d+\]/', '', $f)] = true;
}

echo "\n========================================\n";
echo "VERIFICATION RESULTS: $providedPath\n";
echo "========================================\n";

// ---------------------------------------------------------------------------
// Check 1: master fields missing from the provided file (exact match).
// ---------------------------------------------------------------------------

// Required fields are reported under check 8 (missing or unmapped) instead,
// whether they're absent entirely or just present-but-blank.
$missingFromProvided = array_values(array_diff($masterFields, $providedFields, $requiredFields));
echo "\n1. Master fields missing from provided file:\n";
if (empty($missingFromProvided)) {
    echo "   None.\n";
} else {
    echo "   " . implode(", ", $missingFromProvided) . "\n";
}

// ---------------------------------------------------------------------------
// Check 2: malformed JSON.
// ---------------------------------------------------------------------------

echo "\n2. JSON validity:\n";
if (!$isValidJson) {
    if ($jsonError !== JSON_ERROR_NONE) {
        echo "   MALFORMED JSON: " . $jsonErrorMsg . "\n";
    } else {
        echo "   MALFORMED: file is valid JSON but has no top-level \"data\" array.\n";
    }
    echo "   (Remaining checks that need parsed data will be skipped or limited to text-based scanning.)\n";
} else {
    echo "   OK.\n";
}

// ---------------------------------------------------------------------------
// Check 3: extra fields on each object (informational only, not modified).
// ---------------------------------------------------------------------------

echo "\n3. Extra (non-standard) fields on objects:\n";
$standardKeys = [
    "folio_field", "legacy_field", "value", "description",
    "fallback_legacy_field", "fallback_value", "rules", "rules_apply_scope",
];
$extraFieldNotes = [];
if ($isValidJson) {
    foreach ($parsed["data"] as $i => $obj) {
        if (!is_array($obj)) {
            continue;
        }
        $extra = array_diff(array_keys($obj), $standardKeys);
        if (!empty($extra)) {
            $label = $obj["folio_field"] ?? "(no folio_field)";
            $startLine = $objectOpenLines[$i] ?? null;
            $endLine = $objectOpenLines[$i + 1] ?? (count($lines) + 1);
            $extraWithLines = [];
            foreach ($extra as $key) {
                $keyLine = null;
                if ($startLine !== null) {
                    for ($ln = $startLine; $ln < $endLine; $ln++) {
                        if (preg_match('/^\s*"' . preg_quote($key, '/') . '"\s*:/', $lines[$ln - 1] ?? "")) {
                            $keyLine = $ln;
                            break;
                        }
                    }
                }
                $extraWithLines[] = $keyLine !== null ? "$key (line $keyLine)" : $key;
            }
            $extraFieldNotes[] = "$label: " . implode(", ", $extraWithLines);
        }
    }
}
if (empty($extraFieldNotes)) {
    echo $isValidJson ? "   None.\n" : "   Skipped (JSON did not parse).\n";
} else {
    foreach ($extraFieldNotes as $note) {
        echo "   $note\n";
    }
}

// ---------------------------------------------------------------------------
// Check 4: duplicate folio_field values, with line numbers.
// ---------------------------------------------------------------------------

echo "\n4. Duplicate folio_field values:\n";
$duplicatesFound = false;
foreach ($providedFieldLines as $field => $fieldLines) {
    if (count($fieldLines) > 1) {
        $duplicatesFound = true;
        echo "   '$field' appears on lines " . implode(", ", $fieldLines) . "\n";
    }
}
if (!$duplicatesFound) {
    echo "   None.\n";
}

// ---------------------------------------------------------------------------
// Check 5: missing array elements (gaps).
//
// For every field with at least one "[N]" index, group by "family" (the
// text up to and including the opening "[" of the LAST index) and by
// "subfield" (family + wildcard index + trailing suffix). The expected
// index set for a family is the union of indices seen across every sibling
// subfield sharing that family; any subfield missing one of those indices
// gets its reconstructed field name reported as missing.
// ---------------------------------------------------------------------------

echo "\n5. Missing array elements (gaps):\n";
$families = []; // familyKey => ["indices" => [...], "subfields" => [subfieldKey => ["indices" => [...], "suffix" => ...]]]
foreach ($providedFields as $field) {
    if (!preg_match_all('/\[(\d+)\]/', $field, $allMatches, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    $lastMatch = end($allMatches[1]); // [digits, offset]
    $lastFull = end($allMatches[0]);  // ["[N]", offset]
    $digitStr = $lastMatch[0];
    $bracketStart = $lastFull[1];
    $bracketEnd = $bracketStart + strlen($lastFull[0]);

    $familyKey = substr($field, 0, $bracketStart) . "[";
    $suffix = substr($field, $bracketEnd);
    $subfieldKey = $familyKey . "N]" . $suffix;

    $families[$familyKey]["indices"][(int)$digitStr] = true;
    $families[$familyKey]["subfields"][$subfieldKey]["indices"][(int)$digitStr] = true;
    $families[$familyKey]["subfields"][$subfieldKey]["suffix"] = $suffix;
}

$gapsFound = false;
foreach ($families as $familyKey => $family) {
    $expected = array_keys($family["indices"]);
    sort($expected);
    foreach ($family["subfields"] as $subfieldKey => $sub) {
        $have = array_keys($sub["indices"]);
        $missing = array_diff($expected, $have);
        foreach ($missing as $missingIdx) {
            $gapsFound = true;
            echo "   " . $familyKey . $missingIdx . "]" . $sub["suffix"] . " is missing\n";
        }
    }
}
if (!$gapsFound) {
    echo "   None.\n";
}

// ---------------------------------------------------------------------------
// Check 6: quoted boolean values.
// ---------------------------------------------------------------------------

echo "\n6. Quoted boolean values:\n";
$quotedBoolFound = false;
foreach ($lines as $i => $line) {
    if (preg_match('/"value"\s*:\s*"(true|false)"/i', $line, $m)) {
        $quotedBoolFound = true;
        echo "   Line " . ($i + 1) . ": \"value\": \"" . $m[1] . "\" should be unquoted\n";
    }
}
if (!$quotedBoolFound) {
    echo "   None.\n";
}

// ---------------------------------------------------------------------------
// Check 7: folio_field values not spelled exactly like the schema.
// Compared at the template level (indices stripped) so a valid field with
// an array index beyond what the master happened to generate isn't
// incorrectly flagged.
// ---------------------------------------------------------------------------

echo "\n7. folio_field values not matching the schema:\n";
$misspelled = [];
foreach ($providedFields as $field) {
    $template = preg_replace('/\[\d+\]/', '', $field);
    if (!isset($masterTemplateSet[$template])) {
        $misspelled[] = $field;
    }
}
if (empty($misspelled)) {
    echo "   None.\n";
} else {
    foreach ($misspelled as $field) {
        $fieldLines = implode(", ", $providedFieldLines[$field] ?? []);
        echo "   '$field' (line $fieldLines)\n";
    }
}

// Build a folio_field => row lookup from the parsed data, for the
// remaining checks that need to inspect a specific row's contents. If a
// folio_field repeats (duplicate, already reported above) the last one
// wins; that's fine since duplicates are already flagged separately.
$providedRowByField = [];
$rowsMissingFolioField = [];
$rowsMissingLegacyField = [];
if ($isValidJson) {
    foreach ($parsed["data"] as $i => $obj) {
        if (!is_array($obj)) {
            continue;
        }
        if (!array_key_exists("legacy_field", $obj)) {
            $rowsMissingLegacyField[] = $i;
        }
        if (!array_key_exists("folio_field", $obj)) {
            $rowsMissingFolioField[] = $i;
            continue;
        }
        $providedRowByField[$obj["folio_field"]] = $obj;
    }
}

// ---------------------------------------------------------------------------
// Check 8: required fields missing or unmapped. Covers required fields that
// are entirely absent from the file as well as ones present with
// legacy_field "Not mapped" and no literal value given either.
//
// A required field that belongs to an array-of-objects instance (e.g.
// additionalCallNumbers[1].callNumber) is only flagged if at least one
// sibling field within that SAME instance (additionalCallNumbers[1].*) is
// actually mapped. If the whole instance is missing/unmapped, that's just
// the optional array not being used, not a violation.
// ---------------------------------------------------------------------------

$UNMAPPED_DEFAULT = ["legacy_field" => "Not mapped", "value" => ""];

function is_unmapped_row($row) {
    $legacy = $row["legacy_field"] ?? "Not mapped";
    $value = $row["value"] ?? "";
    return $legacy === "Not mapped" && ($value === "" || $value === null);
}

function array_instance_key($field) {
    if (!preg_match_all('/\[\d+\]/', $field, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $last = end($m[0]); // [text, offset]
    return substr($field, 0, $last[1] + strlen($last[0]));
}

echo "\n8. Required fields missing or unmapped:\n";
$requiredUnmapped = [];
if ($isValidJson) {
    foreach ($requiredFields as $field) {
        $row = $providedRowByField[$field] ?? $UNMAPPED_DEFAULT;
        if (!is_unmapped_row($row)) {
            continue; // it's mapped, fine
        }

        $instanceKey = array_instance_key($field);
        if ($instanceKey === null) {
            // top-level required field, not part of any array instance
            $requiredUnmapped[] = $field;
            continue;
        }

        // Only flag if a sibling within the same array instance is mapped;
        // otherwise the whole instance is simply unused.
        foreach ($providedRowByField as $otherField => $otherRow) {
            if ($otherField === $field || strpos($otherField, $instanceKey . ".") !== 0) {
                continue;
            }
            if (!is_unmapped_row($otherRow)) {
                $requiredUnmapped[] = $field;
                break;
            }
        }
    }
}
if (empty($requiredUnmapped)) {
    echo $isValidJson ? "   None.\n" : "   Skipped (JSON did not parse).\n";
} else {
    echo "   " . implode(", ", $requiredUnmapped) . "\n";
}

// ---------------------------------------------------------------------------
// Check 9: non-string legacy_field values.
// ---------------------------------------------------------------------------

echo "\n9. Non-string legacy_field values:\n";
$nonStringLegacy = [];
if ($isValidJson) {
    foreach ($providedRowByField as $field => $row) {
        if (array_key_exists("legacy_field", $row) && !is_string($row["legacy_field"])) {
            $nonStringLegacy[] = "$field (" . json_encode($row["legacy_field"]) . ")";
        }
    }
}
if (empty($nonStringLegacy)) {
    echo $isValidJson ? "   None.\n" : "   Skipped (JSON did not parse).\n";
} else {
    foreach ($nonStringLegacy as $note) {
        echo "   $note\n";
    }
}

// ---------------------------------------------------------------------------
// Check 10: the same legacy_field value used as the target for more than
// one distinct folio_field (excluding the "Not mapped" sentinel, which is
// expected to repeat).
// ---------------------------------------------------------------------------

echo "\n10. Duplicate legacy_field targets:\n";
$legacyTargets = []; // legacy_field value => [folio_fields]
if ($isValidJson) {
    foreach ($providedRowByField as $field => $row) {
        $legacy = $row["legacy_field"] ?? null;
        if (!is_string($legacy) || $legacy === "Not mapped") {
            continue;
        }
        $legacyTargets[$legacy][] = $field;
    }
}
$dupTargetsFound = false;
foreach ($legacyTargets as $legacy => $fields) {
    if (count($fields) > 1) {
        $dupTargetsFound = true;
        $fieldNotes = array_map(function ($field) use ($providedFieldLines) {
            $fieldLines = implode(", ", $providedFieldLines[$field] ?? []);
            return $fieldLines !== "" ? "$field (line $fieldLines)" : $field;
        }, $fields);
        echo "   '$legacy' is used for: " . implode(", ", $fieldNotes) . "\n";
    }
}
if (!$dupTargetsFound) {
    echo $isValidJson ? "   None.\n" : "   Skipped (JSON did not parse).\n";
}

// ---------------------------------------------------------------------------
// Check 11: rows with no folio_field key at all (wouldn't show up in the
// text-based scans used by the other checks).
// ---------------------------------------------------------------------------

echo "\n11. Rows missing a folio_field key entirely:\n";
if (empty($rowsMissingFolioField)) {
    echo $isValidJson ? "   None.\n" : "   Skipped (JSON did not parse).\n";
} else {
    foreach ($rowsMissingFolioField as $i) {
        $obj = $parsed["data"][$i];
        $hint = isset($obj["legacy_field"]) ? " (legacy_field: " . json_encode($obj["legacy_field"]) . ")" : "";
        // The line number is the actionable reference; data[i] (the row's
        // position in the JSON array) only shows up as a fallback when no
        // line number could be determined for this row.
        $location = isset($objectOpenLines[$i]) ? "on line " . $objectOpenLines[$i] : "data[$i]";
        echo "   $location has no folio_field key$hint\n";
    }
}

// ---------------------------------------------------------------------------
// Check 12: rows with no legacy_field key at all.
// ---------------------------------------------------------------------------

echo "\n12. Rows missing a legacy_field key entirely:\n";
if (empty($rowsMissingLegacyField)) {
    echo $isValidJson ? "   None.\n" : "   Skipped (JSON did not parse).\n";
} else {
    foreach ($rowsMissingLegacyField as $i) {
        $obj = $parsed["data"][$i];
        $hint = isset($obj["folio_field"]) ? " (folio_field: " . json_encode($obj["folio_field"]) . ")" : "";
        $location = isset($objectOpenLines[$i]) ? "on line " . $objectOpenLines[$i] : "data[$i]";
        echo "   $location has no legacy_field key$hint\n";
    }
}

echo "\n========================================\n";
echo "Done.\n";
