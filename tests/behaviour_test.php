<?php

defined('MOODLE_INTERNAL') || die();

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
 * @package   qbehaviour_gapcheck
 * @copyright 2026 Matthias Giger
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_gapcheck_behaviour_test extends qbehaviour_walkthrough_test_base {

    public function test_submit_correct_answer() {
        $this->quba->set_preferred_behaviour('gapcheck');

        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 1);

        $this->check_current_state(question_state::$todo);
        $this->check_current_output(
            $this->get_contains_submit_button()
        );

        $this->process_submission(['answer' => 'frog', '-submit' => 1]);
        $this->check_current_state(question_state::$gradedright);
        $this->check_current_output(
            $this->get_does_not_contain_submit_button()
        );
    }

    public function test_submit_wrong_answer() {
        $this->quba->set_preferred_behaviour('gapcheck');

        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 1);

        $this->process_submission(['answer' => 'toad', '-submit' => 1]);
        $this->check_current_state(question_state::$gradedwrong);
    }

    public function test_submit_incomplete() {
        $this->quba->set_preferred_behaviour('gapcheck');

        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 1);

        $this->process_submission(['answer' => '', '-submit' => 1]);
        $this->check_current_state(question_state::$invalid);
        $this->check_current_output(
            $this->get_contains_submit_button()
        );
    }

    public function test_save_then_submit() {
        $this->quba->set_preferred_behaviour('gapcheck');

        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 1);

        $this->process_submission(['answer' => 'frog']);
        $this->check_current_state(question_state::$todo);

        $this->process_submission(['answer' => 'frog', '-submit' => 1]);
        $this->check_current_state(question_state::$gradedright);
    }

    public function test_finish_without_submit() {
        $this->quba->set_preferred_behaviour('gapcheck');

        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 1);

        $this->process_submission(['answer' => 'frog']);
        $this->quba->finish_all_questions();

        $this->check_current_state(question_state::$gradedright);
    }

    public function test_finish_without_answer() {
        $this->quba->set_preferred_behaviour('gapcheck');

        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 1);

        $this->quba->finish_all_questions();

        $this->check_current_state(question_state::$gaveup);
    }

    public function test_get_salt_consistency() {
        $qa = new question_attempt(new question_definition(), 1, null, 1);

        $salt1 = qbehaviour_gapcheck::get_salt($qa);
        $salt2 = qbehaviour_gapcheck::get_salt($qa);

        $this->assertEquals($salt1, $salt2);
    }

    public function test_process_answer_rows_full_and_partial() {
        global $PAGE;
        $salt = '1|99|1';

        $row1 = new question_answer('Paris', 1.0, '');
        $row2 = new question_answer('paris', 0.5, '');
        $row3 = new question_answer('Paris, France', 1.0, '');
        $rows = [$row1, $row2, $row3];

        $renderer = new qbehaviour_gapcheck_renderer($PAGE, '');
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

    public function test_process_answer_rows_numerical_tolerance() {
        global $PAGE;
        $salt = '1|99|1';

        $row1 = new question_answer('3.14', 1.0, '');
        $row1->tolerance = 0.01;
        $row1->tolerancetype = 0;
        $rows = [$row1];

        $renderer = new qbehaviour_gapcheck_renderer($PAGE, '');
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

    public function test_process_answer_rows_skips_wildcard() {
        global $PAGE;
        $salt = '1|99|1';

        $row1 = new question_answer('Paris', 1.0, '');
        $row2 = new question_answer('*', 0.0, '');
        $rows = [$row1, $row2];

        $renderer = new qbehaviour_gapcheck_renderer($PAGE, '');
        $rc = new ReflectionClass($renderer);
        $method = $rc->getMethod('process_answer_rows');
        $method->setAccessible(true);
        $entry = $method->invoke($renderer, $rows, $salt);

        $this->assertCount(1, $entry['h']);
        $this->assertContains(hash_hmac('sha256', 'Paris', $salt), $entry['h']);
    }

    public function test_fallback_hash_single() {
        global $PAGE;
        $salt = '1|99|1';

        $renderer = new qbehaviour_gapcheck_renderer($PAGE, '');
        $rc = new ReflectionClass($renderer);
        $method = $rc->getMethod('fallback_hash');
        $method->setAccessible(true);
        $entry = $method->invoke($renderer, 'Berlin', $salt);

        $this->assertEquals(['h' => [hash_hmac('sha256', 'Berlin', $salt)]], $entry);
    }

    public function test_fallback_hash_pipe() {
        global $PAGE;
        $salt = '1|99|1';

        $renderer = new qbehaviour_gapcheck_renderer($PAGE, '');
        $rc = new ReflectionClass($renderer);
        $method = $rc->getMethod('fallback_hash');
        $method->setAccessible(true);
        $entry = $method->invoke($renderer, 'Berlin|London', $salt);

        $this->assertCount(2, $entry['h']);
        $this->assertContains(hash_hmac('sha256', 'Berlin', $salt), $entry['h']);
        $this->assertContains(hash_hmac('sha256', 'London', $salt), $entry['h']);
    }

    public function test_renderer_output_has_structured_format() {
        $this->quba->set_preferred_behaviour('gapcheck');

        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 1);

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

    public function test_renderer_output_contains_correct_hash() {
        $this->quba->set_preferred_behaviour('gapcheck');

        $sa = test_question_maker::make_question('shortanswer');
        $this->start_attempt_at_question($sa, 1);

        $displayoptions = new question_display_options();
        $html = $this->quba->render_question(1, $displayoptions);

        preg_match('/data-pergap-hashes="([^"]+)"/', $html, $matches);
        $data = json_decode(html_entity_decode($matches[1]), true);

        $salt = '0|' . $this->quba->get_id() . '|1';
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
