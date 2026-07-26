<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Behaviour type definition for gapcheck.
 *
 * Registers this behaviour as archetypal (selectable in the UI
 * and listed in the preview dropdown) and configures its
 * interaction model.
 *
 * @package   qbehaviour_gapcheck
 * @copyright 2026 Your Name
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_gapcheck_type extends question_behaviour_type {
    public function is_archetypal() {
        return true;
    }

    public function can_questions_finish_during_the_attempt() {
        return true;
    }

    public function get_unused_display_options() {
        return [];
    }
}
