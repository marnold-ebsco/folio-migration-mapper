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

Repo: `github.com:marnold-ebsco/folio-migration-mapper`. **Update
(2026-11-03 follow-up): both picker branches below have since been
merged into `main` (`--no-ff`, one merge commit per branch, Python first)
and pushed, and the now-merged `legacy-field-picker` /
`legacy-field-picker-php` branches have been deleted both locally and on
`origin`.** `main` is now the only branch and has everything described
in this file, including the TSV legacy-field picker, in both Python and
PHP. README.md was also updated in the same pass to document `--data-file`
and the picker (closing out Next-steps items 3 and 5 below). The original
end-of-session note is left as-is beneath this update for the history of
*why* the branches existed and what they each covered.

Original note, written before the merge/cleanup above: **As of this
handoff (2026-11-03/04 session) there are three relevant branches, all
pushed to origin, all working trees clean:**

- **`main`** — HEAD `d2c357b`. Has every fix/feature below *except* the
  TSV legacy-field picker itself, in both Python and PHP (ported pairwise
  this session — Python first on its own small branches, merged to `main`,
  then PHP ported from the verified Python and merged to `main` too).
- **`legacy-field-picker`** — HEAD `6a44fbe` (Python TSV picker, rebased
  onto `main` as of the commit before `d2c357b`/the `.gitignore` fix —
  i.e. it does **not** yet have that `.gitignore` fix; harmless, just
  don't be surprised by `working/`/`.venv/` showing as untracked there).
  **Not yet merged to `main` for real** (deliberately — see "Next steps").
  — **merged into `main` and deleted as of the follow-up above.**
- **`legacy-field-picker-php`** — HEAD `5a414c4`. PHP port of the picker,
  branched from `main` *after* all the PHP main-fixes above were merged
  in, and does have the `.gitignore` fix. Also not yet merged to `main`.
  — **merged into `main` and deleted as of the follow-up above.**
- This working copy is currently checked out on **`legacy-field-picker-php`**
  — stale; the branch no longer exists, so the working copy is now on `main`.

(If those HEAD hashes look stale when you read this, trust `git log
--oneline -1 main` over this file — this is a point-in-time note, not a
live pointer.)

## Everything built this session (2026-11-03/04)

User's own words, roughly: a TSV-column picker for `create_map`'s
legacy-field prompts, plus several smaller usability fixes that came up
while testing it. Session started Python-only; user's normal workflow is
PHP, so everything was ported to PHP once the Python side was verified
(porting from a verified reference is cheaper than designing both at
once — see cost-estimate discussion earlier in this same conversation if
resuming it; not otherwise recorded here).

**Branching pattern used throughout**: any fix/feature that didn't
inherently depend on the TSV picker's state went on its own small branch
off `main`, got tested, merged to `main` (`--no-ff`), and pushed — in
*both* languages, Python first each time. Only once `main` had it did the
picker branch (`legacy-field-picker`, then later `legacy-field-picker-php`)
rebase on top and layer on the picker-specific piece. This kept the
picker branches from re-deriving fixes that belonged everywhere, and kept
each `main` commit auditable/bisectable on its own. Expect this same
pattern if there's more follow-up work before the picker branches
eventually merge to `main` for real.

### On `main` (both languages)

- **Schema-wide (not just per-nesting-level) scalar/array prompt
  ordering.** Every array field anywhere in the schema — even nested
  inside a plain object — now waits for the *entire* schema's scalar
  fields to be prompted first, then all arrays are prompted together
  after one `--- Switching to array fields ---` banner. Python:
  `build_and_prompt_object()`'s `defer_arrays` param (default `True` for
  the top-level walk; `False` when called from inside an array instance,
  which is already past the global split) + module-level `deferred_arrays`
  list, drained after the top-level walk returns. PHP: same shape,
  `$deferArrays` param + `$deferred_arrays`. The `.txt` key list stays
  alphabetical regardless (built from its own schema walk, independent of
  prompt order) — the JSON mapping's row order was already prompt-order
  rather than alphabetical before this change, and still is, in both
  languages.
- **`ask_count()`** (the "How many instances of 'X'..." prompt) no longer
  crashes on non-numeric input (e.g. `?` typed out of habit) — it
  re-prompts with an error instead.
- **`?` at that same prompt** previews the fields each instance would
  have before you commit to a count: an object array lists its sorted
  top-level property names + types; a scalar array gets a one-line note.
  Python: `describe_array_item_fields()`. PHP: same name/shape.
- **End-of-run warning** for unconditionally-`REQUIRED` fields left
  `"Not mapped"` — printed after "Wrote key list to...". Deliberately
  excludes `"REQUIRED if used"` fields inside array instances, since those
  are routinely/intentionally left unmapped whenever that instance goes
  unused (not a gap). Uses the `required_fields`/`$required_fields` list
  that was already being collected in both languages but never used for
  anything until now.
- **`#N` correction command.** Type `#N` at *any* leaf prompt to jump
  back and re-answer prompt number N, then resume wherever you were.
  Blank during a correction means **"keep the current value"** — not
  "reset to Not mapped", which is still what blank means on a *fresh*
  prompt. Backed by a number→`(row, is_required)` registry (Python:
  `prompt_registry`, stores the row dict directly since Python dicts are
  references; PHP: `$prompt_registry`, stores the row's *index* into
  `$rows` instead, since a plain PHP array value wouldn't alias back —
  this is the one place the two languages' implementations differ in
  shape, not behavior) and a reusable input-loop function split out of
  `prompt_leaf` (Python: `ask_leaf()`; PHP: same name). Scoped to leaf
  prompts only — array-count prompts aren't numbered and aren't
  retroactively editable (would mean adding/removing rows after the
  fact). **Known limitation, not fixed**: correcting a `"REQUIRED if
  used"` field after its array instance's silent-skip decision was
  already made does *not* retroactively re-prompt that instance's sibling
  fields — you'd need to `#N` those individually too. User was told this
  up front when the feature was proposed.
- **Legacy-field picker from a TSV data file.** An optional first prompt
  (before the schema menu) asks for a TSV data file to pick legacy field
  names from instead of typing them; blank skips it and everything
  behaves exactly as it did before this feature existed. Can also be
  given directly via `--data-file PATH` to skip that prompt entirely — a
  bad/missing value exits immediately, no retry (it's a fixed CLI
  argument, not something to retype). Python: `load_data_file_columns()`
  + `prompt_for_data_file()` in `folio_schema_lib.py`, shared between the
  interactive prompt and the flag. PHP: same names in
  `folio_schema_lib.php`, using `RuntimeException`/`InvalidArgumentException`
  where Python uses `OSError`/`ValueError`. Once loaded, each leaf prompt
  shows the file's header columns as a numbered grid — sorted
  alphabetically/case-insensitively (added 2026-11-03 follow-up, so the
  grid is easy to scan rather than listed in raw file-header order) —
  and a column can be picked by number instead of typed, with a `*`
  marker for already-used columns (reuse is allowed, not removed from
  the pool) and `?`/`list` to redisplay the grid. Python:
  `format_field_grid()` (width via `shutil.get_terminal_size`). PHP: same
  name/shape, but PHP has no `shutil` equivalent, so there's a new
  `get_terminal_width()` helper (`COLUMNS` env var → `tput cols` → flat
  80 fallback). An out-of-range number re-prompts with a note rather than
  becoming a bogus literal field name. At the array-count prompt, `?`
  *also* shows this same column grid (not just the schema's field names)
  — the point is deciding, before sizing an array, whether the file has
  anything worth mapping into it at all (e.g. no URL-like column → don't
  bother mapping `electronicAccess`). At the end of a run that used a
  data file, `<stem>_field_usage.log` lists which columns were used (and
  where) vs. unused. The `#N` correction command correctly re-/un-
  attributes TSV column usage when a correction changes which column (or
  none) a row maps to — Python: `unrecord_usage()`; PHP: same name.
  Without this, correcting a picked-column answer would double-count the
  old column in the usage log. Verified in both languages: correcting a
  row from one column to another releases the old column back to
  "unused" and attributes the new one, nothing double-counted. Originally
  built on separate `legacy-field-picker`/`legacy-field-picker-php`
  branches (depending on picker-only state — `data_columns`/
  `$dataColumns`, `data_usage_counts`/`$dataUsageCounts`, etc. — that
  didn't exist on `main` yet); both branches were merged into `main`
  (`--no-ff`) and deleted in the 2026-11-03 follow-up noted at the top of
  this file, so this is now just a normal part of both tools, documented
  in README.md's "Legacy-field picker (TSV data file)" section.

### Verification performed (both languages, this session)

Not a formal test suite — ad hoc scripted interactive runs (piping a
sequence of canned answers into `create_map.py`/`.php` via stdin),
mirrored line-for-line between Python and PHP once Python's behavior was
confirmed correct, including:

- A synthetic nested test schema (`aLeaf`, `mNested.{nestedLeaf,
  nestedArray}`, `topArray`, `zLeaf`) to prove the schema-wide scalar/array
  ordering: scalars prompted first (including the one nested inside
  `mNested`), *then* one banner, *then* both arrays — in both languages.
- `ask_count()` rejecting `?`/non-numeric input without crashing, in both
  languages, against that same schema.
- The real Holdings schema (`mod-inventory-storage`,
  `holdings-storage.html#holdings_storage_holdings_post`) for `?`'s field
  preview against `electronicAccess`'s real fields
  (`linkText`/`materialsSpecification`/`publicNote`/`relationshipId`/`uri`) —
  Python only; not re-verified against the real schema in PHP this
  session (synthetic-schema coverage was treated as sufficient parity
  evidence given identical code shape — flag this if it matters before
  relying on it further).
- The real TSV fixture (`working/CUMARCExport110226_repaired_prepped.tsv`,
  17 columns) for the picker: relist, pick-by-number, typed-name pick,
  reuse marker, literal value, out-of-range digit re-prompt, `--data-file`
  flag (happy path + bad path + missing value), and a `#N` correction
  that moves a column's attribution — in both languages, same scripted
  inputs mirrored.
- The end-of-run REQUIRED-fields warning, with and without a field
  correction moving a required field in/out of "Not mapped" — in both
  languages.

**Important caveat on all of the above**: this was piped-stdin scripted
verification (`printf '...' | python3 create_map.py`), not the user
interactively testing in a real terminal. The user ("testing now") was in
the middle of their own manual interactive testing of the Python picker
branch when this PHP port started — their findings, if any, aren't
reflected here. Check with them before assuming the Python branch is
fully signed off.

### Working directory housekeeping

`.gitignore` now ignores `/working/` (holds the user's real TSV test
file, `CUMARCExport110226_repaired_prepped.tsv` — 5.4MB, not meant to be
committed) and `.venv/` (created this session since none existed; has
`pytest` + `flake8` installed, no `requirements.txt` in this repo). Both
additions are on `main` and both picker branches.

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

## Current state (as of 2026-10-23; see top of file for 2026-11-03/04 session)

Everything below was true as of 2026-10-23 — committed and pushed to
`main`, no open branches, no pending work, no known bugs. It is **no
longer the full picture**: see the top of this file for the three-branch
state as of 2026-11-03/04. `main`'s commit history now continues well
past `6ac72f9` — run `git log --oneline main` for the current list rather
than trusting this section's list below as complete.

Recent commit history as of 2026-10-23 (newest first, now historical):

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

## Next steps (as of 2026-11-04 — supersedes the paragraph above; see the
2026-11-03 follow-up note near the top for what's changed since)

1. ~~Check in with the user about their own manual testing of
   `legacy-field-picker` (Python) before touching that branch further.~~
   **Moot** — the user directed the merge explicitly in the follow-up
   session despite this caveat being raised; no separate sign-off was
   given on their own interactive testing specifically, so if anything
   surfaces from that testing later, treat it as a normal post-merge bug
   report against `main` rather than something that should have blocked
   the merge.
2. **Still open**: the real-Holdings-schema `?`-preview check was never
   re-run against PHP independently (it was only done against Python;
   PHP parity was inferred from identical code shape against a synthetic
   schema). Worth doing if the PHP picker's `?`-preview output is ever in
   question.
3. ~~Merge both picker branches into `main` for real.~~ **Done** —
   both merged `--no-ff` and pushed, then both branches deleted locally
   and on `origin`.
4. ~~Fold this file's picker section into the main numbered feature
   list.~~ **Done** — the picker is now its own bullet under "On `main`
   (both languages)" instead of a separate "Only on the picker branches"
   section.
5. ~~Consider whether `README.md` needs updating to document `--data-file`
   and the TSV picker.~~ **Done** — README now has a "Legacy-field picker
   (TSV data file)" section and a `--data-file` row in the options table,
   covering both languages (no longer Python-only language anywhere in
   README).

If resuming cold on something **unrelated** to all of the above instead,
re-run a quick paired Python/PHP smoke test (View 1 + View 4,
`--map-template-only`) on `main` before making changes, to confirm the
pre-session baseline is still green — note this won't cover anything from
2026-11-03/04, which needs the verification steps above instead.
