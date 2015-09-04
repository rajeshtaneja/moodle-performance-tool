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

namespace moodlehq\performancetoolkit\testplangenerator;

use moodlehq\performancetoolkit\testplangenerator\util,
    moodlehq\performancetoolkit\testplangenerator\browsermobproxyclient,
    Symfony\Component\Process\Process;

/**
 * Utils for performance-related stuff
 *
 * @package    moodlehq_performancetoolkit_testplangenerator
 * @copyright  2015 Rajesh Taneja <rajesh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Init/reset utilities for Performance test site.
 *
 * @package   moodlehq_performancetoolkit_testplangenerator
 * @copyright 2015 Rajesh Taneja
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generator {

    /**
     * Create test plan.
     *
     * @param string $size size of the test plan.
     * @param string $proxy proxy url
     * @param string $port (optional) port to be used for proxy server.
     * @param bool $enable only enable it.
     */
    public function create_test_plan($size, $proxy = "localhost:9090", $port = '', $enable = false) {
        global $CFG, $DB;

        // Drop old testplan data dir, ensuring we have fresh data everytime we run the tool.
        util::drop_dir(util::get_tool_dir(), true);

        // Initialise BrowserMobProxy.
        $browsermobproxy = new browsermobproxyclient($proxy);

        // Use the proxy server url now.
        $proxyurl = $browsermobproxy->create_connection($port);
        echo "Proxy server running at: " . $proxyurl . PHP_EOL;

        // Create behat.yml.
        util::create_test_feature($size, array(), $proxyurl);

        util::set_option('size', $size);

        // Run test plan.
        if (!$enable) {
            $cmd = "vendor/bin/behat --config " . util::get_tool_dir() . DIRECTORY_SEPARATOR . 'behat.yml ';
            echo "Run " . PHP_EOL . '  - ' . $cmd . PHP_EOL . PHP_EOL;
        } else {
            $this->run_plan_features();
        }

        // Close BrowserMobProxy connection.
        $browsermobproxy->close_connection();
        return 0;
    }

    public function run_plan_features() {
        // Execute each feature file 1 by one to show the proper progress...
        $testplanconfig = util::get_feature_config();
        if (empty($testplanconfig)) {
            util::performance_exception("Check generator config file testplan.json");
        }

        $status = $this->execute_behat_generator();
        // Don't proceed if it fails.
        if ($status) {
            echo "Error: Failed generating test plan" . PHP_EOL.PHP_EOL;
            $cmd = "vendor/bin/behat --config " . util::get_tool_dir() . DIRECTORY_SEPARATOR . 'behat.yml ';
            echo "Run " . PHP_EOL . '  - ' . $cmd . PHP_EOL . PHP_EOL;
            die();
        } else {
            echo PHP_EOL."Test plan has been generated under:".PHP_EOL;
            echo " - ". util::get_final_testplan_path().PHP_EOL;
        }
    }

    /**
     * Execute behat command for featurename and return exit status.
     *
     * @return int status code.
     */
    protected function execute_behat_generator() {
        $cmd = "vendor/bin/behat --config " . util::get_tool_dir() . DIRECTORY_SEPARATOR . 'behat.yml ';

        $process = new Process($cmd);
        $process->setWorkingDirectory(__DIR__ . "/../../moodle");

        $process->setTimeout(null);
        $process->start();
        if ($process->getStatus() !== 'started') {
            echo "Error starting process";
            $process->signal(SIGKILL);
            exit(1);
        }

        while ($process->isRunning()) {
            $output = $process->getIncrementalOutput();
            $op = trim($output);
            if (!empty($op)) {
                echo $output;
            }
        }

        if ($process->getExitCode() !== 0) {
            echo $process->getErrorOutput();
        }
        return $process->getExitCode();
    }
}