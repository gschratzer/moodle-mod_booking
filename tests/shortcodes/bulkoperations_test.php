<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tests for the bulkoperations shortcode.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 David Ala
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/classes/price.php');

/**
 * Tests for bulkoperations shortcode.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class bulkoperations_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Test creation and display of shortcode bulkoperations.
     *
     * @covers \shortcodes::bulkoperations
     *
     * @param array $data
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_recommendedin_shortcode(array $data, array $expected): void {
        global $DB, $PAGE;
        $bdata = self::provide_bdata();

        // Setup test data.
        $courses = [];
        $courses[] = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'shortname' => 'course1']);
        $courses[] = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'shortname' => 'course2']);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        // Two courses will contain identical setup and multiple options in multiple booking.
        $cmids = [];

        foreach ($courses as $course) {
            $bdata['course'] = $course->id;
            $bdata['bookingmanager'] = $bookingmanager->username;
            $bookings = [];
            $bookings[] = $this->getDataGenerator()->create_module('booking', $bdata);
            $bookings[] = $this->getDataGenerator()->create_module('booking', $bdata);

            $this->setAdminUser();

            $this->getDataGenerator()->enrol_user($student1->id, $course->id);
            $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
            $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

            // Create an initial booking option.
            foreach ($bdata['standardbookingoptions'] as $option) {
                foreach ($bookings as $booking) {
                    $record = (object) $option;
                    $record->bookingid = $booking->id;
                    /** @var mod_booking_generator $plugingenerator */
                    $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
                    $option1 = $plugingenerator->create_option($record);
                    $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
                    $cmids[$settings->cmid] = $settings->cmid;
                }
            }
        }

        // Apply given settings.
        if (isset($data['settings'])) {
            foreach ($data['settings'] as $key => $value) {
                set_config($key, $value, 'booking');
            }
        }

        // Now we have multiple options in multiple bookings and multiple courses.
        $records = $DB->get_records('booking_options');
        $this->assertCount(24, $records, 'Booking options were not created correctly');

        // Prepare the args.
        $args = $data['args'];

        // Now we can start testing the shortcode.
        $env = new stdClass();
        $next = function () {
        };
        $args['all'] = 1;
        $PAGE->set_url(new \moodle_url('/mod/booking/tests/bulkoperations_test.php'));
        $shortcode = shortcodes::bulkoperations('bulkoperations', $args, null, $env, $next);
        $this->assertNotEmpty($shortcode);
        $this->assertStringContainsString($expected['tablestringcontains'], $shortcode);
        $pregmatch = preg_match('/<div[^>]*\sdata-encodedtable=["\']?([^"\'>\s]+)["\']?/i', $shortcode, $matches);
        $this->assertEquals($expected['displaytable'], $pregmatch);
        if (!$expected['displaytable']) {
            return;
        }
        $table = wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
        $tableobject = $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals($expected['numberofrecords'], $table->totalrows);
    }

    /**
     * Data provider for test_courselist_shortcode
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {

        return [
            'settingoff' => [
                [
                    'args' => [
                        'all' => 1, // Set this to avoid filtering on coursestarttime.
                    ],
                    'settings' => [
                        'shortcodesoff' => 1,
                    ],
                ],
                [
                    'tablestringcontains' => "shortcodes are turned off",
                    'displaytable' => false,
                ],
            ],
            'settingson' => [
                [
                    'args' => [
                        'all' => 1, // Set this to avoid filtering on coursestarttime.
                    ],
                    'settings' => [
                        'shortcodesoff' => 0,
                    ],
                ],
                [
                    'tablestringcontains' => "optionbulkoperationstable",
                    'displaytable' => true,
                    'numberofrecords' => 24,
                ],
            ],
        ];
    }

    /**
     * Provides the data that's constant for the test.
     *
     * @return array
     *
     */
    private static function provide_bdata(): array {
        return [
            'name' => 'Test Booking Policy 1',
            'eventtype' => 'Test event',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
            'standardbookingoptions' => [
                [
                    'text' => 'Test Booking Option without price',
                    'description' => 'Test Booking Option',
                    'identifier' => 'noprice',
                    'maxanswers' => 1,
                ],
                [
                    'text' => 'Test Booking Option with price',
                    'description' => 'Test Booking Option',
                    'identifier' => 'withprice',
                    'maxanswers' => 1,
                ],
                [
                    'text' => 'Disalbed Test Booking Option',
                    'description' => 'Test Booking Option',
                    'identifier' => 'disabledoption',
                    'maxanswers' => 1,
                    'disablebookingusers' => 1,
                ],
                [
                    'text' => 'Wait for confirmation Booking Option, no price',
                    'description' => 'Test Booking Option',
                    'identifier' => 'waitforconfirmationnoprice',
                    'maxanswers' => 1,
                    'waitforconfirmation' => 1,
                ],
                [
                    'text' => 'Wait for confirmation Booking Option, price',
                    'description' => 'Test Booking Option',
                    'identifier' => 'waitforconfirmationwithprice',
                    'maxanswers' => 1,
                    'waitforconfirmation' => 1,
                ],
                [
                    'text' => 'Blocked by enrolledincohorts',
                    'description' => 'Test enrolledincohorts availability condition',
                    'identifier' => 'enrolledincohorts',
                    'maxanswers' => 1,
                    'boavenrolledincohorts' => 'testcohort',
                ],
            ],
        ];
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }
}
