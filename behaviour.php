<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Question behaviour that provides per-gap instant visual feedback
 * without grading or revealing the correct answer.
 *
 * Feedback is rendered client-side by comparing the student's input
 * against salted HMAC-SHA256 hashes of the correct answers, embedded
 * in the page as a data attribute. The server never reveals the
 * plaintext correct answers.
 *
 * Supported question types:
 *   - Cloze (multianswer) — including SA/SAC case sensitivity and NM tolerance
 *   - Gapfill (qtype_gapfill) — individual gap checking
 *   - Formulas (qtype_formulas) — extracted tolerance from grading criterion
 *
 * @package   qbehaviour_gapcheck
 * @copyright 2026 Matthias Giger
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_gapcheck extends question_behaviour_with_save {
    /**
     * Whether this behaviour is archetypal, i.e. selectable in the UI
     * and listed in the behaviour preview dropdown.
     *
     * @var bool
     */
    public const IS_ARCHETYPAL = true;

    /**
     * Check whether this behaviour is compatible with a question.
     *
     * Only automatically gradable questions are supported, since the
     * feedback is computed against hashes of the correct answers.
     *
     * @param question_definition $question the question to check
     * @return bool whether the question is compatible
     */
    public function is_compatible_question(question_definition $question) {
        return $question instanceof question_automatically_gradable;
    }

    /**
     * Whether the attempt can be finished during the attempt.
     *
     * @return bool
     */
    public function can_finish_during_attempt() {
        return true;
    }

    /**
     * The minimum possible fraction for a question using this behaviour.
     *
     * @return float the minimum fraction
     */
    public function get_min_fraction() {
        return $this->question->get_min_fraction();
    }

    /**
     * The data expected to be submitted in the current state.
     *
     * @return array of name => PARAM_... constants
     */
    public function get_expected_data() {
        if ($this->qa->get_state()->is_active()) {
            return ['submit' => PARAM_BOOL];
        }
        return parent::get_expected_data();
    }

    /**
     * A human-readable description of the current state.
     *
     * Saved-but-unsubmitted attempts are reported as 'not complete'
     * so that the question does not appear finished.
     *
     * @param bool $showcorrectness whether to show correctness information
     * @return string the state description
     */
    public function get_state_string($showcorrectness) {
        $state = $this->qa->get_state();
        if ($state == question_state::$todo) {
            return get_string('notcomplete', 'qbehaviour_gapcheck');
        }
        return parent::get_state_string($showcorrectness);
    }

    /**
     * A summary of the correct answer.
     *
     * @return string the right answer summary
     */
    public function get_right_answer_summary() {
        return $this->question->get_right_answer_summary();
    }

    /**
     * Generate a unique salt for HMAC hashing.
     *
     * The salt is a pipe-delimited string combining the user ID,
     * the question usage ID, and the slot number. This ensures that
     * the same correct answer produces a different hash for every
     * user, attempt, and question position, preventing hash reuse
     * across sessions.
     *
     * @param question_attempt $qa the current question attempt
     * @return string salt in format "userid|usageid|slot"
     */
    public static function get_salt(question_attempt $qa): string {
        global $USER;
        return $USER->id . '|' . $qa->get_usage_id() . '|' . $qa->get_slot();
    }

    /**
     * Dispatch an action to one of the process_* methods.
     *
     * @param question_attempt_pending_step $pendingstep the steps that would
     *      be updated if the action is approved
     * @return bool question_attempt::KEEP or question_attempt::DISCARD
     */
    public function process_action(question_attempt_pending_step $pendingstep) {
        if ($pendingstep->has_behaviour_var('comment')) {
            return $this->process_comment($pendingstep);
        } else if ($pendingstep->has_behaviour_var('submit')) {
            return $this->process_submit($pendingstep);
        } else if ($pendingstep->has_behaviour_var('finish')) {
            return $this->process_finish($pendingstep);
        } else {
            return $this->process_save($pendingstep);
        }
    }

    /**
     * Summarise the action taken by the student.
     *
     * @param question_attempt_step $step the step to summarise
     * @return string the summary
     */
    public function summarise_action(question_attempt_step $step) {
        if ($step->has_behaviour_var('comment')) {
            return $this->summarise_manual_comment($step);
        } else if ($step->has_behaviour_var('finish')) {
            return $this->summarise_finish($step);
        } else if ($step->has_behaviour_var('submit')) {
            return $this->summarise_submit($step);
        } else {
            return $this->summarise_save($step);
        }
    }

    /**
     * Process the 'submit' action.
     *
     * The response is graded immediately so that the per-gap feedback
     * could be shown, but the grade is only stored, never revealed.
     *
     * @param question_attempt_pending_step $pendingstep the steps the update
     *     would apply to
     * @return bool question_attempt::KEEP or question_attempt::DISCARD
     */
    public function process_submit(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        if (!$this->is_complete_response($pendingstep)) {
            $pendingstep->set_state(question_state::$invalid);
        } else {
            $response = $pendingstep->get_qt_data();
            [$fraction, $state] = $this->question->grade_response($response);
            $pendingstep->set_fraction($fraction);
            $pendingstep->set_state($state);
            $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        }
        return question_attempt::KEEP;
    }

    /**
     * Process the 'finish' action.
     *
     * Answers never submitted are graded at the end of the attempt,
     * or the attempt is recorded as given up if no answer was provided.
     *
     * @param question_attempt_pending_step $pendingstep the steps the update
     *     would apply to
     * @return bool question_attempt::KEEP or question_attempt::DISCARD
     */
    public function process_finish(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        $response = $this->qa->get_last_step()->get_qt_data();
        if (!$this->question->is_gradable_response($response)) {
            $pendingstep->set_state(question_state::$gaveup);
        } else {
            [$fraction, $state] = $this->question->grade_response($response);
            $pendingstep->set_fraction($fraction);
            $pendingstep->set_state($state);
        }
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        return question_attempt::KEEP;
    }

    /**
     * Process a 'save' action.
     *
     * A saved response is deliberately set to the 'todo' state, so that
     * the attempt is not considered complete until the student explicitly
     * submits (or the attempt ends).
     *
     * @param question_attempt_pending_step $pendingstep the steps the update
     *     would apply to
     * @return bool question_attempt::KEEP or question_attempt::DISCARD
     */
    public function process_save(question_attempt_pending_step $pendingstep) {
        $status = parent::process_save($pendingstep);
        if (
            $status == question_attempt::KEEP &&
            $pendingstep->get_state() == question_state::$complete
        ) {
            $pendingstep->set_state(question_state::$todo);
        }
        return $status;
    }
}
