<?php

defined('MOODLE_INTERNAL') || die();

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
    const IS_ARCHETYPAL = true;

    public function is_compatible_question(question_definition $question) {
        return $question instanceof question_automatically_gradable;
    }

    public function can_finish_during_attempt() {
        return true;
    }

    public function get_min_fraction() {
        return $this->question->get_min_fraction();
    }

    public function get_expected_data() {
        if ($this->qa->get_state()->is_active()) {
            return ['submit' => PARAM_BOOL];
        }
        return parent::get_expected_data();
    }

    public function get_state_string($showcorrectness) {
        $state = $this->qa->get_state();
        if ($state == question_state::$todo) {
            return get_string('notcomplete', 'qbehaviour_gapcheck');
        }
        return parent::get_state_string($showcorrectness);
    }

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

    public function process_submit(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        if (!$this->is_complete_response($pendingstep)) {
            $pendingstep->set_state(question_state::$invalid);
        } else {
            $response = $pendingstep->get_qt_data();
            list($fraction, $state) = $this->question->grade_response($response);
            $pendingstep->set_fraction($fraction);
            $pendingstep->set_state($state);
            $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        }
        return question_attempt::KEEP;
    }

    public function process_finish(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        $response = $this->qa->get_last_step()->get_qt_data();
        if (!$this->question->is_gradable_response($response)) {
            $pendingstep->set_state(question_state::$gaveup);
        } else {
            list($fraction, $state) = $this->question->grade_response($response);
            $pendingstep->set_fraction($fraction);
            $pendingstep->set_state($state);
        }
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        return question_attempt::KEEP;
    }

    public function process_save(question_attempt_pending_step $pendingstep) {
        $status = parent::process_save($pendingstep);
        if ($status == question_attempt::KEEP &&
                $pendingstep->get_state() == question_state::$complete) {
            $pendingstep->set_state(question_state::$todo);
        }
        return $status;
    }
}
