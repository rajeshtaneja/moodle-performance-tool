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
 * Data generators for acceptance testing.
 *
 * @package   core_generator
 * @copyright 2015 rajesh Taneja
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__.'/../../moodle/lib/tests/behat/behat_data_generators.php');

use Behat\Gherkin\Node\TableNode,
    \Behat\Behat\Context\Step\Given,
    Behat\Behat\Exception\ErrorException,
    \moodlehq\performancetoolkit\testplangenerator\browsermobproxyclient,
    \moodlehq\performancetoolkit\testplangenerator\util,
    \moodlehq\performancetoolkit\testplangenerator\testplan_writer;

/**
 * Class containing bulk steps for setting up site for performance testing.
 *
 * @package   core_generator
 * @copyright 2015 rajesh Taneja
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_tool_generator extends behat_base {

    /**
     * Start capturing http request by browsermobproxy.
     *
     * @Given /^I start capturing http requests$/
     */
    public function start_capture_request($capturelabel) {
        browsermobproxyclient::new_har($capturelabel);
    }

    /**
     * Stop capturing http request by browsermobproxy.
     *
     * @Given /^I capture "([^"]*)" http request$/
     */
    public function stop_capture_request($capturelabel) {
        $request = $this->confirm_requests_to_capture($capturelabel);

        // Update testplan.
        testplan_writer::add_request_data($capturelabel, $request);
    }

    /**
     * Stop capturing http request by browsermobproxy and add assertion check in test plan.
     *
     * @Given /^I capture "([^"]*)" http request and ensure "(.*)" exists on page$/
     */
    public function stop_capture_request_and_assert($capturelabel, $assertiontext) {
        // Capture data if it's not in testplan-json-dist.
        $request = $this->confirm_requests_to_capture($capturelabel);

        // Update testplan.
        testplan_writer::add_request_data($capturelabel, $request, $assertiontext);
    }

    /**
     * Stop capturing http request by browsermobproxy and add assertion check in test plan.
     *
     * @Given /^I capture "([^"]*)" http request and ensure "([^"]*)" exists on page with following globals from page:$/
     */
    public function stop_capture_request_and_assert_and_set_global($capturelabel, $assertiontext, TableNode $globaldata) {
        // Capture data if it's not in testplan-json-dist.
        $request = $this->confirm_requests_to_capture($capturelabel);

        $globaldata = $globaldata->getRows();
        $data = array();
        foreach ($globaldata as $value) {
            $data[$value[0]] = $value[1];
        }

        // Update testplan.
        testplan_writer::add_request_data($capturelabel, $request, $assertiontext, $data);
    }

    /**
     * Creates csv file and add it to the threadgroup for use case.
     *
     * @Given /^I login as any "([^"]*)" enrolled in course "([^"]*)"$/
     */
    public function i_login_as_any_enrolled_in_course($rolearchtype, $courseshortname) {
        global $DB, $CFG;

        if (!$id = $DB->get_field('course', 'id', array('shortname' => $courseshortname))) {
            util::performance_exception('The specified course with shortname "' . $courseshortname . '" does not exist');
        }
        $coursecontext = \context_course::instance($id);
        if ($roles = get_archetype_roles($rolearchtype)) {
            $roles = array_keys($roles);
        }
        $roleid = $roles[0];

        $users = get_role_users($roleid, $coursecontext, false, 'u.id,u.username', 'u.id ASC');
        if (!$users) {
            util::performance_exception("Course without users with role: " . $rolearchtype);
        }
        $data = "";
        foreach ($users as $user) {
            $data .= $user->username.",".$user->username.",".$user->id.PHP_EOL;
        }

        $testplanfilename = $rolearchtype.'_'.behat_hooks::$featurefile.'.csv';
        $csvfile = util::get_final_testplan_path().DIRECTORY_SEPARATOR.$testplanfilename;

        $testplanfilepath = "";
        if (isset($CFG->testplanfiles_dataroot)) {
            $testplanfilepath = $CFG->testplanfiles_dataroot . DIRECTORY_SEPARATOR;
        }
        file_put_contents($csvfile, $data);

        testplan_writer::create_csv_data($testplanfilepath, $testplanfilename, $rolearchtype);
        $firstuser = array_shift($users);

        return new Given('I log in as "'.$firstuser->username.'"');
    }

    /**
     * Creates csv file and add it to the threadgroup for use case.
     *
     * @Given /^I add listener to threadgroup$/
     */
    public function i_add_listener_to_threadgroup() {
        testplan_writer::result_collector();
    }

    /**
     * Return information about what requests should be captured, with what params.
     *
     * @param string $capturelabel label.
     * @param string $featurefile name of the feature file.
     * @return array
     */
    private function get_capture_request_from_config($capturelabel, $featurefile) {
        $config = util::get_config();

        if (isset($config['scenarios'][$featurefile]['requests']) &&
            array_key_exists($capturelabel, $config['scenarios'][$featurefile]['requests'])) {

            return $config['scenarios'][$featurefile]['requests'][$capturelabel];
        }
        return array();
    }

    /**
     * Get user input, about what request is of interest, if set in testplan.json then ignore.
     *
     * @param string $capturelabel capture label.
     * @return array request which is saved.
     */
    private function confirm_requests_to_capture($capturelabel) {
        // Save har data in the temp file.
        $useridentifiedrequestsdatafile = util::get_har_file_path($capturelabel);

        $hardata = browsermobproxyclient::get_har($capturelabel);
        file_put_contents($useridentifiedrequestsdatafile, $hardata);

        $requests = util::get_info_from_har($capturelabel);

        // Get which request is being used.
        $request = $this->is_request_in_config($capturelabel, $requests);

        // Get query params to be used.
        if (!empty($request)) {
            // Update config and return.
            util::save_final_har_data($capturelabel, $request);
            return $request;
        }

        // This is only possible with interactive terminal, so ensure we have one before proceeding.
        $posixexists = function_exists('posix_isatty');
        // Make sure this step is only used with interactive terminal (if detected).
        if ($posixexists && !@posix_isatty(STDOUT)) {
            $msg = "You have some unfilled requests in testplan.json\n".
                "Please run following command for interactive terminal to enter value\n".
                "  vendor/bin/behat --config " . util::get_tool_dir() . DIRECTORY_SEPARATOR . 'behat.yml ';

            util::performance_exception($msg);
        }

        // Else ask user for the actual request to use.
        fwrite(STDOUT, "\n");

        if (count($requests) > 1) {
            foreach ($requests as $index => $request) {
                fwrite(STDOUT, $index . ". - " . $request['method'] . " - " . $request['path'] . PHP_EOL);
            }

            fwrite(STDOUT, "Which http request should associate with \"$capturelabel\": ");
            $requestindex = trim(fread(STDIN, 1024));

            // Ensure correct selection is done.
            while (!is_number($requestindex) || ($requestindex <= 0) || $requestindex > count($requests)) {
                fwrite(STDOUT, "Wrong request number for step $capturelabel, try again: ");
                $requestindex = trim(fread(STDIN, 1024));
            }
        } else {
            $requestindex = 0;
        }

        fwrite(STDOUT, "\Enter query values to substitute for \"".$requests[$requestindex]['path']."\"\n ");
        $requests[$requestindex]['query'] = $this->get_substitute_value_from_user($requests[$requestindex]['query']);

        // Save final request.
        util::save_final_har_data($capturelabel, $requests[$requestindex]);
        return $requests[$requestindex];
    }

    /**
     * Return request index which is defined in config with updated query data, leaving global values untouched.
     *
     * @param $getrequests
     * @param $postrequests
     * @param $postdata
     * @param $requestsfromconfig
     * @return array requestindex, method and path.
     */
    private function is_request_in_config($capturelabel, $requests) {
        $requestsfromconfig = $this->get_capture_request_from_config($capturelabel, \behat_hooks::$featurefile);

        $requestfromhar = null;
        // Check if request is already set by user in config.
        if (!empty($requestsfromconfig)) {
            // Loop though each request from har and find the appropriate one.
            // We need this to replace query values except the global set by user.
            foreach ($requests as $index => $request) {
                if ($requestsfromconfig['method'] == $request['method'] &&
                    $requestsfromconfig['path'] == $request['path']) {
                    break;
                }

            }

            if (empty($request)) {
                util::performance_exception("Request from har is not found in config. Check: ". \behat_hooks::$featurefile . " : " . $capturelabel);
            }

            // Replace global values in query array with config values.
            foreach ($requestsfromconfig['query'] as $key => $value) {
                if (preg_match('/^\$\{.*\}/', $value)) {
                    $request['query'][$key] = $value;
                }
            }
            return $request;
        }

        return array();
    }

    /**
     * Helper function to ask user for substitute value of query params.
     *
     * @param array $queryarray original value captured in har.
     * @return array
     */
    private function get_substitute_value_from_user($queryarray) {

        $querydata = array();
        foreach ($queryarray as $name => $value) {
            fwrite(STDOUT, "- $name [default: $value]: ");
            $userinput = trim(fread(STDIN, 1024));
            // Ensure correct selection is done.
            if (empty($userinput)) {
                $querydata[$name] = $value;
            } else {
                $querydata[$name] = '${'.$userinput.'}';
            }
        }

        return $querydata;
    }
}
