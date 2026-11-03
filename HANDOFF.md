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

Repo: `github.com:marnold-ebsco/folio-migration-mapper`. As of this handoff
there are two relevant branches, both pushed and working tree clean:

- `main` — HEAD `012e322`. Several small Python-only fixes/features landed
  here this session (see "Legacy-field picker + related fixes" below);
  PHP (`php/create_map.php`) has **not** been touched and does not have any
  of them yet.
- `legacy-field-picker` — HEAD `693f814`, rebased on top of current `main`.
  Adds the TSV data-file legacy-field picker (Python only). This is the
  currently-checked-out branch in this working copy.

## Legacy-field picker + related fixes (2026-11-03/04) — Python only, PHP not started

Built across several small branches, each merged to `main` first (when the
fix/feature didn't depend on the picker) before `legacy-field-picker` was
rebased on top — see git log on `main` for the individual commits/merges.
All of this is **Python-only** (`python/create_map.py`,
`python/lib/folio_schema_lib.py`); `php/create_map.php` /
`php/lib/folio_schema_lib.php` have none of it. Porting to PHP is explicitly
the next piece of work (see "Next steps").

**On `main`** (so PHP inherits these once ported, and plain free-text mode
benefits too):
- Array prompts are now deferred **schema-wide**, not just per nesting
  level — every array field anywhere in the schema (even nested inside a
  plain object) waits for the *entire* schema's scalar fields to be
  prompted first, then all arrays are prompted together after one banner.
  (`build_and_prompt_object`'s `defer_arrays` param; `deferred_arrays`
  module-level list.) The `.txt` key list stays alphabetical regardless
  (unaffected, built from its own schema walk) — the JSON mapping's row
  order was already prompt-order rather than alphabetical before this
  change, and still is.
- `ask_count()` (the "How many instances of 'X'..." prompt) no longer
  crashes on non-numeric input (e.g. `?` typed out of habit) — re-prompts
  with an error instead.
- `?` at that same prompt previews the fields each instance would have
  (object array: sorted property names + types; scalar array: a one-line
  note). Helper: `describe_array_item_fields()`.
- End-of-run warning for unconditionally-`REQUIRED` fields left
  `"Not mapped"` (excludes `"REQUIRED if used"` fields inside array
  instances, since those are routinely/intentionally left unmapped
  whenever that instance goes unused). Uses the previously-collected-but-
  unused `required_fields` list.
- **`#N` correction command**: type `#N` at *any* leaf prompt to jump back
  and re-answer prompt number N, then resume where you were. Blank during
  a correction means "keep the current value" (not "reset to Not
  mapped" — that's still blank's meaning on a fresh prompt). Backed by
  `prompt_registry` (number → `(row, is_required)`), the reusable
  `ask_leaf()` input loop (split out of `prompt_leaf`), and
  `describe_current_answer()`. Scoped to leaf prompts only — array-count
  prompts aren't numbered and aren't retroactively editable (would mean
  adding/removing rows after the fact). **Known limitation**: correcting a
  `"REQUIRED if used"` field after its array instance's silent-skip
  decision was already made does *not* retroactively re-prompt that
  instance's sibling fields — you'd need to `#N` those individually too.

**Only on `legacy-field-picker`** (depends on the TSV picker's state —
`data_columns`, `data_usage_counts`, etc. — which doesn't exist on `main`):
- First prompt (before the schema menu) asks for an optional TSV data
  file; blank skips it and everything behaves exactly as before. Can also
  be given directly via `--data-file PATH` to skip that prompt entirely
  (`load_data_file_columns()`, shared between the prompt and the flag).
- At each leaf prompt, the file's header columns can be picked by number
  instead of typed, with a `*` marker for already-used columns (reuse is
  allowed, not removed from the pool), and `?`/`list` to redisplay the
  grid (`format_field_grid()`, width-aware via `shutil.get_terminal_size`).
  An out-of-range number re-prompts with a note rather than becoming a
  bogus literal field name.
- At the array-count prompt, `?` also shows the TSV columns (not just the
  schema's field names) — the point being to decide *before* sizing an
  array whether the file even has anything worth mapping into it (e.g. no
  URL-like column → skip `electronicAccess` entirely).
- End-of-run `<stem>_field_usage.log` lists which columns were used (and
  where) vs. unused.
- The `#N` correction command above correctly un-/re-attributes TSV column
  usage when a correction changes which column (or none) a row maps to
  (`unrecord_usage()`), so corrected runs don't double-count a column in
  the usage log.

**To test** (from repo root):

```bash
git checkout legacy-field-picker
git pull
.venv/bin/python python/create_map.py --data-file working/CUMARCExport110226_repaired_prepped.tsv
```

(or omit `--data-file` to get the interactive prompt, or drop it and the
flag entirely to test plain free-text mode / `main`'s fixes with no data
file at all). `.venv` already exists with `pytest`+`flake8` installed; no
`requirements.txt` in this repo.

## Scalars-then-arrays prompt ordering (2026-10-23)

Fields used to be prompted strictly in schema-alphabetical order, which
interleaved scalar and array fields (e.g. `addresses` between `active` and
`barcode`). Both `build_and_prompt_object()` and `build_and_prompt_instance()`
(in `python/create_map.py` and `php/create_map.php`) now split each
object's fields into two alphabetical passes: all non-array fields (plain
leaves and nested plain objects) first, then all array fields. A
`--- Switching to array fields ---` banner prints once before the array
pass begins (skipped entirely in silent/`--map-template-only` runs, since
those produce no prompts at all), and each individual array field gets its
own `--- fieldName ---` break immediately before its "how many instances"
prompt. Nested plain objects (e.g. `personal`) recurse and get the same
scalars-then-arrays split internally. Smoke-tested against a synthetic
schema mixing scalars, a nested plain object, and two array fields in both
languages; prompt order and JSON output confirmed identical. Committed
(`6ac72f9`) and pushed to `main`.

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
inherit anything). Committed (`d61867a`) and pushed to `main`.

## Schema-selection menu (2026-10-23)

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

## Smoke test (2026-10-23) — baseline confirmed green

Ran the paired Python/PHP smoke test this handoff recommends, both with
`--map-template-only`:

- **View 1 (Items, `mod-inventory-storage`)**: identical JSON (60 rows) and
  `.txt` key list between languages.
- **View 4 (Agreements, OpenAPI/Redoc, `mod-agreements`)**: identical JSON
  (99 rows) and `.txt` key list; both excluded the same 4 unresolved-`$ref`
  keys (`customProperties`, `linkedLicenses[0].remoteId_object`,
  `orgs[0].org.orgsUuid_object`, `usageDataProviders[0].remoteId_object`).

Compared with `diff <(jq -S . ...)` for JSON, plain `diff` for `.txt`. Test
output was written to throwaway `mapping/smoketest*` subfolders and deleted
afterward; `git status` was clean before and after. No code changes.

### Running this test from a Windows Claude session (gotcha)

This repo's own CLAUDE.md says work happens in WSL, not Windows — but when
a Windows-side Claude Code session runs WSL commands via
`wsl.exe -d Ubuntu-24.04 bash -lc '...'` (per the global CLAUDE.md rule, to
avoid Git Bash's `/tmp`-path rewriting), **any `#` character inside that
single-quoted command string gets silently truncated** somewhere in the
Windows→WSL argument-passing path — it's not a bash comment-parsing issue
inside WSL itself (confirmed: `bash -lc 'URL="...#frag"; echo "[$URL]"'`
from Windows prints `[]`, but the same command run natively inside a WSL
terminal is fine). This bit hard here because View 4 FOLIO doc-page URLs
and JSON-pointer fragments always contain `#`
(e.g. `.../agreements.html#/components/schemas/Agreement`), and
`create_map`'s "Other" option needs that full URL typed at a prompt.

**Workaround used:** don't put the `#`-bearing string inline in the
`wsl.exe ... bash -lc '...'` argument at all. Instead, write the full
multi-line stdin (menu choice, URL, output folder) to a file first with a
heredoc (`cat > /tmp/foo.txt <<EOF ... EOF`, run as its own `wsl.exe`
call), then pipe that file into the script (`python3 create_map.py < foo.txt`)
in a separate call. The heredoc's `EOF` body isn't subject to the same
truncation, so the `#` survives intact.

**Better fix, not yet done:** avoid the Windows→WSL hop per-command
entirely — e.g. open a real WSL terminal (`wsl.exe -d Ubuntu-24.04` with no
trailing `-c`/`bash -lc` wrapper, or a WSL-side terminal tab) and run
`python3`/`php` directly from there for anything that needs a URL with a
`#` in it. The one-shot `wsl.exe -d Ubuntu-24.04 bash -lc '<cmd>'` pattern
is fine for commands with no `#`.

## Current state (as of 2026-10-23; superseded in part — see 2026-11-03/04 section above)

Everything below has been committed and pushed to `main`. No open branches,
no pending work, no known bugs. (This was true as of 2026-10-23; as of
2026-11-04 there's an additional open branch, `legacy-field-picker`, and
an explicit next step — see above and "Next steps" below.)

Recent commit history (newest first):

- `6ac72f9` — Prompt scalars before arrays, with a visible switch-over
- `d61867a` — Stop guessing legacy field names for array instances
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

## Next steps (as of 2026-11-04)

**Port everything in "Legacy-field picker + related fixes" above to PHP**
(`php/create_map.php`, `php/lib/folio_schema_lib.php`) — this is the
explicit next piece of work, not yet started. Do it as a straight port
from the now-verified Python implementation (cheaper than designing both
at once), ideally mirroring the same commit-by-commit structure (the
`main`-eligible fixes as their own PHP commits merged to `main`, then the
picker-specific pieces on a PHP equivalent of `legacy-field-picker`) so
parity stays easy to audit. Read the Python commits on `main` and on
`legacy-field-picker` (`git log`) for the design decisions already made —
don't re-litigate: grid-once-then-`?`-to-relist, `*` marker, blank-keeps-
on-correction vs blank-means-Not-mapped-on-fresh-prompt, `_field_usage.log`
naming, `#N` scoped to leaf prompts only, etc.

Once PHP is ported and both are re-confirmed at parity (same paired
Python/PHP smoke test this file has always recommended), `legacy-field-picker`
can merge to `main` for real (not just rebase onto it).

If resuming cold on something unrelated instead, re-run a quick paired
Python/PHP smoke test (View 1 + View 4, `--map-template-only`) before
making changes, to confirm the pre-picker baseline is still green — note
this won't cover any of the 2026-11-03/04 work, which is Python-only.
