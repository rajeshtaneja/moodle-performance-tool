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

namespace moodlehq\performancetoolkit;

use Symfony\Component\Yaml\Yaml as symfonyyaml;

require_once(__DIR__ . '/../moodle/lib/behat/classes/behat_config_manager.php');

/**
 * Utils for performance-related stuff
 *
 * @package    moodlehq_performancetoolkit_sitegenerator
 * @copyright  2015 Rajesh Taneja <rajesh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Init/reset utilities for Performance test site.
 *
 * @package   moodlehq_performancetoolkit_sitegenerator
 * @copyright 2015 Rajesh Taneja
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait toolkit_util {

    /** @var array Keeps array of tool config */
    protected $config = array();

    /** @var  string tool directory where data will be stored. */
    protected $tooldir;

    /**
     * Return directory in which performance data is saved.
     *
     * @static
     * @param bool $create if ture then it will also create directory if not present.
     * @return string
     */
    public static function get_performance_dir($create = false) {
        global $CFG;

        if (empty($CFG->performance_dataroot)) {
            self::performance_exception("\$CFG->performance_dataroot is not set.");
        }

        $dir = $CFG->performance_dataroot;

        // Create dir if required.
        if ($create && !is_dir($dir)) {
            make_writable_directory($dir, true);
        }

        return $dir;
    }

    /**
     * Try to get current git hash of the performance-tool-kit
     * @return string null if unknown, sha1 hash if known
     */
    public static function get_performance_tool_hash() {

        // This is a bit naive, but it should mostly work for all platforms.

        if (!file_exists(__DIR__ . "/../.git/HEAD")) {
            return null;
        }

        $headcontent = file_get_contents(__DIR__ . "/../.git/HEAD");
        if ($headcontent === false) {
            return null;
        }

        $headcontent = trim($headcontent);

        // If it is pointing to a hash we return it directly.
        if (strlen($headcontent) === 40) {
            return $headcontent;
        }

        if (strpos($headcontent, 'ref: ') !== 0) {
            return null;
        }

        $ref = substr($headcontent, 5);

        if (!file_exists(__DIR__ . "/../.git/$ref")) {
            return null;
        }

        $hash = file_get_contents(__DIR__ . "/../.git/$ref");

        if ($hash === false) {
            return null;
        }

        $hash = trim($hash);

        if (strlen($hash) != 40) {
            return null;
        }

        return $hash;
    }

    /**
     * Execption used by performance tool.
     *
     * @param string $msg message in exception.
     * @throws \moodle_exception
     */
    public static function performance_exception($msg) {
        throw new \moodle_exception($msg);
    }

    /**
     * Return config for specific feature or all features.
     *
     * @param string $featurename feature name for which config is needed.
     * @return array.
     */
    public static function get_feature_config($featurename = '') {
        $featureconfig = self::get_config();
        if (empty($featurename)) {
            return $featureconfig['scenarios'];
        } else {
            return $featureconfig['scenarios'][$featurename];
        }
    }

    /**
     * Get feature path for the tool.
     *
     * @param string $featurename name of the feature for which path should be returned.
     * @return string
     */
    public static function get_feature_path($featurename) {
        $generatorconfig = self::get_feature_config();

        if (!empty($generatorconfig[$featurename]['featurepath'])) {
            $featurepath = $generatorconfig[$featurename]['featurepath'];
        } else {
            // Add generator contexts.
            $classname = get_called_class();
            preg_match('/.*\\\([a-z-_]+)\\\[a-z]+$/i', $classname, $behatcontextdir);
            $featurepath = __DIR__ . "/../" . $behatcontextdir[1] . '/features/' . $featurename . '.feature';
        }

        if (file_exists($featurepath)) {
            return $featurepath;
        } else {
            return '';
        }
    }

    /**
     * Return tool version from config. Used to identify the tool differences.
     *
     * @return int.
     */
    public static function get_tool_version() {
        $config = self::get_config();

        return $config['version'];
    }

    /**
     * Create test feature and enable behat config.
     *
     * @param string $sitesize size of site
     * @param array $optionaltestdata (optional), replace default template with this value.
     * @param string $proxy proxy url which will be used for test plan generation example: "localhost:9090"
     */
    public static function create_test_feature($sitesize, $optionaltestdata = array(), $proxy= "") {
        $generatorfeaturepath = self::get_tool_dir();
        $generatorconfig = self::get_feature_config();

        if (empty($generatorconfig)) {
            self::performance_exception("Check generator config file.");
        }


        // Create test feature file depending on what is given.
        foreach ($generatorconfig as $featuretoadd => $config) {
            if (!isset($optionaltestdata[$featuretoadd])) {
                if ($featurecontents = self::get_feature_contents($featuretoadd, $sitesize)) {
                    $optionaltestdata[$featuretoadd] = $featurecontents;
                } else {
                    echo "Feature file not found for: " . $featuretoadd;
                }
            }
            if (isset($optionaltestdata[$featuretoadd])) {
                $finalfeaturepath = $generatorfeaturepath . DIRECTORY_SEPARATOR . $featuretoadd . '.feature';
                file_put_contents($finalfeaturepath, $optionaltestdata[$featuretoadd]);
            }
        }

        // Update config file.
        self::update_config_file(array_keys($optionaltestdata), $proxy);
    }

    /**
     * Updates a config file
     *
     * @param  array $featurestoadd list of features to add.
     * @param string $proxy proxy url which will be used for test plan generation example: "localhost:9090"
     * @return void
     */
    protected static function update_config_file($featurestoadd, $proxy= "") {
        global $CFG;

        // Behat must have a separate behat.yml to have access to the whole set of features and steps definitions.
        $configfilepath = self::get_tool_dir() . DIRECTORY_SEPARATOR . 'behat.yml';
        $featurepath = self::get_tool_dir();

        // Gets all the components with features.
        $featureslist = glob("$featurepath/*.feature");
        $features = array();

        foreach ($featurestoadd as $featuretoadd) {
            $feature = preg_grep('/.\/' . $featuretoadd . '\.feature/', $featureslist);
            if (!empty($feature)) {
                if (count($feature) > 1) {
                    echo "Found more then 1 feature for the requested order set: " , $featuretoadd . PHP_EOL;
                }
                $features = array_merge($features, $feature);
            }
        }

        // Gets all the components with steps definitions.
        $stepsdefinitions = array();
        $steps = \behat_config_manager::get_components_steps_definitions();
        if ($steps) {
            foreach ($steps as $key => $filepath) {
                $stepsdefinitions[$key] = $filepath;
            }
        }

        // We don't want the deprecated steps definitions here.
        unset($stepsdefinitions['behat_deprecated']);

        // Remove default hooks.
        unset($stepsdefinitions['behat_hooks']);

        // Add generator contexts.
        $classname = get_called_class();
        preg_match('/.*\\\([a-z-_]+)\\\[a-z]+$/i', $classname, $behatcontextdir);
        $contexts = glob(__DIR__ . "/../" . $behatcontextdir[1] . "/classes/behat_*.php");

        foreach ($contexts as $context) {
            preg_match('/.*\/(behat_[a-z_].*)\.php$/', $context, $matches);
            $classname = $matches[1];
            $stepsdefinitions[$classname] = $context;
        }

        // Add any other context file defined in config.
        $generatorconfig = self::get_config();

        foreach ($featurestoadd as $featurename) {
            if (!empty($generatorconfig[$featurename]['contextpath'])) {
                if (!is_array($generatorconfig[$featurename]['contextpath'])) {
                    $customcontextspaths = array($generatorconfig[$featurename]['contextpath']);
                } else {
                    $customcontextspaths = $generatorconfig[$featurename]['contextpath'];
                }

                foreach ($customcontextspaths as $customcontextpath) {
                    preg_match('/.*\/(behat_[a-z_].*)\.php$/', $customcontextpath, $matches);
                    $classname = $matches[1];
                    $stepsdefinitions[$classname] = $customcontextpath;
                }

            }
        }

        // Behat config file specifing the main context class,
        // the required Behat extensions and Moodle test wwwroot.
        $contents = self::get_config_file_contents($features, $stepsdefinitions, $proxy);

        // Stores the file.
        if (!file_put_contents($configfilepath, $contents)) {
            self::performance_exception('File ' . $configfilepath . ' can not be created');
        }
    }

    /**
     * Behat config file specifing the main context class,
     * the required Behat extensions and Moodle test wwwroot.
     *
     * @param array $features The system feature files
     * @param array $stepsdefinitions The system steps definitions
     * @param string $proxy proxy url which will be used for test plan generation example: "localhost:9090"
     * @return string
     */
    protected static function get_config_file_contents($features, $stepsdefinitions, $proxy= "") {
        global $CFG;

        // We require here when we are sure behat dependencies are available.
        require_once($CFG->dirroot . '/vendor/autoload.php');

        $selenium2wdhost = array('wd_host' => 'http://localhost:4444/wd/hub');

        $basedir = $CFG->dirroot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'behat';

        $config = array(
            'default' => array(
                'paths' => array(
                    'features' => $basedir . DIRECTORY_SEPARATOR . 'features',
                    'bootstrap' => $basedir . DIRECTORY_SEPARATOR . 'features' . DIRECTORY_SEPARATOR . 'bootstrap',
                ),
                'context' => array(
                    'class' => 'behat_init_context'
                ),
                'extensions' => array(
                    'Behat\MinkExtension\Extension' => array(
                        'base_url' => $CFG->wwwroot,
                        'goutte' => null,
                        'selenium2' => $selenium2wdhost
                    ),
                    'Moodle\BehatExtension\Extension' => array(
                        'formatters' => array(
                            'moodle_progress' => 'Moodle\BehatExtension\Formatter\MoodleProgressFormatter',
                            'moodle_list' => 'Moodle\BehatExtension\Formatter\MoodleListFormatter',
                            'moodle_step_count' => 'Moodle\BehatExtension\Formatter\MoodleStepCountFormatter'
                        ),
                        'features' => $features,
                        'steps_definitions' => $stepsdefinitions,
                    )
                ),
                'formatter' => array(
                    'name' => 'moodle_progress'
                )
            )
        );

        if (!empty($proxy)) {
            $proxyconfig = array('capabilities' => array(
                    'proxy' => array(
                        "httpProxy" => $proxy,
                        "proxyType" => "manual"
                        )
                    ));
            $config['default']['extensions']['Moodle\BehatExtension\Extension'] =
                array_merge($config['default']['extensions']['Moodle\BehatExtension\Extension'], $proxyconfig);
        }

        return symfonyyaml::dump($config, 10, 2);
    }

    /**
     * Delete directory.
     *
     * @param string $dir directory path.
     * @param bool $includingself if true then the directory itself will be removed.
     * @return bool true on success.
     */
    public static function drop_dir($dir, $includingself = false) {

        $files = scandir($dir);
        foreach ($files as $file) {
            // Don't delete the dataroot directory. Just contents.
            if (!$includingself && ($file == "." || $file == "..")) {
                continue;
            }

            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                @remove_dir($path, false);
            } else {
                @unlink($path);
            }
        }
        return true;
    }
}