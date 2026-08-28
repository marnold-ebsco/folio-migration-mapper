# folio-migration-mapper

Command-line tools for building and verifying `folio_migration_tools`-style mapping
files from FOLIO JSON schemas. Available in two functionally identical
implementations, kept in byte-for-byte parity:

- **Python**: `python/create_map.py`, `python/verify_map.py`
- **PHP**: `php/create_map.php`, `php/verify_map.php`

Each language's pair shares a small library (`folio_schema_lib.py` / `folio_schema_lib.php`)
with schema loading, `$ref` resolution, and key-list building.

## Requirements

- Python 3 (no third-party packages), or PHP 8+ (no extensions beyond the
  default `json`)
- Network access to `s3.amazonaws.com` (FOLIO doc pages) and `api.github.com` /
  `raw.githubusercontent.com` (schema `$ref` resolution)

## create_map

Interactively builds a mapping JSON file (and a plain key-list `.txt` file)
from a FOLIO JSON schema. Prompts for the schema file or URL, then for a
legacy field name (or a literal value) for each mappable field.

```bash
python3 python/create_map.py
php php/create_map.php
```

When prompted for the schema, you can give:

- A local file path to a JSON schema
- A raw schema URL
- A FOLIO API doc page URL with an anchor. FOLIO publishes API docs in
  [several formats](https://dev.folio.org/reference/api/) (referred to
  below as "views" to match that page's terminology):
  - **View 1** — one raml2html page per module, `/r/{module}.html#anchor`,
    e.g.
    `https://s3.amazonaws.com/foliodocs/api/mod-inventory-storage/r/item-storage.html#item_storage_items_post`
  - **View 2** — one raml2html page per resource, `/p/{resource}.html#anchor`,
    e.g.
    `https://s3.amazonaws.com/foliodocs/api/mod-inventory-storage/p/item-storage.html#item_storage_items_post`
    — same anchor convention and embedded schema block as View 1, just a
    different page layout
  - **View 3** — rare; not currently supported
  - **View 4** — Redoc-rendered OpenAPI, `/s/{page}.html` (see below)
- An OpenAPI/YAML document (View 4) — either the doc page URL itself with a
  JSON-pointer fragment, e.g.
  `https://s3.amazonaws.com/foliodocs/api/mod-agreements/s/agreements.html#/components/schemas/Agreement`
  (the module's bundled OpenAPI YAML is auto-discovered on GitHub, using the
  `docs/API/yamls/{page}.yaml` convention seen across the FOLIO ERM
  modules), or a direct link to the YAML/JSON file itself with the same
  kind of fragment, e.g.
  `https://raw.githubusercontent.com/folio-org/mod-agreements/master/docs/API/yamls/agreements.yaml#/components/schemas/Agreement`
  — useful if a module doesn't follow that convention, or auto-discovery
  can't find the file (it'll say so). Unlike View 1/2 pages, a Redoc/View 4
  page doesn't embed a scrapeable schema block, so the pointer names which
  schema to map explicitly. Local `$ref`s, `oneOf` (picks the first
  non-`$ref` branch), and `allOf` (merges all branches) are resolved
  automatically; the YAML reader supports plain block-style YAML only (no
  anchors/aliases or flow collections beyond empty `[]`/`{}`).

At each field prompt, the answer can be:

- A legacy field name (plain text) — maps that field by name
- A quoted literal value, e.g. `"Available"` or `'Available'` — maps the
  field to that fixed value instead of a legacy field name
- `true` / `false` (any case, quoted or not) — maps the field to that
  literal boolean value

Required fields are marked `(REQUIRED)` in the prompt; a required field
inside an optional array instance is marked `(REQUIRED if used)` — if you
leave it unmapped, the rest of that array instance is silently skipped.

### create_map options

| Option | Effect |
| --- | --- |
| `--no-text-annotation` | Don't annotate the `.txt` key list with each field's active mapping. Annotations (`fieldname  [legacy_field]`, `fieldname  ["value"]`, `fieldname  [<true>]`) are shown by default. |
| `--compact` | Remove fields with no active mapping from both the `.txt` key list and the JSON map. Off by default (all fields are included in both). |
| `--map-template-only` | A separate mode: skips all interactive prompting and writes one full map with every field present and `legacy_field` left as `"Not mapped"`. Ignores `--compact`/`--no-text-annotation` (with a notice), since a map with nothing mapped has nothing to compact or annotate. Instead, the `.txt` key list annotates each field with its schema shape: `fieldname  type: <type>`, plus `enum: opt1,opt2` if the field is an enum and `pattern: <pattern>` (or `pattern: UUID` for the standard UUID patterns) if it has one. |
| `--help`, `-h` | Show usage and exit. |

Output goes to a `mapping/` folder alongside the schema file (or the current
directory, for a URL): `{stem}_mapping.json` and `{stem}.txt`.

## verify_map

Builds an in-memory reference ("master") map straight from a FOLIO JSON
schema, then runs 12 checks comparing it against an existing mapping file.
Prompts for the schema file or URL, then for the path to the map file to
verify.

```bash
python3 python/verify_map.py
php php/verify_map.php
```

### The 12 checks

1. **Master fields missing from provided file** — schema fields absent from
   the file being checked (required fields are reported under check 8
   instead)
2. **JSON validity** — whether the file parses as JSON with a top-level
   `data` array
3. **Extra (non-standard) fields on objects** — any key besides
   `folio_field`, `legacy_field`, `value`, `description`, `fallback_legacy_field`,
   `fallback_value`, `rules`, `rules_apply_scope`
4. **Duplicate `folio_field` values** — with line numbers
5. **Missing array elements (gaps)** — e.g. `additionalCallNumbers[2].prefix`
   present at index 1 but missing at index 2, when a sibling field has both
6. **Quoted boolean values** — `"value": "true"` instead of `"value": true`
7. **`folio_field` values not matching the schema** — misspellings, with
   line numbers
8. **Required fields missing or unmapped** — absent entirely, or present
   with `legacy_field: "Not mapped"` and no literal value
9. **Non-string `legacy_field` values**
10. **Duplicate `legacy_field` targets** — the same legacy field name used
    for more than one FOLIO field, with line numbers for each
11. **Rows missing a `folio_field` key entirely**
12. **Rows missing a `legacy_field` key entirely**

Checks 4–7 work directly on the raw text, so they still run even if the file
fails to parse as JSON; checks that require parsed data are skipped and
reported as such.

### verify_map options

| Option | Effect |
| --- | --- |
| `--no-text-annotation` | Don't annotate the master `.txt` key list with each field's active mapping *in the provided file*. Shown by default. |
| `--compact` | Remove fields with no active mapping (in the provided file) from both the master `.txt` key list and the master JSON map. Off by default. Only affects the written master files, never the 12 checks themselves. |
| `--help`, `-h` | Show usage and exit. |

Output goes to a `mapping/` folder alongside the schema file (or the current
directory, for a URL): `{stem}_master_mapping.json` and `{stem}_master.txt`,
plus the verification report printed to stdout.

## Test fixtures

`python/tests/` and `php/tests/` each contain three sample files for exercising
`verify_map` against the `mod-inventory-storage` item-storage schema:

- `verify_test_multi_error.json` — trips all 11 content checks (one object
  carries three simultaneous errors)
- `verify_test_malformed.json` — the same file with a structural JSON break,
  for check 2
- `verify_test_clean.json` — passes every check

## Getting the files onto an EC2 instance

Any of the standard approaches work; pick whichever fits your existing access:

**Option A — clone from the git remote** (this repo already has one at
GitHub):

```bash
ssh ec2-user@<ec2-host>
git clone git@github.com:marnold-ebsco/folio-migration-mapper.git
```

This requires the EC2 instance to have its own SSH key added as a deploy key
(or your key forwarded via `ssh -A`) on the GitHub repo.

**Option B — `scp` directly from this machine** (no remote needed; run from
the machine that has the repo):

```bash
scp -i /path/to/your-key.pem -r ~/folio-migration-mapper ec2-user@<ec2-host>:~/
```

**Option C — `rsync`** (faster for repeat transfers, skips unchanged files):

```bash
rsync -avz -e "ssh -i /path/to/your-key.pem" ~/folio-migration-mapper/ ec2-user@<ec2-host>:~/folio-migration-mapper/
```

Replace `ec2-user` with the correct login for your AMI (e.g. `ubuntu` for
Ubuntu AMIs) and `<ec2-host>` with the instance's public DNS name or IP. The
`mapping/` output folders and `*.zip`/`__pycache__`/`spareparts` are
gitignored, so Option A won't bring those along; Options B/C will copy
everything currently on disk unless you exclude them (e.g. add
`--exclude 'mapping' --exclude '__pycache__'` to the `rsync` command).

Once on the instance, make sure Python 3 or PHP 8+ is installed
(`python3 --version` / `php --version`), then run the scripts exactly as
above.
