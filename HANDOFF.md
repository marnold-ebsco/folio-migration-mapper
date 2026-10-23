# HANDOFF — folio-migration-mapper

Read this first after a session clear. See `README.md` for full usage docs;
this file is about *state*, not usage.

**Location / execution:** this repo lives in WSL at
`~/folio-migration-mapper` (`\\wsl.localhost\Ubuntu-24.04\home\marnold\folio-migration-mapper`
from Windows). Do all work here — running code, tests, linting, git — in
WSL, not Windows.

## What this is

Two functionally-identical CLI tools, kept in byte-for-byte parity:

- **Python**: `python/create_map.py`, `python/verify_map.py`
- **PHP**: `php/create_map.php`, `php/verify_map.php`

They build/verify `folio_migration_tools`-style mapping JSON files directly
from FOLIO JSON schemas (fetched from FOLIO API doc pages or raw schema
URLs — see README's "views" section). Shared logic lives in
`folio_schema_lib.py` / `folio_schema_lib.php` plus a hand-rolled
dependency-free YAML parser (`yaml_lite.py` / `.php`) for OpenAPI schemas.

Repo: `github.com:marnold-ebsco/folio-migration-mapper`, `main` branch only
(no other branches currently exist). Working tree is clean as of this
handoff, HEAD at `377248e` (pushed).

## Array-instance prompting no longer guesses (2026-10-23)

Every array-instance field (`notes[0].note`, `notes[1].note`, ...) is now
prompted independently with a plain `[Not mapped]` default. Previously the
first instance's answer was remembered as a "base" legacy name and later
instances were suggested `base_1`, `base_2`, etc. as a default — a guess
the user could silently accept by hitting Enter. That suggestion logic
(`array_base_legacy` / `$arrayBaseLegacy`, the suffix-appending branch of
`prompt_leaf()`) has been removed entirely in both `python/create_map.py`
and `php/create_map.php`: whatever is typed (or "Not mapped" if left blank)
is used exactly as given, with no suffix ever appended automatically.
Smoke-tested against a synthetic 2-instance array schema in both languages,
including leaving a later instance blank (correctly drops out, doesn't
inherit anything). Not yet committed/pushed.

## Schema-selection menu (most recent feature, 2026-10-23)

The schema-source prompt (`"Enter path or URL to the schema file: "`) in
all four scripts is now a numbered menu instead of a bare free-form prompt:

```
Select the object to build a map for:
  1) Users      -> mod-user-import's import.html#user_import_post
  2) Holdings   -> mod-inventory-storage's holdings-storage.html#holdings_storage_holdings_post
  3) Items      -> mod-inventory-storage's item-storage.html#item_storage_items_post
  4) Other (enter a URL)
Choice [1-4]:
```

Options 1-3 jump straight to a hardcoded FOLIO doc-page URL (View 1); option
4 falls back to the original free-form path/URL prompt. New shared helper
`prompt_for_schema_resource()` in `folio_schema_lib.py`/`.php` (alongside
the existing `prompt_for_resource()`, which is still used for the *other*
prompts — e.g. `verify_map`'s "existing map file to verify" path — so both
helpers remain exported/used). Retry behavior matches `prompt_for_resource`:
an invalid menu choice or a failed load re-shows the whole menu, up to 3
attempts total.

Smoke-tested in WSL: all three presets plus "Other" in both `create_map`
and `verify_map`, Python and PHP, including an invalid-choice retry;
Python/PHP output parity reconfirmed via `jq -S .` diff for Users, Holdings,
and Items. README's `create_map`/`verify_map` sections updated to match.
Committed (`377248e`) and pushed to `main`.

## Current state (as of 2026-10-23)

Everything below has been committed and pushed to `main`. No open branches,
no pending work, no known bugs.

Recent commit history (newest first):

- `377248e` — Add numbered menu (Users/Holdings/Items/Other) for schema
  source prompt
- `41c99c4` — Tighten description-only readonly detection to avoid false
  positives
- `132c063` — Exclude readonly fields detected only via description text
  (e.g. `itemIdentifier`)
- `1b50538` — Document 0-based array indexing in README
- `dd92c95` — Use 0-based indexing for array field keys (e.g.
  `additionalCallNumbers[0]`)
- `73944b6` — Flag FOLIO "reference data" fields instead of silently
  showing plain string
- `d7db9b3` and earlier — View 4 (OpenAPI/Redoc) support, GITHUB_TOKEN rate
  limiting, output-folder prompt, retry-on-not-found, etc.

### Readonly-field exclusion (most recent feature)

A field is now excluded from the actual map entirely (shown only as
`(readonly)` in `--map-template-only`'s key list) if either:

- the schema sets `readonly: true` / `readOnly: true`, **or**
- its `description` contains the literal parenthetical `(read only)` (any
  casing, space or hyphen) — this catches `item.json`'s `itemIdentifier`,
  which has no `readonly` attribute at all, only prose saying so.

The text check is deliberately narrow (parenthetical only). A looser bare
substring match was tried first and reverted after an audit turned up a
real false positive: mod-agreements' `Agreement` schema has its own
settable `readonly` boolean field (a KB-protection flag) whose *description*
contains "protected/read-only" as a business term, not a readonly marker.
The audit covered all 135 mod-inventory-storage schema files plus
mod-agreements' `agreements.yaml`/`licenses.yaml` — `itemIdentifier` is the
only field anywhere in that set using the description-only convention.

Shared helper: `is_readonly(subschema)` in `folio_schema_lib.py`/`.php`,
used by `create_map`'s interactive walker, `verify_map`'s walker and array
discovery, and `build_key_tree`'s `--map-template-only` suffix logic.

### 0-based array indices

Array field keys (`additionalCallNumbers[0]`, `[1]`, ...) now start at 0,
not 1, in both languages — in the interactive instance-count loops, the
`--map-template-only` representative-row lookup, and `verify_map`'s
array-instance-count derivation from an existing map file's indices.

### Other established conventions (older, still current)

- **4 FOLIO doc "views"**: View 1/2 (raml2html scrape, same pattern), View
  3 (unsupported), View 4 (OpenAPI/Redoc, needs a JSON-pointer or
  operation-permalink fragment; auto-discovers the module's YAML on GitHub
  via the `docs/API/yamls/{page}.yaml` convention).
- **`GITHUB_TOKEN`** env var raises GitHub's rate limit from 60→5000/hour;
  only attached to requests actually going to `api.github.com` /
  `*.githubusercontent.com` (sending it to e.g. `s3.amazonaws.com` breaks
  those fetches — this was a real bug, now fixed).
- **`oneOf` + `$ref`-to-Refdata pattern** (FOLIO ERM's runtime-configurable
  reference-data convention, e.g. `Agreement.agreementStatus`) is tagged
  `x-reference-data: true` during `$ref` resolution and shown as
  `(reference data)` in both the `.txt` key list and JSON `description`.
  Doesn't apply to View 1/2 schemas (they don't use `oneOf`).
- **Output folder prompt**: blank → writes directly into `mapping/`; a
  value creates that as a *subfolder* of `mapping`, not a replacement.
- **Retry behavior**: file/URL prompts retry up to 3 total attempts on a
  not-found error; a blank/empty answer at any prompt exits immediately
  with no retry.

## Testing approach

No automated test runner is checked into the repo. Regression testing is
done ad hoc each session via paired Python/PHP runs against real FOLIO
schemas (`mod-inventory-storage`'s item-storage schema for View 1, and
`mod-agreements`' `agreements.yaml` → `Agreement` for View 4/OpenAPI +
reference-data), diffing `.txt`/`.json` output with `jq -S .` for JSON. The
three fixtures in `python/tests/` and `php/tests/`
(`verify_test_{clean,multi_error,malformed}.json`) exercise `verify_map`'s
12 checks against that same item-storage schema.

`mapping/verify_test/` in both `python/` and `php/` holds prior verify_map
test outputs — **do not delete its contents unless specifically asked.**

Gotcha: unauthenticated GitHub API calls are capped at 60/hour and this is
easy to exhaust during a schema-wide audit (hit this directly during the
readonly-exclusion audit above). Set `GITHUB_TOKEN` before heavy testing,
or just wait out the hourly reset (`curl -s https://api.github.com/rate_limit`
to check).

## Next steps

None currently queued. No open bugs, no in-progress feature. If resuming
cold, re-run a quick paired Python/PHP smoke test (View 1 + View 4,
`--map-template-only`) before making changes, to confirm the baseline is
still green.
