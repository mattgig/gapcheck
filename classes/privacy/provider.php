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

namespace qbehaviour_gapcheck\privacy;

/**
 * Privacy provider for the gapcheck behaviour.
 *
 * This plugin stores no personally identifiable user data.
 * All comparison data (salted hashes) is embedded in the page
 * HTML and never persisted to custom database tables.
 *
 * @package   qbehaviour_gapcheck
 * @copyright 2026 Matthias Giger
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\null_provider {
    /**
     * The string identifier of the reason why this plugin stores no data.
     *
     * @return string the language string identifier
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
