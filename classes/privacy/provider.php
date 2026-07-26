<?php

namespace qbehaviour_gapcheck\privacy;

defined('MOODLE_INTERNAL') || die();

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

    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
