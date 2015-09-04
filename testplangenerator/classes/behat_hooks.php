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
 * Behat hooks steps definitions.
 *
 * This methods are used by Behat CLI command.
 *
 * @package    core
 * @category   test
 * @copyright  2012 David Monllaó
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.
// With BEHAT_TEST we will be using $CFG->behat_* instead of $CFG->dataroot, $CFG->prefix and $CFG->wwwroot.
require_once(__DIR__.'/../../moodle/lib/behat/behat_base.php');

use Behat\Behat\Event\SuiteEvent as SuiteEvent,
    Behat\Behat\Event\ScenarioEvent as ScenarioEvent,
    Behat\Behat\Event\FeatureEvent as FeatureEvent,
    Behat\Behat\Event\OutlineExampleEvent as OutlineExampleEvent,
    Behat\Behat\Event\StepEvent as StepEvent,
    Behat\Mink\Exception\DriverException as DriverException,
    WebDriver\Exception\NoSuchWindow as NoSuchWindow,
    WebDriver\Exception\UnexpectedAlertOpen as UnexpectedAlertOpen,
    WebDriver\Exception\UnknownError as UnknownError,
    WebDriver\Exception\CurlExec as CurlExec,
    WebDriver\Exception\NoAlertOpenError as NoAlertOpenError;

use \moodlehq\performancetoolkit\testplangenerator\util,
    \moodlehq\performancetoolkit\testplangenerator\browsermobproxyclient,
    \moodlehq\performancetoolkit\testplangenerator\testplan_writer;

/**
 * Hooks to the behat process.
 *
 * Behat accepts hooks after and before each
 * suite, feature, scenario and step.
 *
 * They can not call other steps as part of their process
 * like regular steps definitions does.
 *
 * Throws generic Exception because they are captured by Behat.
 *
 * @package   core
 * @category  test
 * @copyright 2012 David Monllaó
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_hooks extends behat_base {

    /**
     * @var For actions that should only run once.
     */
    protected static $initprocessesfinished = false;

    /**
     * @var Keeps track of which feature file is being executed.
     */
    public static $featurefile;

    /**
     * @var Keeps track of threadgroupname = Feature title
     */
    public static $threadgroupname;

    /**
     * Gives access to moodle codebase, ensures all is ready and sets up the test lock.
     *
     * Includes config.php to use moodle codebase with $CFG->behat_*
     * instead of $CFG->prefix and $CFG->dataroot, called once per suite.
     *
     * @param SuiteEvent $event event before suite.
     * @static
     * @throws Exception
     * @BeforeSuite
     */
    public static function before_suite(SuiteEvent $event) {
        global $CFG;

        define('CLI_SCRIPT', 1);
        require_once(__DIR__.'/../../moodle/config.php');
        require_once(__DIR__.'/../../lib/autoload.php');

        // Now that we are MOODLE_INTERNAL.
        require_once($CFG->libdir . '/behat/classes/behat_command.php');
        require_once($CFG->libdir . '/behat/classes/behat_selectors.php');
        require_once($CFG->libdir . '/behat/classes/behat_context_helper.php');
        require_once($CFG->libdir . '/behat/classes/util.php');
        require_once($CFG->libdir . '/testing/classes/test_lock.php');
        require_once($CFG->libdir . '/testing/classes/nasty_strings.php');

        // Initialise BrowserMobProxy.
        $browsermobproxy = new browsermobproxyclient(util::get_option('proxyurl'));

        // Use the proxy server url now.
        $browsermobproxy->create_connection(util::get_option('proxyport'));

        util::drop_dir(util::get_final_testplan_path());

        // Update global properties in test plan.
        testplan_writer::start_testplan(util::get_option('size'));
    }

    /**
     * After suite event.
     *
     * @param SuiteEvent $event
     * @AfterSuite
     */
    public static function after_suite(SuiteEvent $event) {
        $browsermobproxy = new browsermobproxyclient(util::get_option('proxyurl'));
        $browsermobproxy->close_connection();
        echo PHP_EOL."Test plan has been generated under:".PHP_EOL;
        echo " - ". util::get_final_testplan_path().PHP_EOL;
    }

    /**
     * Sets featurefile name, which is used by test plan generator.
     *
     * @param FeatureEvent $event event fired before feature.
     * @throws coding_exception If here we are not using the test database it should be because of a coding error
     * @BeforeFeature
     */
    public static function before_feature(FeatureEvent $event) {
        self::$featurefile = $event->getFeature()->getFile();
        preg_match('/^.*\/(\w*)\.feature/i', self::$featurefile, $matches);

        self::$featurefile = $matches[1];

        self::$threadgroupname = $event->getFeature()->getTitle();
    }

    /**
     * Resets the test environment.
     *
     * @param OutlineExampleEvent|ScenarioEvent $event event fired before scenario.
     * @throws coding_exception If here we are not using the test database it should be because of a coding error
     * @BeforeScenario
     */
    public function before_scenario(BeforeScenario $event) {
        global $DB, $CFG;

        // TODO: check config value to ensure site is set for performance data.

        $moreinfo = 'More info in ' . behat_command::DOCS_URL . '#Running_tests';
        $driverexceptionmsg = 'Selenium server is not running, you need to start it to run tests that involve Javascript. ' . $moreinfo;

        try {
            $session = $this->getSession();
        } catch (CurlExec $e) {
            // Exception thrown by WebDriver, so only @javascript tests will be caugth; in
            // behat_util::is_server_running() we already checked that the server is running.
            throw new Exception($driverexceptionmsg);
        } catch (DriverException $e) {
            throw new Exception($driverexceptionmsg);
        } catch (UnknownError $e) {
            // Generic 'I have no idea' Selenium error. Custom exception to provide more feedback about possible solutions.
            $this->throw_unknown_exception($e);
        }


        // We need the Mink session to do it and we do it only before the first scenario.
        if (self::is_first_scenario()) {
            behat_selectors::register_moodle_selectors($session);
            behat_context_helper::set_session($session);

            // Ensure performance site is enable.
            if (!get_config('core', 'performancesitedata')) {
                $notperformancesite = "This is not a performance site". PHP_EOL .
                    " - Run vendor/bin/moodle_performance_site --enable=s" . PHP_EOL;
                throw new Exception($notperformancesite);
            }
        }

        // Start a new thread group.
        testplan_writer::start_thread_group(self::$featurefile, self::$threadgroupname);
    }

    /**
     * Returns whether the first scenario of the suite is running
     *
     * @return bool
     */
    protected static function is_first_scenario() {
        return !(self::$initprocessesfinished);
    }

    /**
     * Throws an exception after appending an extra info text.
     *
     * @throws Exception
     * @param UnknownError $exception
     * @return void
     */
    protected function throw_unknown_exception(UnknownError $exception) {
        $text = get_string('unknownexceptioninfo', 'tool_behat');
        throw new Exception($text . PHP_EOL . $exception->getMessage());
    }
}

