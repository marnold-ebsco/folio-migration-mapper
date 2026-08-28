<?php

// A deliberately minimal YAML reader for OpenAPI documents: plain block
// mappings and sequences, scalars, and "|"/">" block scalars. No
// anchors/aliases, flow collections beyond "[]"/"{}", or multi-document
// streams -- everything the FOLIO OpenAPI specs we've seen actually use.

function yl_indent_of($line) {
    $trimmed = ltrim($line, " ");
    return strlen($line) - strlen($trimmed);
}

function yl_skip_blank($lines, $i) {
    $n = count($lines);
    while ($i < $n && trim($lines[$i]) === "") {
        $i++;
    }
    return $i;
}

function yl_parse_scalar($text) {
    $text = trim($text);
    if ($text === "") {
        return null;
    }
    if ($text === "[]") {
        return [];
    }
    if ($text === "{}") {
        return [];
    }
    $len = strlen($text);
    if ($len >= 2 && $text[0] === '"' && $text[$len - 1] === '"') {
        return substr($text, 1, -1);
    }
    if ($len >= 2 && $text[0] === "'" && $text[$len - 1] === "'") {
        return str_replace("''", "'", substr($text, 1, -1));
    }
    $low = strtolower($text);
    if ($low === "true") {
        return true;
    }
    if ($low === "false") {
        return false;
    }
    if ($low === "null" || $low === "~") {
        return null;
    }
    if (preg_match('/^-?\d+$/', $text)) {
        return (int)$text;
    }
    if (preg_match('/^-?\d+\.\d+$/', $text)) {
        return (float)$text;
    }
    return $text;
}

function yl_strip_quotes_key($key) {
    $key = trim($key);
    $len = strlen($key);
    if ($len >= 2 && $key[0] === $key[$len - 1] && ($key[0] === "'" || $key[0] === '"')) {
        return substr($key, 1, -1);
    }
    return $key;
}

function yl_split_key_value($content) {
    $content = rtrim($content);
    if (strlen($content) > 0 && $content[0] === '"') {
        $end = strpos($content, '"', 1);
        $key = substr($content, 1, $end - 1);
        $rest = substr($content, $end + 1);
    } elseif (strlen($content) > 0 && $content[0] === "'") {
        $end = strpos($content, "'", 1);
        $key = substr($content, 1, $end - 1);
        $rest = substr($content, $end + 1);
    } else {
        $idx = strpos($content, ":");
        if ($idx === false) {
            $idx = strlen($content);
        }
        $key = substr($content, 0, $idx);
        $rest = substr($content, $idx);
    }
    $rest = trim($rest);
    if (strlen($rest) > 0 && $rest[0] === ":") {
        $rest = trim(substr($rest, 1));
    }
    return [$key, $rest];
}

// Matches a genuine "key:" prefix (quoted or bare) so a sequence item's
// inline content can be told apart from a plain scalar that happens to
// contain a colon (e.g. a bare "https://..." string has no "key:" prefix).
function yl_is_kv_start($rest) {
    return preg_match('/^(?:"[^"]*"|\'[^\']*\'|[^:\s]+):(\s|$)/', $rest) === 1;
}

function yl_is_block_scalar($valText) {
    return preg_match('/^[|>][+-]?\d*$/', $valText) === 1;
}

function yl_parse_literal_block($lines, $i, $parentIndent) {
    $blockIndent = null;
    $contentLines = [];
    $n = count($lines);
    while ($i < $n) {
        $line = $lines[$i];
        if (trim($line) === "") {
            $contentLines[] = "";
            $i++;
            continue;
        }
        $curIndent = yl_indent_of($line);
        if ($curIndent <= $parentIndent) {
            break;
        }
        if ($blockIndent === null) {
            $blockIndent = $curIndent;
        }
        $contentLines[] = substr($line, $blockIndent);
        $i++;
    }
    while (!empty($contentLines) && end($contentLines) === "") {
        array_pop($contentLines);
    }
    return [implode("\n", $contentLines), $i];
}

function yl_consume_kv($lines, $i, $indent, $content, &$result) {
    [$key, $valText] = yl_split_key_value($content);
    $key = yl_strip_quotes_key($key);
    if ($valText === "") {
        $i++;
        [$value, $i] = yl_parse_block($lines, $i, $indent + 1);
        $result[$key] = $value;
    } elseif (yl_is_block_scalar($valText)) {
        $i++;
        [$value, $i] = yl_parse_literal_block($lines, $i, $indent);
        $result[$key] = $value;
    } else {
        $result[$key] = yl_parse_scalar($valText);
        $i++;
    }
    return $i;
}

function yl_parse_mapping($lines, $i, $indent, $firstOverride = null) {
    $result = [];
    if ($firstOverride !== null) {
        $i = yl_consume_kv($lines, $i, $indent, $firstOverride, $result);
    }
    $n = count($lines);
    while (true) {
        $i = yl_skip_blank($lines, $i);
        if ($i >= $n) {
            break;
        }
        $line = $lines[$i];
        if (yl_indent_of($line) !== $indent) {
            break;
        }
        $content = substr($line, $indent);
        if (str_starts_with($content, "- ")) {
            break;
        }
        $i = yl_consume_kv($lines, $i, $indent, $content, $result);
    }
    return [$result, $i];
}

function yl_parse_sequence($lines, $i, $indent) {
    $result = [];
    $n = count($lines);
    while (true) {
        $i = yl_skip_blank($lines, $i);
        if ($i >= $n) {
            break;
        }
        $line = $lines[$i];
        if (yl_indent_of($line) !== $indent) {
            break;
        }
        $content = substr($line, $indent);
        if (!str_starts_with($content, "-")) {
            break;
        }
        $rest = substr($content, 1);
        if (str_starts_with($rest, " ")) {
            $rest = substr($rest, 1);
        }
        $itemIndent = $indent + 2;
        if (trim($rest) === "") {
            $i++;
            [$value, $i] = yl_parse_block($lines, $i, $itemIndent);
            $result[] = $value;
        } elseif (yl_is_kv_start($rest)) {
            [$value, $i] = yl_parse_mapping($lines, $i, $itemIndent, $rest);
            $result[] = $value;
        } else {
            $result[] = yl_parse_scalar($rest);
            $i++;
        }
    }
    return [$result, $i];
}

function yl_parse_block($lines, $i, $minIndent) {
    $i = yl_skip_blank($lines, $i);
    if ($i >= count($lines)) {
        return [null, $i];
    }
    $line = $lines[$i];
    $curIndent = yl_indent_of($line);
    if ($curIndent < $minIndent) {
        return [null, $i];
    }
    $content = substr($line, $curIndent);
    if (str_starts_with($content, "- ")) {
        return yl_parse_sequence($lines, $i, $curIndent);
    }
    return yl_parse_mapping($lines, $i, $curIndent);
}

function parse_yaml($text) {
    $lines = [];
    foreach (explode("\n", $text) as $rawLine) {
        $line = rtrim($rawLine);
        if (trim($line) === "---" || str_starts_with(ltrim($line), "#")) {
            continue;
        }
        $lines[] = $line;
    }
    [$value, ] = yl_parse_block($lines, 0, 0);
    return $value;
}

function resolve_pointer($root, $pointer) {
    if (!str_starts_with($pointer, "#/")) {
        throw new Exception("unsupported pointer: $pointer");
    }
    $node = $root;
    foreach (explode("/", substr($pointer, 2)) as $part) {
        $part = str_replace(["~1", "~0"], ["/", "~"], $part);
        if (is_array($node) && array_is_list($node)) {
            $node = $node[(int)$part];
        } else {
            $node = $node[$part];
        }
    }
    return $node;
}

// Resolves local "$ref": "#/..." pointers, merges "allOf" branches (each
// resolved and combined -- properties/required are unioned across all
// branches, other keys are last-branch-wins), and collapses "oneOf"
// branches (a oneOf that includes a bare {"$ref": ...} branch alongside
// inline alternatives -- e.g. a field that's either a plain string or a
// full Refdata object -- picks the first non-$ref branch, since that's the
// simpler shape to map). A "currently resolving" set guards against
// circular $refs (e.g. two schemas that reference each other) by cutting
// the cycle short rather than recursing forever.
function dereference_local($root, $node, $resolving = []) {
    if (is_array($node) && array_is_list($node)) {
        $result = [];
        foreach ($node as $v) {
            $result[] = dereference_local($root, $v, $resolving);
        }
        return $result;
    }
    if (!is_array($node)) {
        return $node;
    }

    if (isset($node["allOf"]) && is_array($node["allOf"]) && !empty($node["allOf"])) {
        $merged = [];
        $mergedProperties = [];
        $mergedRequired = [];
        foreach ($node["allOf"] as $branch) {
            $resolvedBranch = dereference_local($root, $branch, $resolving);
            if (!is_array($resolvedBranch)) {
                continue;
            }
            foreach ($resolvedBranch as $k => $v) {
                if ($k === "properties" && is_array($v)) {
                    $mergedProperties = array_merge($mergedProperties, $v);
                } elseif ($k === "required" && is_array($v)) {
                    $mergedRequired = array_merge($mergedRequired, $v);
                } else {
                    $merged[$k] = $v;
                }
            }
        }
        if (!empty($mergedProperties)) {
            $merged["properties"] = $mergedProperties;
        }
        if (!empty($mergedRequired)) {
            $merged["required"] = array_values(array_unique($mergedRequired));
        }
        foreach ($node as $k => $v) {
            if ($k !== "allOf") {
                $merged[$k] = $v;
            }
        }
        return dereference_local($root, $merged, $resolving);
    }

    if (isset($node["oneOf"]) && is_array($node["oneOf"]) && !empty($node["oneOf"])) {
        $branches = $node["oneOf"];
        $chosen = $branches[0];
        foreach ($branches as $b) {
            if (!(is_array($b) && array_keys($b) === ["\$ref"])) {
                $chosen = $b;
                break;
            }
        }
        // FOLIO ERM modules model a "reference data" field (a category
        // whose allowed values are configurable at runtime, e.g.
        // agreementStatus) as oneOf[plain scalar, $ref to a Refdata-shaped
        // lookup schema]. The Refdata branch itself carries no fixed value
        // list to show, but it's worth flagging that this isn't just free
        // text.
        $isReferenceData = false;
        foreach ($branches as $b) {
            if (is_array($b) && is_string($b['$ref'] ?? null) && stripos($b['$ref'], "refdata") !== false) {
                $isReferenceData = true;
                break;
            }
        }
        $merged = is_array($chosen) ? $chosen : [];
        foreach ($node as $k => $v) {
            if ($k !== "oneOf") {
                $merged[$k] = $v;
            }
        }
        if ($isReferenceData) {
            $merged["x-reference-data"] = true;
        }
        return dereference_local($root, $merged, $resolving);
    }

    $ref = $node['$ref'] ?? null;
    if (is_string($ref) && str_starts_with($ref, "#/")) {
        if (in_array($ref, $resolving, true)) {
            return [];
        }
        $target = dereference_local($root, resolve_pointer($root, $ref), array_merge($resolving, [$ref]));
        $merged = is_array($target) ? $target : [];
        foreach ($node as $k => $v) {
            if ($k !== '$ref') {
                $merged[$k] = $v;
            }
        }
        return dereference_local($root, $merged, $resolving);
    }

    $result = [];
    foreach ($node as $k => $v) {
        $result[$k] = dereference_local($root, $v, $resolving);
    }
    return $result;
}
