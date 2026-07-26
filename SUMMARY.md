# gapcheck Plugin - Progress Summary

## Goal
Create a Moodle 5.x question behaviour plugin that gives per-gap instant visual feedback (✓/✗/∼) when a student fills gaps in cloze questions, without grading or revealing correct answers.

## Constraints & Preferences
- Must work with built-in cloze (multianswer), gapfill (qtype_gapfill), and formulas (qtype_formulas) questions.
- Must provide feedback as soon as the student stops typing in a gap (on blur/debounced input).
- Must not change any grading state — purely visual feedback.
- Must minimize server strain.
- For Moodle 5.x (MOODLE_500_STABLE branch).
- Background color on the input field, not a text indicator — keeps text flow intact.

## Progress
### Done
- Created full plugin `qbehaviour_gapcheck`: `version.php`, `behaviourtype.php`, `lang/en/`, `behaviour.php`, `renderer.php`, `amd/src/gapcheck.js`, `amd/build/gapcheck.min.js`, `styles.css`, `classes/privacy/provider.php`, `tests/behaviour_test.php`.
- Fixed `get_quba_id()` → `get_usage_id()`.
- Fixed `amd/build/gapcheck.min.js` (syntax error in hand-minified file corrupted JS combo bundle).
- Added multiple answer alternatives (split on `|` and `~`).
- Added numerical tolerance check — JS compares student number against stored `{v, t, k, f}` entries.
- Fixed cloze (multianswer) extraction: subquestions are keyed by place number (1-based), not 0-based — `$subq->answers` is **public** and directly accessible. All cloze test cases now work.
- Removed text-based cloze parsing (`parse_cloze_text`, `create_answer_rows_from_cloze`) — question text contains `{#N}` placeholders, not `{N:TYPE:...}` patterns.
- Changed visual feedback from text indicator (span) to **background color** on the input field: green `#d4edda`, amber `#fff3cd`, red `#f8d7da`. Sets `aria-invalid` and `title` for accessibility.
- Added inline `style.backgroundColor` via JS as fallback for CSS specificity issues (gapfill context).
- Removed `head_code()` — CSS is now inline in `controls()` via static flag (output once per request). Fixes "Cannot require CSS after \<head\>" error in embed question filter.
- Fixed AMD module loading: uses both `js_call_amd()` (standard queue) and deferred inline `<script>` with retry (polls for `require` every 50ms, up to 1s) for preview and embed contexts.
- Fixed gapfill cross-contamination: `get_question_answers()` now only returns answers for `subN_` fields; gapfill uses per-field `fallback_hash()`.
- Added case-insensitive (SA) support: `$subq->usecase == 0` triggers `ci` flag — server lowercases answer before hashing, JS lowercases user input before comparing.
- Added Formulas (`qtype_formulas`) tolerance extraction via `get_formulas_data()`: detects `$question->parts`, parses `_err < X` (absolute) and `_relerr < X`×100 (percentage) from grading criterion, creates `n` tolerance entries.
- Added decimal comma handling: `fallback_hash()` also hashes swapped variant (`3.14`↔`3,14`) for decimal strings.
- No custom backup needed — plugin uses no custom database tables; standard question engine tables are backed up automatically.

### In Progress
- (none)

### Blocked
- (none)

## Key Decisions
- **Client-side salted hash approach**: embed salted HMAC-SHA256 hashes of correct answers as `data-pergap-hashes` attribute; JS hashes student input client-side and compares. Zero extra server requests after page load.
- **Salt = `userid|usageid|slot`**: per-user, per-attempt, per-slot uniqueness prevents cross-user hash reuse.
- **Structured data format**: each field entry has `h` (full credit hashes), `p` (partial credit hashes), `n` (numerical tolerance entries with value/tolerance/type/fraction), `ci` (case-insensitive flag).
- **Priority chain**: `$question->subquestions` (cloze) → `$question->parts` (formulas) → `get_correct_response()` + `fallback_hash()` (gapfill, other).
- **Formulas tolerance parsing**: only simple patterns `_err < X` and `_relerr < X` are parsed; complex multi-condition expressions fall back to exact hash.
- **Numerical tolerance**: reveal correct value + tolerance client-side (user accepted this trade-off).
- **Background color visualization**: input background color changes (green/amber/red) instead of a separate indicator span — preserves text flow. Backed by `aria-invalid` and `title` for accessibility.
- **AMD loading**: both `js_call_amd()` and deferred inline `<script>` with retry — covers quiz, preview, and embed contexts.
- **Case-insensitive matching**: for SA (`usecase=0`), both server and client lowercase before hashing — covers all case variations including Unicode (ä, è, ß).
- **Decimal comma**: server stores hash of both `3.14` and `3,14` for numeric strings — covers English and German locale formats.

## Next Steps
- Write internal PHPDoc documentation for all classes and methods.
- Clean up stale test methods (those testing removed `parse_cloze_text`).
- Monitor for edge cases with complex Formulas grading criteria (multi-condition expressions).

## Critical Context
- `$answers` property is **public** on all subquestion types (`qtype_shortanswer_question`, `qtype_numerical_question`, `qtype_multichoice_*`) — direct `isset($subq->answers)` works.
- Subquestions in multianswer are keyed 1-based (matching place numbers `{#1}`, `{#2}`), not 0-based.
- Question text uses `{#N}` placeholders, not `{N:TYPE:...}` — text-based parsing never works.
- Formulas stores per-part data in `$question->parts[]` with `evaluatedanswers`, `correctness` (grading criterion), `numbox`, `partindex`.
- `get_formulas_data()` detects Formulas via `isset($question->parts)` (duck-typing, not class name).
- The behaviour stores no data in custom database tables — only standard `question_attempt_step_data`. No backup/restore implementation needed.
- No external JS libraries loaded — only browser-native APIs (`crypto.subtle`, `TextEncoder`) and Moodle's built-in `core/notification` AMD module.

## Relevant Files
- `question/behaviour/gapcheck/renderer.php`: Main extraction logic — `get_answer_data()` orchestrates priority chain (subquestions → formulas → `get_correct_response()`). `get_formulas_data()` extracts tolerance from `_err`/`_relerr`. `process_answer_rows()` builds structured `{h, p, n, ci}` entries. `fallback_hash()` handles pipe-delimited alternatives + decimal comma.
- `question/behaviour/gapcheck/amd/src/gapcheck.js`: Client-side AMD module — handles structured `{h, p, n, ci}` format, numerical tolerance with `checkNumerical()`, background color feedback via `setInputState()`, case-insensitive hashing.
- `question/behaviour/gapcheck/amd/build/gapcheck.min.js`: Sync'd copy of source (not minified, module name added to `define()`).
- `question/behaviour/gapcheck/styles.css`: Class-only selectors `.gapcheck-correct`, `.gapcheck-partial`, `.gapcheck-incorrect` for background colors.
- `question/behaviour/gapcheck/behaviour.php`: Behaviour class with `get_salt()` static method, compatibility check for `question_automatically_gradable`.
- `question/behaviour/gapcheck/behaviourtype.php`: Returns `is_archetypal() = true` to appear in behaviour dropdown.
- `question/behaviour/gapcheck/tests/behaviour_test.php`: Tests for behaviour grading, hash consistency, partial credit grouping, numerical tolerance detection (includes stale `parse_cloze_text_fake` methods).
