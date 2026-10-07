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

namespace qbehaviour_gapcheck;

use qbehaviour_gapcheck;
use qbehaviour_walkthrough_test_base;
use qtype_numerical_answer;
use question_answer;
use question_bank;
use question_display_options;
use question_state;
use ReflectionClass;
use test_question_maker;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/tests/helpers.php');

/**
 * Unit tests for the gapcheck question behaviour.
 *
 * Tests cover:
 *   - Standard behaviour transitions (submit, save, finish)
 *   - Salt consistency and hash determinism
 *   - Structured hash data output from process_answer_rows()
 *   - Numerical tolerance detection in process_answer_rows()
 *   - Pipe-delimited fallback_hash() handling
 *   - Wildcard/empty answer skipping in process_answer_rows()
 *
 * @covers \qbehaviour_gapcheck
 * @covers \qbehaviour_gapcheck_renderer
 * @package   qbehaviour_gapcheck
 * @copyright 2026 Matthias Giger
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class behaviour_test extends qbehaviour_walkthrough_test_base {
    public function test_submit_correct_answer(): void {
        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 'gapcheck');

        $this->check_current_state(question_state::$todo);
        $this->check_current_output(
            $this->get_contains_submit_button_expectation()
        );

        $this->process_submission(['answer' => 'frog', '-submit' => 1]);
        $this->check_current_state(question_state::$gradedright);
        $this->check_current_output(
            $this->get_does_not_contain_submit_button_expectation()
        );
    }

    public function test_submit_wrong_answer(): void {
        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 'gapcheck');

        $this->process_submission(['answer' => 'cat', '-submit' => 1]);
        $this->check_current_state(question_state::$gradedwrong);
    }

    public function test_submit_partial_answer(): void {
        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 'gapcheck');

        $this->process_submission(['answer' => 'toad', '-submit' => 1]);
        $this->check_current_state(question_state::$gradedpartial);
    }

    public function test_submit_incomplete(): void {
        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 'gapcheck');

        $this->process_submission(['answer' => '', '-submit' => 1]);
        $this->check_current_state(question_state::$invalid);
        $this->check_current_output(
            $this->get_contains_submit_button_expectation()
        );
    }

    public function test_save_then_submit(): void {
        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 'gapcheck');

        $this->process_submission(['answer' => 'frog']);
        $this->check_current_state(question_state::$todo);

        $this->process_submission(['answer' => 'frog', '-submit' => 1]);
        $this->check_current_state(question_state::$gradedright);
    }

    public function test_finish_without_submit(): void {
        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 'gapcheck');

        $this->process_submission(['answer' => 'frog']);
        $this->quba->finish_all_questions();

        $this->check_current_state(question_state::$gradedright);
    }

    public function test_finish_without_answer(): void {
        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 'gapcheck');

        $this->quba->finish_all_questions();

        $this->check_current_state(question_state::$gaveup);
    }

    public function test_get_salt_consistency(): void {
        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 'gapcheck');

        $qa = $this->get_question_attempt();
        $salt1 = qbehaviour_gapcheck::get_salt($qa);
        $salt2 = qbehaviour_gapcheck::get_salt($qa);

        $this->assertEquals($salt1, $salt2);
    }

    public function test_process_answer_rows_full_and_partial(): void {
        global $PAGE;
        $salt = '1|99|1';

        $row1 = new question_answer(0, 'Paris', 1.0, '', FORMAT_HTML);
        $row2 = new question_answer(0, 'paris', 0.5, '', FORMAT_HTML);
        $row3 = new question_answer(0, 'Paris, France', 1.0, '', FORMAT_HTML);
        $rows = [$row1, $row2, $row3];

        $renderer = $PAGE->get_renderer('qbehaviour_gapcheck');
        $rc = new ReflectionClass($renderer);
        $method = $rc->getMethod('process_answer_rows');
        $method->setAccessible(true);
        $entry = $method->invoke($renderer, $rows, $salt);

        $this->assertCount(2, $entry['h']);
        $this->assertCount(1, $entry['p']);
        $this->assertContains(hash_hmac('sha256', 'Paris', $salt), $entry['h']);
        $this->assertContains(hash_hmac('sha256', 'Paris, France', $salt), $entry['h']);
        $this->assertContains(hash_hmac('sha256', 'paris', $salt), $entry['p']);
    }

    public function test_process_answer_rows_numerical_tolerance(): void {
        global $PAGE;
        question_bank::load_question_definition_classes('numerical');
        $salt = '1|99|1';

        $row1 = new qtype_numerical_answer(0, '3.14', 1.0, '', FORMAT_HTML, 0.01);
        $row1->tolerancetype = 0;
        $rows = [$row1];

        $renderer = $PAGE->get_renderer('qbehaviour_gapcheck');
        $rc = new ReflectionClass($renderer);
        $method = $rc->getMethod('process_answer_rows');
        $method->setAccessible(true);
        $entry = $method->invoke($renderer, $rows, $salt);

        $this->assertCount(1, $entry['n']);
        $this->assertEquals('3.14', $entry['n'][0]['v']);
        $this->assertEquals(0.01, $entry['n'][0]['t']);
        $this->assertEquals(0, $entry['n'][0]['k']);
        $this->assertEquals(1.0, $entry['n'][0]['f']);
        $this->assertContains(hash_hmac('sha256', '3.14', $salt), $entry['h']);
    }

    public function test_process_answer_rows_skips_wildcard(): void {
        global $PAGE;
        $salt = '1|99|1';

        $row1 = new question_answer(0, 'Paris', 1.0, '', FORMAT_HTML);
        $row2 = new question_answer(0, '*', 0.0, '', FORMAT_HTML);
        $rows = [$row1, $row2];

        $renderer = $PAGE->get_renderer('qbehaviour_gapcheck');
        $rc = new ReflectionClass($renderer);
        $method = $rc->getMethod('process_answer_rows');
        $method->setAccessible(true);
        $entry = $method->invoke($renderer, $rows, $salt);

        $this->assertCount(1, $entry['h']);
        $this->assertContains(hash_hmac('sha256', 'Paris', $salt), $entry['h']);
    }

    public function test_fallback_hash_single(): void {
        global $PAGE;
        $salt = '1|99|1';

        $renderer = $PAGE->get_renderer('qbehaviour_gapcheck');
        $rc = new ReflectionClass($renderer);
        $method = $rc->getMethod('fallback_hash');
        $method->setAccessible(true);
        $entry = $method->invoke($renderer, 'Berlin', $salt);

        $this->assertEquals(['h' => [hash_hmac('sha256', 'Berlin', $salt)]], $entry);
    }

    public function test_fallback_hash_pipe(): void {
        global $PAGE;
        $salt = '1|99|1';

        $renderer = $PAGE->get_renderer('qbehaviour_gapcheck');
        $rc = new ReflectionClass($renderer);
        $method = $rc->getMethod('fallback_hash');
        $method->setAccessible(true);
        $entry = $method->invoke($renderer, 'Berlin|London', $salt);

        $this->assertCount(2, $entry['h']);
        $this->assertContains(hash_hmac('sha256', 'Berlin', $salt), $entry['h']);
        $this->assertContains(hash_hmac('sha256', 'London', $salt), $entry['h']);
    }

    public function test_renderer_output_has_structured_format(): void {
        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 'gapcheck');

        $displayoptions = new question_display_options();
        $html = $this->quba->render_question(1, $displayoptions);

        $this->assertStringContainsString('pergapcheck-hashmap', $html);

        preg_match('/data-pergap-hashes="([^"]+)"/', $html, $matches);
        $this->assertNotEmpty($matches, 'data-pergap-hashes attribute not found');

        $data = json_decode(html_entity_decode($matches[1]), true);
        $this->assertNotNull($data, 'Failed to parse JSON from data-pergap-hashes');

        foreach ($data as $field => $entry) {
            $this->assertArrayHasKey('h', $entry, "Field '$field' missing 'h' key");
            $this->assertIsArray($entry['h'], "'h' must be an array");
        }
    }

    public function test_renderer_output_contains_correct_hash(): void {
        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 'gapcheck');

        $displayoptions = new question_display_options();
        $html = $this->quba->render_question(1, $displayoptions);

        preg_match('/data-pergap-hashes="([^"]+)"/', $html, $matches);
        $data = json_decode(html_entity_decode($matches[1]), true);

        $salt = qbehaviour_gapcheck::get_salt($this->get_question_attempt());
        $expected = hash_hmac('sha256', 'frog', $salt);

        $found = false;
        foreach ($data as $entry) {
            if (in_array($expected, $entry['h'] ?? [])) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected hash for "frog" not found in rendered output');
    }
}
