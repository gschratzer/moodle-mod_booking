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
 * Base class for a single booking option availability condition.
 *
 * All bo condition types must extend this class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 namespace mod_booking\bo_availability\conditions;

use context_system;
use mod_booking\bo_availability\bo_condition;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_answers;
use mod_booking\booking_bookit;
use mod_booking\booking_option_settings;
use mod_booking\output\bookingoption_description;
use mod_booking\singleton_service;
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Base class for a single bo availability condition.
 *
 * All bo condition types must extend this class.
 *
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class askforconfirmation implements bo_condition {
    /** @var int $id Standard Conditions have hardcoded ids. */
    public $id = MOD_BOOKING_BO_COND_ASKFORCONFIRMATION;

    /** @var bool $overwrittenbybillboard Indicates if the condition can be overwritten by the billboard. */
    public $overwrittenbybillboard = false;

    /**
     * Get the condition id.
     *
     * @return int
     *
     */
    public function get_id(): int {
        return $this->id;
    }

    /**
     * Needed to see if class can take JSON.
     * @return bool
     */
    public function is_json_compatible(): bool {
        return false; // Hardcoded condition.
    }

    /**
     * Needed to see if it shows up in mform.
     * @return bool
     */
    public function is_shown_in_mform(): bool {
        return false;
    }

    /**
     * Determines whether a particular item is currently available
     * according to this availability condition.
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     * @return bool True if available
     */
    public function is_available(booking_option_settings $settings, int $userid, bool $not = false): bool {

        // We only block when we are NOT on the waiting list...
        // And when it's set in the option.

        $isavailable = true;

        // Get the booking answers for this instance.
        $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);
        $bookinginformation = $bookinganswer->return_all_booking_information($userid);

        // The following conditions have to be met.
        // - User must not be on waitinglist
        // - AND: Ask for confirmation must be turned on.
        // - OR: A price is set and it's either fully booked already OR there are already other people on the waitinglist.
        if (
            !isset($bookinginformation['onwaitinglist'])
            && (!empty($settings->waitforconfirmation)
            || (!empty($settings->jsonobject->useprice))
                // Free spots on waitinglist given.
                && $settings->maxoverbooking > booking_answers::count_places($bookinganswer->usersonwaitinglist)
                && (
                        (
                            // Fully booked.
                            isset($bookinginformation['notbooked']['fullybooked'])
                            && $bookinginformation['notbooked']['fullybooked'] === true
                        )
                        || (
                            // Or people already on waiting list.
                            isset($bookinginformation['notbooked']['waiting'])
                            && !empty($bookinginformation['notbooked']['waiting'])
                        )
                    )
                )
        ) {
            if (
                !empty(get_config('booking', 'allowoverbooking'))
                && has_capability('mod/booking:canoverbook', context_system::instance())
            ) {
                $isavailable = true;
            } else {
                $isavailable = false;
            }
        }

        if ($not) {
            $isavailable = !$isavailable;
        }

        return $isavailable;
    }

    /**
     * Each function can return additional sql.
     * This will be used if the conditions should not only block booking...
     * ... but actually hide the conditons alltogether.
     * @param int $userid
     * @return array
     */
    public function return_sql(int $userid = 0): array {

        return ['', '', '', [], ''];
    }

    /**
     * The hard block is complementary to the is_available check.
     * While is_available is used to build eg also the prebooking modals and...
     * ... introduces eg the booking policy or the subbooking page, the hard block is meant to prevent ...
     * ... unwanted booking. It's the check just before booking if we really...
     * ... want the user to book. It will return always return false on subbookings...
     * ... as they are not necessary, but return true when the booking policy is not yet answered.
     * Hard block is only checked if is_available already returns false.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @return bool
     */
    public function hard_block(booking_option_settings $settings, $userid): bool {
        return true;
    }

    /**
     * Obtains a string describing this restriction (whether or not
     * it actually applies). Used to obtain information that is displayed to
     * students if the activity is not available to them, and for staff to see
     * what conditions are.
     *
     * The $full parameter can be used to distinguish between 'staff' cases
     * (when displaying all information about the activity) and 'student' cases
     * (when displaying only conditions they don't meet).
     *
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $full Set true if this is the 'full information' view
     * @param bool $not Set true if we are inverting the condition
     * @return array availability and Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_description(booking_option_settings $settings, $userid = null, $full = false, $not = false): array {

        $description = '';

        $isavailable = $this->is_available($settings, $userid, $not);

        $description = $this->get_description_string($isavailable, $full, $settings);

        return [$isavailable, $description, MOD_BOOKING_BO_PREPAGE_BOOK, MOD_BOOKING_BO_BUTTON_MYBUTTON];
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, int $optionid = 0) {
        // Do nothing.
    }

    /**
     * The page refers to an additional page which a booking option can inject before the booking process.
     * Not all bo_conditions need to take advantage of this. But eg a condition which requires...
     * ... the acceptance of a booking policy would render the policy with this function.
     *
     * @param int $optionid
     * @param int $userid optional user id
     * @return array
     */
    public function render_page(int $optionid, int $userid = 0) {

        if (!empty($userid)) {
            $user = singleton_service::get_instance_of_user($userid);
        }

        $data1 = new bookingoption_description($optionid, null, MOD_BOOKING_DESCRIPTION_WEBSITE, true, false, $user ?? null);

        $template = 'mod_booking/bookingoption_description_prepagemodal_bookit';

        $dataarray[] = [
            'data' => $data1->get_returnarray(),
        ];

        $templates[] = $template;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        [$template, $data2] = booking_bookit::render_bookit_template_data($settings, $userid ?? 0, false);
        $data2 = reset($data2);
        $template = reset($template);

        $dataarray[] = [
            'data' => $data2->data,
        ];

        $templates[] = $template;

        // Only if the option is not yet booked, we set buttontype to 1 (continue is disabled).
        $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);

        // Inactive Continue Button.
        // We don't use this functionality right now.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* if ($bookinganswer->user_status($USER->id) == MOD_BOOKING_STATUSPARAM_NOTBOOKED) {
            $buttontype = 1;
        } else {
            $buttontype = 0;
        } */
        $buttontype = 0;

        $response = [
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* 'json' => json_encode($dataarray), */
            'template' => implode(',', $templates),
            'buttontype' => $buttontype, // This means that the continue button is disabled.
            'data' => $dataarray,
        ];

        return $response;
    }

    /**
     * Some conditions (like price & bookit) provide a button.
     * Renders the button, attaches js to the Page footer and returns the html.
     * Return should look somehow like this.
     * ['mod_booking/bookit_button', $data];
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @param bool $full
     * @param bool $not
     * @param bool $fullwidth
     * @return array
     */
    public function render_button(
        booking_option_settings $settings,
        int $userid = 0,
        bool $full = false,
        bool $not = false,
        bool $fullwidth = true
    ): array {

        global $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }
        $label = $this->get_description_string(false, $full, $settings);

        return bo_info::render_button(
            $settings,
            $userid,
            $label,
            'btn btn-secondary mt-1 mb-1',
            true,
            $fullwidth,
            'button',
            'option',
            false,
            'noforward'
        );
    }

    /**
     * Helper function to return localized description strings.
     *
     * @param bool $isavailable
     * @param bool $full
     * @param booking_option_settings $settings
     * @return string
     */
    private function get_description_string($isavailable, $full, $settings): string {

        if (
            !$isavailable
            && $this->overwrittenbybillboard
            && !empty($desc = bo_info::apply_billboard($this, $settings))
        ) {
            return $desc;
        }

        // In this case, we dont differentiate between availability, because when it blocks...
        // ... it just means that it can be booked. Blocking has a different functionality here.
        $description = get_string('bocondaskforconfirmationnotavailable', 'mod_booking');

        return $description;
    }
}
