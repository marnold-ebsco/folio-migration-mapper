<?php

$HTTP_HEADERS = ["User-Agent: folio-schema-tools"];

function prompt_required($promptText) {
    echo $promptText;
    $value = trim(fgets(STDIN) ?: "");
    if ($value === "") {
        fwrite(STDERR, "Error: no input provided.\n");
        exit(1);
    }
    return $value;
}

// These two schemas are standardized and identical across every FOLIO module,
// so they're hardcoded rather than looked up.
$KNOWN_SCHEMAS = [
    "tags.schema" => [
        "type" => "object",
        "description" => "arbitrary tags associated with this record",
        "properties" => [
            "tagList" => [
                "description" => "List of tags",
                "type" => "array",
                "items" => ["type" => "string"]
            ]
        ],
        "additionalProperties" => false
    ],
    "metadata.schema" => [
        "type" => "object",
        "readonly" => true,
        "description" => "Metadata about creation and changes to records, provided by the server (client should not provide)",
        "properties" => [
            "createdDate" => ["type" => "string", "description" => "Date and time when the record was created"],
            "createdByUserId" => ["type" => "string", "description" => "ID of the user who created the record (when available)"],
            "createdByUsername" => ["type" => "string", "description" => "Username of the user who created the record (when available)"],
            "updatedDate" => ["type" => "string", "description" => "Date and time when the record was last updated"],
            "updatedByUserId" => ["type" => "string", "description" => "ID of the user who last updated the record (when available)"],
            "updatedByUsername" => ["type" => "string", "description" => "Username of the user who last updated the record (when available)"]
        ],
        "additionalProperties" => false
    ]
];

function fetch_url($url, $headers) {
    $context = stream_context_create([
        "http" => [
            "header" => implode("\r\n", $headers),
            "timeout" => 15,
            "ignore_errors" => true
        ]
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }
    return $body;
}

function fetch_json($url, $headers) {
    $body = fetch_url($url, $headers);
    if ($body === null) {
        return null;
    }
    $data = json_decode($body, true);
    return $data === null ? null : $data;
}

class RefResolver {
    private $repo;
    private $headers;
    private $knownSchemas;
    private $treePaths = null;
    private $fileCache = [];

    public function __construct($repo, $headers, $knownSchemas) {
        $this->repo = $repo;
        $this->headers = $headers;
        $this->knownSchemas = $knownSchemas;
    }

    private function loadTree() {
        if ($this->treePaths !== null) {
            return;
        }
        $this->treePaths = [];
        foreach (["master", "main"] as $branch) {
            $data = fetch_json("https://api.github.com/repos/{$this->repo}/git/trees/{$branch}?recursive=1", $this->headers);
            if ($data && !empty($data["tree"])) {
                foreach ($data["tree"] as $t) {
                    if (($t["type"] ?? "") === "blob") {
                        $this->treePaths[] = $t["path"];
                    }
                }
                break;
            }
        }
    }

    private function findPath($basename) {
        $this->loadTree();
        $candidates = array_values(array_filter($this->treePaths, function ($p) use ($basename) {
            return basename($p) === $basename;
        }));
        if (empty($candidates)) {
            return null;
        }
        usort($candidates, function ($a, $b) {
            $aSchemas = strpos($a, "/schemas/") !== false ? 0 : 1;
            $bSchemas = strpos($b, "/schemas/") !== false ? 0 : 1;
            return $aSchemas - $bSchemas;
        });
        return $candidates[0];
    }

    public function fetchRef($refValue) {
        $basename = basename($refValue);
        if (isset($this->knownSchemas[$basename])) {
            return $this->knownSchemas[$basename];
        }
        if (array_key_exists($basename, $this->fileCache)) {
            return $this->fileCache[$basename];
        }
        $path = $this->findPath($basename);
        if (!$path) {
            $this->fileCache[$basename] = null;
            return null;
        }
        $resolved = null;
        foreach (["master", "main"] as $branch) {
            $resolved = fetch_json("https://raw.githubusercontent.com/{$this->repo}/{$branch}/{$path}", $this->headers);
            if ($resolved !== null) {
                break;
            }
        }
        $this->fileCache[$basename] = $resolved;
        return $resolved;
    }

    public function dereference($node, $depth = 0) {
        if ($depth > 6) {
            return $node;
        }
        if (is_array($node) && array_is_list($node)) {
            $result = [];
            foreach ($node as $v) {
                $result[] = $this->dereference($v, $depth + 1);
            }
            return $result;
        }
        if (!is_array($node)) {
            return $node;
        }

        if (isset($node['$ref'])) {
            $resolved = $this->fetchRef($node['$ref']);
            if ($resolved !== null) {
                $merged = $resolved;
                foreach ($node as $k => $v) {
                    if ($k !== '$ref') {
                        $merged[$k] = $v;
                    }
                }
                $node = $merged;
            }
        }

        $result = [];
        foreach ($node as $k => $v) {
            $result[$k] = $this->dereference($v, $depth + 1);
        }
        return $result;
    }
}

function load_schema($inputPath, $headers) {
    $parsed = parse_url($inputPath);
    $isUrl = isset($parsed["scheme"]) && in_array($parsed["scheme"], ["http", "https"]);

    if (!$isUrl) {
        $contents = @file_get_contents($inputPath);
        if ($contents === false) {
            fwrite(STDERR, "Error: could not find or read schema file '$inputPath'\n");
            exit(1);
        }
        $schema = json_decode($contents, true);
        if ($schema === null && json_last_error() !== JSON_ERROR_NONE) {
            fwrite(STDERR, "Error: '$inputPath' is not valid JSON: " . json_last_error_msg() . "\n");
            exit(1);
        }
        $inputDir = dirname($inputPath);
        if ($inputDir === ".") {
            $inputDir = "";
        }
        $inputFilename = basename($inputPath);
        $stem = preg_replace('/\.[^.]+$/', '', $inputFilename);
        $outputStem = str_replace("_schema", "", $stem);
        return [$schema, $inputDir, $outputStem, null];
    }

    $raw = fetch_url($inputPath, $headers);
    if ($raw === null) {
        fwrite(STDERR, "Error: could not fetch URL '$inputPath'\n");
        exit(1);
    }

    // FOLIO doc pages are hosted as https://s3.amazonaws.com/foliodocs/api/{repo}/r/{page}.html
    $repo = null;
    if (isset($parsed["path"]) && preg_match('#/api/([^/]+)/r/#', $parsed["path"], $m)) {
        $repo = "folio-org/" . $m[1];
    }

    $anchor = $parsed["fragment"] ?? null;
    if ($anchor) {
        // raml2html doc page (e.g. .../item-storage.html#item_storage_items_post) --
        // the raw schema JSON for that endpoint's request body is embedded in the
        // page as a literal <pre><code>{...}</code></pre> block right after the
        // "<anchor>_request" tab pane.
        $pattern = '#id="' . preg_quote($anchor, '#') . '_request".*?<pre><code>(.*?)</code></pre>#s';
        if (!preg_match($pattern, $raw, $m)) {
            fwrite(STDERR, "Could not find a schema block for anchor '$anchor' on the page\n");
            exit(1);
        }
        $schemaText = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        $schema = json_decode($schemaText, true);
        if ($schema === null && json_last_error() !== JSON_ERROR_NONE) {
            fwrite(STDERR, "Error: schema block for anchor '$anchor' is not valid JSON: " . json_last_error_msg() . "\n");
            exit(1);
        }
        $outputStem = $anchor;
    } else {
        $schema = json_decode($raw, true);
        if ($schema === null && json_last_error() !== JSON_ERROR_NONE) {
            fwrite(STDERR, "Error: content at '$inputPath' is not valid JSON: " . json_last_error_msg() . "\n");
            exit(1);
        }
        $path = $parsed["path"] ?? "";
        $filename = basename($path);
        $stem = preg_replace('/\.[^.]+$/', '', $filename);
        $outputStem = str_replace("_schema", "", $stem);
    }
    $outputStem = str_replace("_post", "", $outputStem);

    return [$schema, getcwd(), $outputStem, $repo];
}

// A field is "actively mapped" if it has a real legacy_field name or a
// literal value. Returns just the bracket's inner content (no brackets):
// the legacy_field name as-is, the value in double quotes, or <true>/<false>
// for a boolean value. Returns "" if the field isn't actively mapped.
function mapped_mark_content($row) {
    if (!$row) {
        return "";
    }
    $legacy = $row["legacy_field"] ?? "Not mapped";
    if ($legacy && $legacy !== "Not mapped") {
        return (string)$legacy;
    }
    $value = $row["value"] ?? "";
    if ($value !== "" && $value !== null) {
        if (is_bool($value)) {
            return "<" . ($value ? "true" : "false") . ">";
        }
        return '"' . $value . '"';
    }
    return "";
}

function build_key_tree($node, $path, $rowsByField) {
    $tree = [];
    $props = $node["properties"] ?? [];
    ksort($props);
    foreach ($props as $key => $val) {
        if ($key === "legacyIdentifier") {
            $suffix = " (added for f_m_t)";
        } elseif (!empty($val["readonly"]) || $key === "_version") {
            $suffix = " (readonly)";
        } else {
            $suffix = "";
        }
        $fullPath = $path ? "$path.$key" : $key;
        $items = $val["items"] ?? [];
        $isArray = ($val["type"] ?? null) === "array";
        // Arrays are prompted per-instance, but the key list only shows the
        // field's structural position once -- instance 1 is used as the
        // representative row to check for an active mapping.
        $lookupPath = $isArray ? "{$fullPath}[1]" : $fullPath;
        $content = mapped_mark_content($rowsByField[$lookupPath] ?? null);
        if ($isArray && !empty($items["properties"])) {
            $children = build_key_tree($items, "{$fullPath}[1]", $rowsByField);
        } elseif (!empty($val["properties"])) {
            $children = build_key_tree($val, $fullPath, $rowsByField);
        } else {
            $children = [];
        }
        $mapped = $content !== "";
        if (!$mapped) {
            foreach ($children as $c) {
                if ($c["mapped"]) {
                    $mapped = true;
                    break;
                }
            }
        }
        $tree[] = ["key" => $key, "suffix" => $suffix, "content" => $content, "children" => $children, "mapped" => $mapped];
    }
    return $tree;
}

function flatten_key_tree($tree, $depth, $showMarks, $compact) {
    $lines = [];
    foreach ($tree as $node) {
        if ($compact && !$node["mapped"]) {
            continue;
        }
        $prefix = str_repeat("  ", $depth) . ($depth > 0 ? "|" : "");
        $mark = ($showMarks && $node["content"] !== "") ? "  [" . $node["content"] . "]" : "";
        $lines[] = $prefix . $node["key"] . $node["suffix"] . $mark;
        $lines = array_merge($lines, flatten_key_tree($node["children"], $depth + 1, $showMarks, $compact));
    }
    return $lines;
}

// $rowsByField drives both the display marks and the --compact filtering,
// so it's built the same way regardless of whether marks are shown --
// $showMarks and $compact are independent toggles on top of the same data.
// If $rowsByField is unavailable (e.g. the provided file didn't parse),
// compact filtering is skipped rather than dropping every field.
function build_key_lines($node, $rowsByField = null, $showMarks = true, $compact = false) {
    $tree = build_key_tree($node, "", $rowsByField ?? []);
    return flatten_key_tree($tree, 0, $showMarks, $compact && $rowsByField !== null);
}

// Special case: the mod-user-import "import" request body wraps the actual
// per-user record in a "users" array alongside unrelated batch-control
// fields (totalRecords, deactivateMissingUsers, updateOnlyPresentFields,
// sourceType). For this one schema, map the individual user record instead
// of the batch wrapper.
define("USER_IMPORT_URL", "https://s3.amazonaws.com/foliodocs/api/mod-user-import/r/import.html#user_import_post");

function apply_user_import_exception($schema, $inputPath) {
    if ($inputPath !== USER_IMPORT_URL) {
        return $schema;
    }
    $usersItems = $schema["properties"]["users"]["items"] ?? null;
    if (!$usersItems) {
        return $schema;
    }
    $newSchema = $usersItems;
    foreach (["totalRecords", "deactivateMissingUsers", "updateOnlyPresentFields", "sourceType"] as $excluded) {
        unset($newSchema["properties"][$excluded]);
    }
    return $newSchema;
}
