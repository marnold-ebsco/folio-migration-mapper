<?php

require_once __DIR__ . "/yaml_lite.php";

$HTTP_HEADERS = ["User-Agent: folio-schema-tools"];

// Unauthenticated GitHub API requests are capped at 60/hour; set GITHUB_TOKEN
// (any personal access token works, no scopes needed for public repos) to
// raise that to 5000/hour. Added only for requests actually going to GitHub
// -- sending it to other hosts (e.g. the s3.amazonaws.com doc pages) can
// make them reject the request outright, since they don't expect a GitHub
// token in that header.
$GITHUB_TOKEN = getenv("GITHUB_TOKEN");

function is_github_host($url) {
    $host = parse_url($url, PHP_URL_HOST) ?? "";
    return $host === "api.github.com" || str_ends_with($host, ".githubusercontent.com");
}

function prompt_required($promptText) {
    echo $promptText;
    $value = trim(fgets(STDIN) ?: "");
    if ($value === "") {
        fwrite(STDERR, "Error: no input provided.\n");
        exit(1);
    }
    return $value;
}

function prompt_with_default($promptText, $default) {
    echo $promptText;
    $value = trim(fgets(STDIN) ?: "");
    return $value !== "" ? $value : $default;
}

// Prompts for a file path or URL, then runs $action on it (e.g. load_schema
// or reading a file) -- $action is expected to return null, with its own
// error message, if the resource can't be found/read. Rather than ending
// the script on the first typo, this re-prompts up to $maxAttempts times in
// total before giving up for good. Hitting enter with no input at all is
// handled by prompt_required() itself exiting immediately, unconditionally
// -- that's not something this retry loop catches or counts against.
function prompt_for_resource($promptText, $action, $maxAttempts = 3) {
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $value = prompt_required($promptText);
        $result = $action($value);
        if ($result !== null) {
            return [$value, $result];
        }
        $remaining = $maxAttempts - $attempt;
        if ($remaining > 0) {
            fwrite(STDERR, "Please try again ($remaining attempt" . ($remaining !== 1 ? "s" : "") . " left).\n");
        } else {
            fwrite(STDERR, "Too many failed attempts. Exiting.\n");
            exit(1);
        }
    }
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
    global $GITHUB_TOKEN;
    if ($GITHUB_TOKEN && is_github_host($url)) {
        $headers[] = "Authorization: token $GITHUB_TOKEN";
    }
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

// Redoc-rendered OpenAPI doc pages (.../api/{repo}/s/{page}.html) don't
// embed a scrapeable schema block like raml2html pages do. Across the FOLIO
// ERM modules we've checked (mod-agreements, mod-licenses), the module's
// GitHub repo also publishes a bundled, self-contained OpenAPI YAML for
// each doc page at "docs/API/yamls/{page}.yaml" -- try that exact path
// first, then fall back to a basename search of the whole repo tree
// (preferring anything still under a "yamls" directory, in case the doc
// page's name doesn't match the primary bundle for some module).
function discover_openapi_yaml_url($repo, $page, $headers) {
    $treePaths = [];
    $branchFound = null;
    foreach (["master", "main"] as $branch) {
        $data = fetch_json("https://api.github.com/repos/{$repo}/git/trees/{$branch}?recursive=1", $headers);
        if ($data && !empty($data["tree"])) {
            foreach ($data["tree"] as $t) {
                if (($t["type"] ?? "") === "blob") {
                    $treePaths[] = $t["path"];
                }
            }
            $branchFound = $branch;
            break;
        }
    }

    $candidates = array_values(array_filter(
        ["docs/API/yamls/{$page}.yaml", "docs/API/yamls/{$page}.yml"],
        function ($p) use ($treePaths) { return in_array($p, $treePaths); }
    ));
    if (empty($candidates)) {
        $candidates = array_values(array_filter($treePaths, function ($p) use ($page) {
            $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
            $base = pathinfo($p, PATHINFO_FILENAME);
            return $base === $page && ($ext === "yaml" || $ext === "yml");
        }));
        usort($candidates, function ($a, $b) {
            $aYamls = strpos($a, "/yamls/") !== false ? 0 : 1;
            $bYamls = strpos($b, "/yamls/") !== false ? 0 : 1;
            if ($aYamls !== $bYamls) {
                return $aYamls - $bYamls;
            }
            return strlen($a) - strlen($b);
        });
    }

    if (empty($candidates)) {
        return null;
    }
    return "https://raw.githubusercontent.com/{$repo}/{$branchFound}/{$candidates[0]}";
}

// A Redoc operation-permalink fragment, e.g. "#tag/Agreements/operation/postSA"
// -- the tag is only there for the reader's benefit, since operationId is
// expected to be unique within a document. Returns the matching
// operationId, or null if the fragment isn't in this form.
function match_operation_anchor($fragment) {
    if (preg_match('#^tag/[^/]+/operation/([^/]+)$#', $fragment, $m)) {
        return $m[1];
    }
    return null;
}

function is_openapi_fragment($fragment) {
    return str_starts_with($fragment, "/") || match_operation_anchor($fragment) !== null;
}

function find_operation_schema($doc, $operationId) {
    foreach (($doc["paths"] ?? []) as $pathItem) {
        if (!is_array($pathItem)) {
            continue;
        }
        foreach ($pathItem as $op) {
            if (!is_array($op) || ($op["operationId"] ?? null) !== $operationId) {
                continue;
            }
            $content = $op["requestBody"]["content"] ?? [];
            $media = $content["application/json"] ?? (is_array($content) && !empty($content) ? reset($content) : null);
            if (is_array($media) && isset($media["schema"])) {
                return $media["schema"];
            }
        }
    }
    return null;
}

// A raml2html doc page embeds one request-body schema per endpoint, keyed
// by an anchor. An OpenAPI/YAML document has no such single embedded block,
// so either a JSON-pointer fragment (e.g. "#/components/schemas/Agreement")
// or a Redoc operation-permalink fragment (e.g.
// "#tag/Agreements/operation/postSA") is required instead, to say which
// schema in the document to map -- whether the YAML lives at a URL or a
// local file path.
// Returns [$schema, $outputDir, $outputStem, $repo] on success, or null on
// any failure (with its own error message already printed) so the caller
// can decide whether to re-prompt or give up.
function load_openapi_yaml($inputPath, $isUrl, $fragment, $headers) {
    $basePath = explode("#", $inputPath, 2)[0];
    if ($isUrl) {
        $raw = fetch_url($basePath, $headers);
        if ($raw === null) {
            fwrite(STDERR, "Error: could not fetch URL '$basePath'\n");
            return null;
        }
        $outputDir = getcwd();
    } else {
        $raw = @file_get_contents($basePath);
        if ($raw === false) {
            fwrite(STDERR, "Error: could not find or read schema file '$basePath'\n");
            return null;
        }
        $outputDir = dirname($basePath);
        if ($outputDir === ".") {
            $outputDir = "";
        }
    }

    $doc = parse_yaml($raw);

    $operationId = match_operation_anchor($fragment);
    if ($operationId !== null) {
        $target = find_operation_schema($doc, $operationId);
        if ($target === null) {
            fwrite(STDERR, "Error: could not find operation '$operationId' with a JSON request body in '$basePath'\n");
            return null;
        }
        $schema = dereference_local($doc, $target);
        // Prefer the referenced component schema's own name (e.g.
        // "Agreement") for the output file name -- the raw operationId
        // (e.g. "postSA") doesn't carry that context. Falls back to the
        // operationId if the request body isn't a single named $ref.
        $outputStem = $operationId;
        if (is_array($target) && isset($target['$ref']) && is_string($target['$ref']) && str_starts_with($target['$ref'], "#/")) {
            $refParts = array_values(array_filter(explode("/", rtrim($target['$ref'], "/"))));
            $outputStem = end($refParts);
        }
        return [$schema, $outputDir, $outputStem, null];
    }

    $pointer = "#" . $fragment;
    try {
        $target = resolve_pointer($doc, $pointer);
    } catch (Exception $e) {
        fwrite(STDERR, "Error: could not resolve pointer '$pointer' in '$basePath'\n");
        return null;
    }

    $schema = dereference_local($doc, $target);
    $outputStem = array_values(array_filter(explode("/", rtrim($fragment, "/"))));
    $outputStem = end($outputStem);
    return [$schema, $outputDir, $outputStem, null];
}

// Returns [$schema, $outputDir, $outputStem, $repo] on success, or null on
// any failure (with its own error message already printed) so the caller
// can decide whether to re-prompt or give up.
function load_schema($inputPath, $headers) {
    $parsed = parse_url($inputPath);
    $isUrl = isset($parsed["scheme"]) && in_array($parsed["scheme"], ["http", "https"]);

    $ext = strtolower(pathinfo($parsed["path"] ?? "", PATHINFO_EXTENSION));
    $fragment = $parsed["fragment"] ?? "";
    if (($ext === "yaml" || $ext === "yml") && is_openapi_fragment($fragment)) {
        return load_openapi_yaml($inputPath, $isUrl, $fragment, $headers);
    }

    // A Redoc OpenAPI doc page (as opposed to a raml2html page) given with a
    // JSON-pointer or operation-permalink fragment -- auto-discover the
    // module's bundled YAML and load the pointed-to schema from that
    // instead of trying to scrape the (unscrapeable) HTML page itself.
    if ($isUrl && preg_match('#/api/([^/]+)/s/([^/]+)\.html$#', $parsed["path"] ?? "", $m)) {
        if (!is_openapi_fragment($fragment)) {
            fwrite(STDERR, "Error: this looks like a Redoc/OpenAPI doc page, which doesn't embed a " .
                "scrapeable schema block. Give either a JSON-pointer fragment " .
                "(e.g. '#/components/schemas/Agreement') or a Redoc operation " .
                "permalink fragment (e.g. '#tag/Agreements/operation/postSA').\n");
            return null;
        }
        $repo = "folio-org/" . $m[1];
        $page = $m[2];
        $yamlUrl = discover_openapi_yaml_url($repo, $page, $headers);
        if (!$yamlUrl) {
            fwrite(STDERR, "Error: could not find a bundled OpenAPI YAML for page '$page' in $repo\n");
            return null;
        }
        return load_openapi_yaml($yamlUrl . "#" . $fragment, true, $fragment, $headers);
    }

    if (!$isUrl) {
        $contents = @file_get_contents($inputPath);
        if ($contents === false) {
            fwrite(STDERR, "Error: could not find or read schema file '$inputPath'\n");
            return null;
        }
        $schema = json_decode($contents, true);
        if ($schema === null && json_last_error() !== JSON_ERROR_NONE) {
            fwrite(STDERR, "Error: '$inputPath' is not valid JSON: " . json_last_error_msg() . "\n");
            return null;
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
        return null;
    }

    // FOLIO doc pages are hosted as https://s3.amazonaws.com/foliodocs/api/{repo}/{view}/{page}.html
    // -- "r" (raml2html, one page per module) and "p" (raml2html, one page
    // per resource) both embed the same kind of scrapeable schema block.
    $repo = null;
    if (isset($parsed["path"]) && preg_match('#/api/([^/]+)/[rp]/#', $parsed["path"], $m)) {
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
            return null;
        }
        $schemaText = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        $schema = json_decode($schemaText, true);
        if ($schema === null && json_last_error() !== JSON_ERROR_NONE) {
            fwrite(STDERR, "Error: schema block for anchor '$anchor' is not valid JSON: " . json_last_error_msg() . "\n");
            return null;
        }
        $outputStem = $anchor;
    } else {
        $schema = json_decode($raw, true);
        if ($schema === null && json_last_error() !== JSON_ERROR_NONE) {
            fwrite(STDERR, "Error: content at '$inputPath' is not valid JSON: " . json_last_error_msg() . "\n");
            return null;
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

// Some FOLIO schemas (e.g. item.json's itemIdentifier) never got the
// readonly/readOnly attribute set, but say so with a "(read only)"
// parenthetical in their description instead -- treat that specific
// convention as equivalent so such fields are still excluded from actual
// maps (and still marked in the template-only list). Deliberately narrow
// (parenthetical only): a bare "read-only"/"readonly" substring elsewhere
// in a description can just be describing what the field's value *means*
// (e.g. mod-agreements' own settable "readonly" flag on a knowledge base,
// whose description reads "...protected/read-only...") rather than saying
// the field itself can't be set.
function is_readonly($subschema) {
    if (!empty($subschema["readonly"]) || !empty($subschema["readOnly"])) {
        return true;
    }
    $description = $subschema["description"] ?? "";
    return (bool)preg_match('/\(\s*read[\s-]?only\s*\)/i', $description);
}

// The schema-type note shown per field when listing a template-only key
// list (nothing is ever mapped there, so there's nothing for the usual mark
// to show -- this describes the field's shape instead). Mirrors the
// required/type/enum/pattern/format formatting used for JSON descriptions,
// except enum options are comma-delimited here rather than pipe-delimited.
function type_annotation($subschema, $isRequired = false, $requiredIfUsed = false) {
    $typeVal = $subschema["type"] ?? null;
    if (is_array($typeVal)) {
        $typeStr = implode(",", $typeVal);
    } else {
        $typeStr = $typeVal ? $typeVal : "unknown";
    }
    $note = "type: $typeStr";

    $enumVal = $subschema["enum"] ?? null;
    if ($enumVal) {
        $enumStr = implode(",", array_map(function ($e) {
            return is_bool($e) ? ($e ? "true" : "false") : (string)$e;
        }, $enumVal));
        $note .= " enum: $enumStr";
    }

    $formatVal = $subschema["format"] ?? null;
    if ($formatVal) {
        $note .= "  format: $formatVal";
    }

    $patternVal = $subschema["pattern"] ?? null;
    if ($patternVal) {
        $uuidPatterns = [
            '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$',
            '^[a-f0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$',
        ];
        $note .= " pattern: " . (in_array($patternVal, $uuidPatterns) ? "UUID" : $patternVal);
    }

    if (!empty($subschema["x-reference-data"])) {
        $note .= "  (reference data)";
    }

    if ($isRequired) {
        $note = ($requiredIfUsed ? "Required if used. " : "REQUIRED ") . $note;
    }

    return $note;
}

function build_key_tree($node, $path, $rowsByField, $schemaTypeInfo = false) {
    $tree = [];
    $props = $node["properties"] ?? [];
    ksort($props);
    $nodeRequired = $node["required"] ?? [];
    foreach ($props as $key => $val) {
        if ($key === "legacyIdentifier") {
            $suffix = " (added for f_m_t)";
        } elseif (is_readonly($val) || $key === "_version") {
            $suffix = " (readonly)";
        } else {
            $suffix = "";
        }
        $fullPath = $path ? "$path.$key" : $key;
        $items = $val["items"] ?? [];
        $isArray = ($val["type"] ?? null) === "array";
        // Arrays are prompted per-instance, but the key list only shows the
        // field's structural position once -- instance 0 is used as the
        // representative row to check for an active mapping.
        $lookupPath = $isArray ? "{$fullPath}[0]" : $fullPath;
        $content = mapped_mark_content($rowsByField[$lookupPath] ?? null);
        $isRequired = in_array($key, $nodeRequired);
        $typeNote = $schemaTypeInfo ? type_annotation($val, $isRequired, strpos($fullPath, "[") !== false) : "";
        if ($isArray && !empty($items["properties"])) {
            $children = build_key_tree($items, "{$fullPath}[0]", $rowsByField, $schemaTypeInfo);
        } elseif (!empty($val["properties"])) {
            $children = build_key_tree($val, $fullPath, $rowsByField, $schemaTypeInfo);
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
        $tree[] = [
            "key" => $key, "suffix" => $suffix, "content" => $content, "typeNote" => $typeNote,
            "children" => $children, "mapped" => $mapped,
        ];
    }
    return $tree;
}

function flatten_key_tree($tree, $depth, $showMarks, $compact, $schemaTypeInfo = false) {
    $lines = [];
    foreach ($tree as $node) {
        if ($compact && !$node["mapped"]) {
            continue;
        }
        $prefix = str_repeat("  ", $depth) . ($depth > 0 ? "|" : "");
        $mark = ($showMarks && $node["content"] !== "") ? "  [" . $node["content"] . "]" : "";
        $typeSuffix = ($schemaTypeInfo && $node["typeNote"] !== "") ? "  " . $node["typeNote"] : "";
        $lines[] = $prefix . $node["key"] . $node["suffix"] . $mark . $typeSuffix;
        $lines = array_merge($lines, flatten_key_tree($node["children"], $depth + 1, $showMarks, $compact, $schemaTypeInfo));
    }
    return $lines;
}

// $rowsByField drives both the display marks and the --compact filtering,
// so it's built the same way regardless of whether marks are shown --
// $showMarks and $compact are independent toggles on top of the same data.
// If $rowsByField is unavailable (e.g. the provided file didn't parse),
// compact filtering is skipped rather than dropping every field.
function build_key_lines($node, $rowsByField = null, $showMarks = true, $compact = false, $schemaTypeInfo = false) {
    $tree = build_key_tree($node, "", $rowsByField ?? [], $schemaTypeInfo);
    return flatten_key_tree($tree, 0, $showMarks, $compact && $rowsByField !== null, $schemaTypeInfo);
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
