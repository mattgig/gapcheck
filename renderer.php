<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Renderer for the gapcheck behaviour.
 *
 * Generates a hidden div containing per-gap salted HMAC-SHA256 hashes
 * of all correct answer alternatives. The client-side AMD module
 * reads this data, hashes the student's input on each keystroke,
 * and applies background-color feedback (green/amber/red) without
 * any additional server requests.
 *
 * The answer data is extracted in the following priority order:
 *   1. Cloze subquestions ($question->subquestions) — SA, NM, MC
 *   2. Formulas question parts ($question->parts) — with tolerance
 *   3. get_correct_response() + fallback_hash() — gapfill, others
 *
 * Override colors and borders without touching plugin code.
 *    Add this to Site administration → Appearance → Additional HTML (Within HEAD):
 *
 *    :root {
 *        --gapcheck-correct: #a8e6cf;
 *        --gapcheck-partial: #ffd3b6;
 *        --gapcheck-incorrect: #ff8b94;
 *        --gapcheck-border-correct: 3px dotted #00cc66;
 *        --gapcheck-border-partial: 2px dashed #e6a800;
 *        --gapcheck-border-incorrect: 1px solid #cc0000;
 *    }
 *
 *    Default borders: correct=solid, partial=dashed, incorrect=dotted.
 *    The CSS rules use !important to win against question-type or theme
 *    input styling. Custom properties fall back to the built-in defaults,
 *    so your :root declarations take effect regardless of specificity.
 *    Works in quiz, preview, and embed contexts.
 *
 * @package   qbehaviour_gapcheck
 * @copyright 2026 Matthias Giger
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_gapcheck_renderer extends qbehaviour_renderer {
    public function controls(question_attempt $qa, question_display_options $options) {
        if ($options->readonly) {
            return '';
        }

        static $cssadded = false;
        $output = '';
        if (!$cssadded) {
            $cssadded = true;
            $output .= html_writer::tag('style',
                '.gapcheck-correct{background-color:var(--gapcheck-correct,#d4edda)!important;'
                . 'border:var(--gapcheck-border-correct,2px solid #28a745)!important;'
                . 'outline:var(--gapcheck-outline,none)!important}'
                . '.gapcheck-partial{background-color:var(--gapcheck-partial,#fff3cd)!important;'
                . 'border:var(--gapcheck-border-partial,2px dashed #ffc107)!important;'
                . 'outline:var(--gapcheck-outline,none)!important}'
                . '.gapcheck-incorrect{background-color:var(--gapcheck-incorrect,#f8d7da)!important;'
                . 'border:var(--gapcheck-border-incorrect,2px dotted #dc3545)!important;'
                . 'outline:var(--gapcheck-outline,none)!important}'
            );
        }

        $question = $qa->get_question();
        $data = $this->get_answer_data($qa);

        $output .= html_writer::tag('div', '', [
            'class' => 'pergapcheck-hashmap d-none',
            'data-pergap-hashes' => json_encode($data),
            'data-pergap-salt' => qbehaviour_gapcheck::get_salt($qa),
            'data-gapcheck-label-correct' => get_string('correct', 'qbehaviour_gapcheck'),
            'data-gapcheck-label-partial' => get_string('partiallycorrect', 'qbehaviour_gapcheck'),
            'data-gapcheck-label-incorrect' => get_string('incorrect', 'qbehaviour_gapcheck'),
        ]);

        $this->page->requires->js_call_amd(
            'qbehaviour_gapcheck/gapcheck',
            'init',
            [$qa->get_outer_question_div_unique_id()]
        );

        $idjson = json_encode($qa->get_outer_question_div_unique_id());
        $output .= html_writer::script(
            '(function(){var i=0,f=function(){require(["qbehaviour_gapcheck/gapcheck"],'
            . 'function(m){m.init(' . $idjson . ')})};'
            . 'if(typeof require!=="undefined"){f()}else{var t=setInterval(function(){'
            . 'if(++i>20){clearInterval(t)}'
            . 'if(typeof require!=="undefined"){clearInterval(t);f()}},50)}})();'
        );

        $output .= $this->submit_button($qa, $options);
        return $output;
    }

    /**
     * Extract per-gap answer data for the current question attempt.
     *
     * Priority chain:
     *   1. Cloze subquestions — accesses $question->subquestions[N]->answers
     *      for field names like "sub1_answer". Detects case sensitivity
     *      via $subq->usecase (0 = case-insensitive).
     *   2. Formulas parts — accesses $question->parts to extract evaluated
     *      answers and tolerance from the grading criterion expression.
     *   3. Fallback — uses get_correct_response() and fallback_hash()
     *      for question types without subquestion/parts structure.
     *
     * @param question_attempt $qa the current question attempt
     * @return array associative array keyed by prefixed field name,
     *               each value a structured entry with 'h', 'p', 'n', 'ci' keys
     */
    private function get_answer_data(question_attempt $qa): array {
        $question = $qa->get_question();
        $salt = qbehaviour_gapcheck::get_salt($qa);
        $data = [];

        if (isset($question->subquestions) && is_array($question->subquestions)) {
            foreach ($question->subquestions as $i => $subq) {
                $fieldname = 'sub' . $i . '_answer';
                $prefixed = $qa->get_qt_field_name($fieldname);
                $answers = $subq->answers ?? [];
                if (count($answers) > 0) {
                    $ci = isset($subq->usecase) && $subq->usecase == 0;
                    if ($subq instanceof qtype_multichoice_single_question) {
                        $c = $subq->get_correct_response();
                        if (isset($c['answer'])) {
                            $h = hash_hmac('sha256', (string)$c['answer'], $salt);
                            $data[$prefixed]['h'][] = $h;
                        }
                        $orderstr = $qa->get_step(0)->get_qt_var('sub' . $i . '_order');
                        if ($orderstr) {
                            $answerorder = explode(',', $orderstr);
                        } else {
                            $answerorder = array_keys($subq->answers ?? []);
                        }
                        foreach ($answerorder as $idx => $ansid) {
                            if (!isset($subq->answers[$ansid])) {
                                continue;
                            }
                            $ans = $subq->answers[$ansid];
                            $fraction = (float)$ans->fraction;
                            if ($fraction > 0 && $fraction < 1.0) {
                                $h = hash_hmac('sha256', (string)$idx, $salt);
                                $data[$prefixed]['p'][] = $h;
                            }
                        }
                    } else {
                        $data[$prefixed] = $this->process_answer_rows($answers, $salt, $ci);
                    }
                }
            }
            if (!empty($data)) {
                return $data;
            }
        }

        if (isset($question->parts) && is_array($question->parts)) {
            $formulas = $this->get_formulas_data($qa, $question, $salt);
            if ($formulas !== null) {
                return $formulas;
            }
        }

        $correct = $question->get_correct_response();
        if (empty($correct)) {
            return [];
        }

        $singlefield = count($correct) === 1;
        foreach ($correct as $fieldname => $correctvalue) {
            $prefixed = $qa->get_qt_field_name($fieldname);
            $answerrows = $this->get_question_answers($question, $fieldname);
            if (!empty($answerrows)) {
                $data[$prefixed] = $this->process_answer_rows($answerrows, $salt);
            } elseif ($singlefield && isset($question->answers) && is_array($question->answers) && count($question->answers) > 0) {
                $ci = isset($question->usecase) && $question->usecase == 0;
                $data[$prefixed] = $this->process_answer_rows($question->answers, $salt, $ci);
            } else {
                $data[$prefixed] = $this->fallback_hash($correctvalue, $salt);
            }
        }

        return $data;
    }

    /**
     * Retrieve answer rows for a specific field from the question object.
     *
     * Only returns data for cloze-style subquestion fields
     * (matching "subN_" pattern). All other field types (gapfill
     * "pN", Formulas "N_M", etc.) return empty so the fallback
     * path uses get_correct_response() per-field values.
     *
     * @param object $question the question definition object
     * @param string $fieldname the raw field name (e.g. "sub1_answer")
     * @return array of question_answer objects, or empty array
     */
    private function get_question_answers(object $question, string $fieldname): array {
        if (preg_match('/^sub(\d+)_/', $fieldname, $m)) {
            $idx = (int)$m[1];
            $subq = $this->get_subquestion($question, $idx);
            if ($subq && isset($subq->answers)) {
                return $subq->answers;
            }
        }

        return [];
    }

    /**
     * Retrieve a subquestion by its 1-based place number.
     *
     * Subquestion array keys correspond to the place numbers
     * from the cloze text {#1}, {#2}, etc., NOT 0-based indices.
     *
     * @param object $question the multianswer question definition
     * @param int $idx the place number (1, 2, 3, ...)
     * @return object|null the subquestion object, or null
     */
    private function get_subquestion(object $question, int $idx) {
        if (isset($question->subquestions) && is_array($question->subquestions) && isset($question->subquestions[$idx])) {
            return $question->subquestions[$idx];
        }
        return null;
    }

    /**
     * Extract per-part answer data for Formulas questions.
     *
     * Detected via duck-typing on $question->parts. For each
     * part, reads the evaluated numerical answers and parses
     * the grading criterion expression for tolerance limits.
     *
     * Supported criterion patterns:
     *   _err < X    → absolute tolerance X
     *   _relerr < X → percentage tolerance X*100
     *
     * Unknown or complex expressions fall back to exact hashing
     * (no tolerance entry).
     *
     * @param question_attempt $qa the current question attempt
     * @param object $question the question definition object
     * @param string $salt the HMAC salt
     * @return array|null structured entries keyed by prefixed field name,
     *                    or null if not a Formulas question
     */
    private function get_formulas_data(question_attempt $qa, object $question, string $salt): ?array {
        if (!isset($question->parts) || !is_array($question->parts)) {
            return null;
        }
        $data = [];
        foreach ($question->parts as $part) {
            $evaluated = $part->evaluatedanswers ?? [];
            $correctness = $part->correctness ?? '';
            $numbox = (int)($part->numbox ?? 1);
            $partindex = $part->partindex ?? 0;

            $tolerance = 0;
            $tolerancetype = 0;
            if (preg_match('/_relerr\s*<[=]?\s*(\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/', $correctness, $m)) {
                $tolerance = (float)$m[1] * 100;
                $tolerancetype = 1;
            } elseif (preg_match('/_err\s*<[=]?\s*(\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/', $correctness, $m)) {
                $tolerance = (float)$m[1];
                $tolerancetype = 0;
            }

            for ($b = 0; $b < $numbox; $b++) {
                $fieldname = $partindex . '_' . $b;
                $prefixed = $qa->get_qt_field_name($fieldname);
                $value = $evaluated[$b] ?? null;
                if ($value === null) {
                    continue;
                }
                $valuestr = (string)$value;

                $entry = ['h' => [hash_hmac('sha256', $valuestr, $salt)]];
                if ($tolerance > 0) {
                    $entry['n'] = [['v' => $valuestr, 't' => $tolerance, 'k' => $tolerancetype, 'f' => 1.0]];
                }
                $data[$prefixed] = $entry;
            }
        }
        return $data;
    }

    /**
     * Convert question_answer objects into a structured hash entry.
     *
     * For each answer row, the method:
     *   1. Skips zero/negative fractions and wildcard/empty answers
     *   2. Splits pipe-delimited alternatives (|) and hashes each
     *   3. For CI mode, lowercases via core_text::strtolower()
     *   4. Extracts tolerance info (numerical questions)
     *   5. Separates full-credit (h) and partial-credit (p) hashes
     *
     * @param array $answerrows array of question_answer objects
     * @param string $salt the HMAC salt
     * @param bool $ci if true, lowercase answers before hashing
     * @return array structured entry with 'h', 'p', 'n', 'ci' keys
     */
    private function process_answer_rows(array $answerrows, string $salt, bool $ci = false): array {
        $entry = ['h' => [], 'p' => [], 'n' => []];

        foreach ($answerrows as $row) {
            $fraction = (float)$row->fraction;
            if ($fraction <= 0) {
                continue;
            }

            $answertext = $row->answer;
            if ($answertext === '*' || $answertext === '') {
                continue;
            }

            $alternatives = array_filter(array_map('trim', explode('|', $answertext)), 'strlen');
            if (empty($alternatives)) {
                continue;
            }

            $hashes = [];
            foreach ($alternatives as $alt) {
                if ($ci) {
                    $alt = core_text::strtolower($alt);
                }
                $hashes[] = hash_hmac('sha256', $alt, $salt);
            }

            $tolerance = isset($row->tolerance) ? (float)$row->tolerance : 0;
            $tolerancetype = isset($row->tolerancetype) ? (int)$row->tolerancetype : 0;

            if ($tolerance > 0 && is_numeric($answertext)) {
                $entry['n'][] = [
                    'v' => $answertext,
                    't' => $tolerance,
                    'k' => $tolerancetype,
                    'f' => $fraction,
                ];
            }

            if ($fraction >= 1.0) {
                $entry['h'] = array_merge($entry['h'], $hashes);
            } else {
                $entry['p'] = array_merge($entry['p'], $hashes);
            }
        }

        $entry['h'] = array_values(array_unique($entry['h']));
        $entry['p'] = array_values(array_unique($entry['p']));

        if ($ci) {
            $entry['ci'] = true;
        }

        if (empty($entry['n'])) {
            unset($entry['n']);
        }
        if (empty($entry['p'])) {
            unset($entry['p']);
        }

        return $entry;
    }

    /**
     * Hash a correct answer value using the fallback path.
     *
     * Used when neither cloze subquestions nor Formulas parts are
     * available. Splits pipe-delimited alternatives (|), hashes each,
     * and also hashes the decimal-comma swapped form for numeric
     * values (e.g. "3.14" → hash of "3.14" and "3,14").
     *
     * @param string $correctvalue the correct answer string
     * @param string $salt the HMAC salt
     * @return array structured 'h'-only entry
     */
    private function fallback_hash(string $correctvalue, string $salt): array {
        $alternatives = array_filter(array_map('trim', explode('|', $correctvalue)), 'strlen');
        $hashes = [];
        foreach ($alternatives as $alt) {
            $hashes[] = hash_hmac('sha256', $alt, $salt);
            if (preg_match('/^\d+[.,]\d+$/', $alt)) {
                $hashes[] = hash_hmac('sha256', strtr($alt, ['.' => ',', ',' => '.']), $salt);
            }
        }
        return ['h' => array_values(array_unique($hashes))];
    }
}