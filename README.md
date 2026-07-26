# Gap Check — instant per-gap visual feedback for Moodle quizzes

A question behaviour plugin for Moodle 5.x that gives students immediate visual feedback (coloured background + border) on each individual gap as they type, select, or drag answers — **without additional server requests**.

**This question behaviour should not be used for summative tests!**

## How it works

1. When the question is rendered, the server computes salted HMAC-SHA256 hashes of all correct answer alternatives and embeds them as a JSON data attribute. The salt (`userid|usageid|slot`) is unique per user, attempt, and question slot. On insecure HTTP the module silently skips — `crypto.subtle` is unavailable, no error is thrown, and no feedback is shown.
2. The client-side JavaScript hashes the student's input and compares it against the embedded hashes. Validation fires on `blur`, `change`, and debounced `input` (400 ms). Programmatic value writes (gapfill drag-and-drop, JS `.value = ...`) are intercepted server-side via a single `HTMLInputElement.prototype.value` setter + `WeakMap<Element, {data, salt}>` lookup — no per-element descriptors needed. `<select>` elements are also validated on `change`.
3. CSS classes are applied to the input field — `.gapcheck-correct` (green), `.gapcheck-partial` (amber), `.gapcheck-incorrect` (red). No inline styles; all appearance is controlled via CSS custom properties. The renderer also injects an inline `<style>` block (via a static flag) so the default styles work in every context — preview, embed, and quiz — without depending on `styles.css` loading order.

No data is sent back to the server for the visual check. The server never knows whether the student saw green, amber, or red — the behaviour only grades the attempt when the student submits.

## Supported question types

| Question type | Status | Details |
|---|---|---|
| **Cloze (embedded answers)** | Full | Short answer, numerical, multichoice single (MC/MCS/MCV/MCH) subquestions. MCS uses `get_correct_response()` which returns the correct position in the shuffled order. Matching and multichoice multi are not supported. |
| **Gapfill** (`qtype_gapfill`) | Full | Drag-and-drop and text input gaps. Programmatic value changes are intercepted. |
| **Formulas** (`qtype_formulas`) | Full | Multi-field numerical answers with absolute/relative tolerance from the grading criterion. |
| **Short answer** (standalone) | Full | Case-sensitive and case-insensitive. Pipe-delimited alternatives. |
| **Numerical** (standalone) | Full | With tolerance. |
| **True/False** | Partial | Correct/incorrect indicator via `get_correct_response()`. |
| **Matching** | Not supported | Select elements are left unvalidated. |
| **Multichoice multi** (checkboxes) | Not supported | The value-based hashing approach is incompatible with checkbox-style multiple selection. |
| **Drag-and-drop markers** | Not supported | Hidden input fields are not in the selector. |

## Appearance

All visual feedback is controlled via CSS custom properties. Default colours and borders are defined in `styles.css` and as inline `<style>` injected by the renderer.

### Override via Additional HTML

Paste this into **Site administration → Appearance → Additional HTML → Within HEAD**:

```css
:root {
    --gapcheck-correct: #a8e6cf;
    --gapcheck-partial: #ffd3b6;
    --gapcheck-incorrect: #ff8b94;
    --gapcheck-border-correct: 3px dotted #00cc66;
    --gapcheck-border-partial: 2px dashed #e6a800;
    --gapcheck-border-incorrect: 1px solid #cc0000;
    --gapcheck-outline: none;
}
```

This works because the plugin's CSS uses `var(--gapcheck-correct, #d4edda) !important`. Your `:root` values take priority over the fallback defaults but the `!important` flag still beats theme/question-type input styling.

### Per-item colours

Set colours per item or per section by scoping to a specific quiz or course:

```css
.quiz-1234 :root {
    --gapcheck-correct: #b8e6b8;
}
```

## Accessibility

When feedback is shown, the input's `aria-invalid` attribute is set to `"false"` for correct/partial and `"true"` for incorrect. A tooltip (`title`) is also set on each input, drawing translatable text from the language strings `correct`, `partiallycorrect`, and `incorrect`. Text colour and contrast remain the student's theme default — only background colour and border change.

## Settings

No plugin settings page. The behaviour can be selected per quiz or set as default via **Site administration → Plugins → Question behaviours → Manage question behaviours**.

## Debug mode

Open the browser console and run:

```js
window.gapcheckDebug = true;
// Then reload the quiz page, or re-initialise:
require(['qbehaviour_gapcheck/gapcheck'], function(m) { m.setDebug(true); });
```

With debug enabled, every hash comparison is logged to the console showing the field name, input value, computed hash, and the full/partial hash lists.

## Privacy

This plugin stores no personal data and uses no custom database tables (`\core_privacy\local\metadata\null_provider`). It embeds salted hashes of correct answers in the page HTML, which are discarded when the page is unloaded. The salt uses the user ID, attempt usage ID, and question slot — all of which are already part of normal Moodle operation.

## Security

| Posture | Value |
|---|---|
| MDL Shield grade | C |
| Critical findings | 0 |
| High findings | 1 (answer disclosure by design — excluded per review) |
| Medium findings | 0 |
| Low findings | 4 (all fixed) |

The plugin never exposes plaintext correct answers beyond what the question type already provides for its own rendering. Only salted HMAC-SHA256 hashes are embedded in the page. The server never persists the student's per-gap visual results — grading only occurs on submit/finish.

## Requirements

- Moodle 5.0 or later
- HTTPS (required by the Web Crypto API — `crypto.subtle` is not available over plain HTTP)

## Installation

1. Copy the `gapcheck` folder into `question/behaviour/`.
2. Visit the Site administration notification page to trigger the plugin installation.
3. Select "Gap check" as the behaviour in your quiz settings.

## License

GNU GPL v3 or later.
