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
 * Behaviour type definition for gapcheck.
 *
 * Registers this behaviour as archetypal (selectable in the UI
 * and listed in the preview dropdown) and configures its
 * interaction model.
 *
 * @package   qbehaviour_gapcheck
 * @copyright 2026 Matthias Giger
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_gapcheck_type extends question_behaviour_type {
    /**
     * Whether this behaviour can be selected in the UI.
     *
     * @return bool
     */
    public function is_archetypal() {
        return true;
    }

    /**
     * Whether the attempt may finish during the attempt.
     *
     * @return bool
     */
    public function can_questions_finish_during_the_attempt() {
        return true;
    }

    /**
     * The display options that this behaviour does not use.
     *
     * @return array the unused display options
     */
    public function get_unused_display_options() {
        return [];
    }
}
