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

namespace moodlehq\performancetoolkit\sitegenerator;
use \moodlehq\performancetoolkit\sitegenerator\util;
use \moodlehq\performancetoolkit\sitegenerator\installer;
use \Symfony\Component\Process\Process as symfonyprocess;

global $CFG;

require_once($CFG->libdir . "/behat/classes/util.php");
require_once($CFG->libdir . "/behat/classes/behat_command.php");

/**
 * Site generator.
 *
 * @package    moodlehq_performancetoolkit_sitegenerator
 * @copyright  2015 Rajesh Taneja <rajesh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Generate site.
 *
 * @package   moodlehq_performancetoolkit_sitegenerator
 * @copyright 2015 Rajesh Taneja <rajesh@moodle.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generator {

    /** @var int log counter for showing progress. */
    protected static $logcounter = 0;

    /** @var int Keep track of progress start time. */
    protected static $starttime = 0;

    /**
     * @var array list of valid site sizes.
     */
    protected $sitesizes = array(
        "xs" => "Extra small",
        "s" => "Small",
        "m" => "Medium",
        "l" => "Large",
        "xl" => "Extra large",
    );

    /**
     * Action to do.
     *
     * @param array $action list of action items recived by user.
     * @return int|bool error code, false to show help message, for invalid option passed.
     */
    public function run($action) {
        // Check site status and then perform action.
        $sitestatus = installer::get_site_status();

        // If site is not configured then just exit with error.
        if ($sitestatus == installer::SITE_ERROR_CONFIG) {
            echo "Error: Ensure you set \$CFG->* vars in config.php " .
                "and you ran vendor/bin/generate_site --install\n\n";
            return 1;
        }

        // Check what option is passed by user.
        if ($action['install']) {

            $exitstatus = 0;
            if ($sitestatus == installer::SITE_ERROR_INSTALLED) {
                echo "Site is already installed\n\n";

            } else if ($sitestatus == installer::SITE_ERROR_INSTALL) {
                $exitstatus = $this->execute('install');

            } else if ($sitestatus == installer::SITE_ERROR_REINSTALL) {
                if (!empty($action['force-install'])) {
                    $this->execute('drop');
                    $exitstatus = $this->execute('install');
                } else {
                    echo "Error: Site is already installed, use --force-install option to install site" . "\n\n";
                    $exitstatus = 1;
                }

            } else {
                echo "Error: Invalid site status while installing: " . $sitestatus ."\n\n";
                $exitstatus = 1;
            }
            if (empty($exitstatus) && !$action['testdata']) {
                return $exitstatus;
            }

        } else if ($action['drop']) {

            $this->execute('disable');
            if ($sitestatus == installer::SITE_ERROR_INSTALLED ||
                $sitestatus == installer::SITE_ERROR_REINSTALL || $action['force']) {
                $this->execute('drop');

            } else {
                echo "No site installed, so doing nothing.\n\n";
            }
            return 0;

        } else if ($action['dropsite']) {

            $this->execute('disable');
            if ($sitestatus == installer::SITE_ERROR_INSTALLED ||
                $sitestatus == installer::SITE_ERROR_REINSTALL || $action['force']) {
                $this->execute('dropsite');

            } else {
                echo "No site installed, so doing nothing.\n\n";
            }
            return 0;

        } else if ($action['disable']) {

            if ($sitestatus == installer::SITE_ERROR_INSTALLED ||
                $sitestatus == installer::SITE_ERROR_REINSTALL) {
                $this->execute('disable');

            } else {
                echo "No site installed, so can't disable performance generator.\n\n";
            }

        } else if ($action['enable']) {
            // This will be handled later.

        } else if ($action['testdata']) {

            // This will be handled later, so a valid option by itself.
        } else if ($action['backup']) {

            if ($action['backup'] && is_string($action['backup'])) {
                $this->execute("backup", $action['backup']);
            } else {
                echo "Pass backup state name for backup.".PHP_EOL;
            }
        } else if ($action['restore']) {

            if ($action['restore'] && is_string($action['restore'])) {
                $this->execute("restore", $action['restore']);
            } else {
                echo "Pass backup state name for restore.".PHP_EOL;
            }
        } else {

            return false;
        }

        // Check if site needs to be enabled.
        if ($action['enable']) {
            $sitesize = $this->check_valid_site_size($action['enable']);

            if ($sitestatus == installer::SITE_ERROR_INSTALLED) {
                $this->execute('enable', $sitesize);
            } else {
                echo "No site installed, so can't enable performance generator." . PHP_EOL;
                echo "Run \n   - vendor/bin/moodle_performance_site --install" . PHP_EOL;
            }
        }

        // Check if site data needs to be generated.
        if ($action['testdata']) {
            $sitesize = $this->check_valid_site_size($action['testdata']);

            // Check site status and then perform action.
            $sitestatus = installer::get_site_status();

            if ($sitestatus !== installer::SITE_ERROR_INSTALLED) {
                echo "No site installed, so can't generate performance site data." . PHP_EOL;
                echo "Run \n   - vendor/bin/moodle_performance_site --install" . PHP_EOL;
                return 1;
            }

            if (empty($this->execute('enable', $sitesize))) {
                // Don't generate site if already installed.
                if ($sitedata = get_config('core', 'performancesitedata')) {
                    echo "Site data is already generated for site size: " . $sitedata . PHP_EOL;
                    echo "Drop site and try again:\n - vendor/bin/generate_site --drop". PHP_EOL;
                    exit(1);
                } else {
                    set_config('performancesitedata', $sitesize);
                }

                $this->generate();
            } else {
                return 1;
            }
        }

        // Success.
        return 0;
    }

    /**
     * Start behat process for generating site contents.
     */
    protected function generate() {
        $generatorfeaturepath = util::get_tool_dir();
        // Execute each feature file 1 by one to show the proper progress...
        $generatorconfig = util::get_feature_config();
        if (empty($generatorconfig)) {
            util::performance_exception("Check generator config file.");
        }

        // Create test feature file depending on what is given.
        foreach ($generatorconfig as $featuretoadd => $config) {
            $finalfeaturepath = $generatorfeaturepath . DIRECTORY_SEPARATOR . $featuretoadd . '.feature';

            if (file_exists($finalfeaturepath)) {
                self::log($featuretoadd);
                $status = $this->execute_behat_generator($featuretoadd, $finalfeaturepath);
                // Don't proceed...
                if ($status) {
                    echo "Error: Failed generating data for ".$featuretoadd.PHP_EOL.PHP_EOL;
                    exit(1);
                }
                self::end_log();
            }
        }
    }

    /**
     * Execute behat command for featurename and return exit status.
     *
     * @param string $featurename name of the feature
     * @param string $featurepath path of feature file
     * @return int status code.
     */
    protected function execute_behat_generator($featurename, $featurepath) {
        $cmd = "vendor/bin/behat --config " . util::get_tool_dir() . DIRECTORY_SEPARATOR . 'behat.yml ' . $featurepath;

        $process = new symfonyprocess($cmd);
        $process->setWorkingDirectory(__DIR__ . "/../../moodle");

        $process->setTimeout(null);
        $process->start();
        if ($process->getStatus() !== 'started') {
            echo "Error starting process: $featurename";
            $process->signal(SIGKILL);
            exit(1);
        }

        while ($process->isRunning()) {
            $output = $process->getIncrementalOutput();
            // Don't show start data everytime.
            $output = preg_replace('/[a-z0-9.\(\)].*/im', '', $output);

            $op = trim($output);
            if (!empty($op)) {
                echo $output;
            }
    }

        return $process->getExitCode();
    }

    /**
     * Return true if valid site size else false;
     *
     * @param string $sitesize
     * @return string sitesize or die if not valid.
     */
    protected function check_valid_site_size($sitesize) {
        // Check if valid site size is given.
        $sitesize = strtolower(is_string($sitesize) ? $sitesize : '');
        $allowedsizes = array_keys($this->sitesizes);
        if (!in_array($sitesize, $allowedsizes)) {
            echo "Error: Site size ($sitesize) is not valid. It should be either one of:\n" . implode(', ', $allowedsizes) . "\n\n";
            die(1);
        }
        return strtolower($sitesize);
    }

    /**
     * Execute the action needed.
     *
     * @param string $action action to be executed.
     * @param string $value (optional) site size or backup/restore state passed.
     */
    protected function execute($action, $value = null) {
        switch ($action) {
            case 'install':
                return installer::install_site();
            case 'enable':
                return installer::enable_performance_sitemode($value);
                break;
            case 'drop':
                return installer::drop_site();
                break;
            case 'dropsite':
                return installer::drop_site(true);
                break;
            case 'disable':
                return installer::disable_performance_sitemode();
                break;
            case 'backup':
                return installer::store_site_state($value);
                break;
            case 'restore':
                return installer::restore_site_state($value);
                break;
        }
    }

    /**
     * Displays information as part of progress.
     *
     * @param string $featurename Feature name which is being executed.
     * @param bool $leaveopen If true, doesn't close LI tag (ready for dots)
     */
    public static function log($featurename, $leaveopen = false) {
        if (CLI_SCRIPT) {
            echo '* ';
        } else {
            echo \html_writer::start_tag('ul');
            echo \html_writer::start_tag('li');
        }
        echo "Generating data for: " . $featurename;
        if (!$leaveopen) {
            if (CLI_SCRIPT) {
                echo "\n";
            } else {
                echo \html_writer::end_tag('li');
            }
        } else {
            echo ': ';
            self::$starttime = microtime(true);
        }
        self::$logcounter = 0;
    }

    /**
     * Outputs dots. There is up to one dot per second.
     *
     * @param int $number Number of completed items
     */
    public static function dot($number = 1) {
        if (CLI_SCRIPT) {
            echo str_repeat('*', $number);
        } else {
            echo str_repeat(' * ');
        }

        self::$logcounter++;
        if (self::$logcounter % 70 == 0) {
            echo " " . (self::$logcounter * self::$logcounter/70) . PHP_EOL;
        }

        // Update time limit so PHP doesn't time out.
        if (!CLI_SCRIPT) {
            \core_php_time_limit::raise(120);
        }
    }

    /**
     * Ends a log string that was started using log function with $leaveopen.
     */
    public static function end_log() {
        echo PHP_EOL . "Finished in (" . round(microtime(true) - self::$starttime, 1).")";
        if (CLI_SCRIPT) {
            echo "\n\n";
        } else {
            echo \html_writer::end_tag('li');
            echo \html_writer::end_tag('ul');
        }
    }
}