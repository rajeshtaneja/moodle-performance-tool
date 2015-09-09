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

use moodlehq\performancetoolkit\toolkit_util;
use Symfony\Component\Yaml\Yaml as symfonyyaml;

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
 * @package   moodlehq_performancetoolkit_sitegenerator
 * @copyright 2015 Rajesh Taneja
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class util {

    use toolkit_util;

    /**
     * Create generator_dir where all feature and context will be saved.
     *
     * @return string
     * @throws \coding_exception
     * @throws \invalid_dataroot_permissions
     */
    public static function get_tool_dir() {
        $dir = self::get_performance_dir() . DIRECTORY_SEPARATOR . 'testplangenerator';

        // Create dir if required.
        if (!is_dir($dir)) {
            make_writable_directory($dir, true);
        }
        return $dir;
    }

    /**
     * Create har dir where all har files will be saved.
     *
     * @return string
     * @throws \coding_exception
     * @throws \invalid_dataroot_permissions
     */
    public static function get_har_dir() {
        $dir = self::get_tool_dir() . DIRECTORY_SEPARATOR . 'har';

        // Create dir if required.
        if (!is_dir($dir)) {
            make_writable_directory($dir, true);
        }
        return $dir;
    }

    /**
     * Create har dir where all har files will be saved.
     *
     * @param string $label get file path for the specified label
     * @return string
     */
    public static function get_har_file_path($label) {
        // Replace spaces with _ for file.
        $label = str_replace(' ', '_', $label);
        return self::get_har_dir() . DIRECTORY_SEPARATOR . $label. '.har';
    }

    /**
     * Return path for the final testplan path, where all file related to test plan will be stored.
     *
     * @return string
     */
    public static function get_final_testplan_path() {
        $dir = self::get_tool_dir() . DIRECTORY_SEPARATOR . "moodle_testplan";
        // Create directory if not exist.
        if (!is_dir($dir)) {
            make_writable_directory($dir, true);
        }

        return $dir;
    }

    /**
     * Return final path for testplan.jmx
     *
     * @param bool $exsitingfile if true, then will return path for testplan.jmx else retun path of template.
     *             This will help for getting the actual file patch which should be reading the jmx.
     * @return string
     */
    public static function get_testplan_file_path($existingfile = false) {
        // Check if file exists, then return, else return the template.
        $testplanpath =  self::get_final_testplan_path() . DIRECTORY_SEPARATOR . 'testplan.jmx';
        if (file_exists($testplanpath) || !$existingfile) {
            return $testplanpath;
        } else {
            return __DIR__.'/../fixtures/testplan.template.jmx';
        }
    }

    /**
     * Return testplan xml.
     *
     * @return string
     */
    public static function get_testplan_xml() {
        return file_get_contents(self::get_testplan_file_path(true));
    }

    /**
     * Delete all har files in har directory.
     *
     * @return string
     * @throws \coding_exception
     * @throws \invalid_dataroot_permissions
     */
    public static function clean_har_dir() {
        return self::drop_dir(self::get_tool_dir() . '/har/', true);
    }

    /**
     * Return config value for generator.
     *
     * @return array.
     */
    public static function get_config() {
        // Check if  user has a custom config file.
        if (file_exists(__DIR__ . '/../../testplan.json')) {
            $jsonconfig = file_get_contents(__DIR__ . '/../../testplan.json');
        } else {
            $jsonconfig = file_get_contents(__DIR__ . '/../testplan.json-dist');
        }

        $config = json_decode($jsonconfig, true);
        if (empty($config)) {
            self::performance_exception("Check config file: ".$jsonconfig);
        }
        return $config;
    }

    /**
     * Set proxy which will be used by test plan generator.
     *
     * @param string $proxy proxy url.
     * @return bool.
     */
    public static function set_option($option, $value) {
        $optionfile = self::get_tool_dir() . DIRECTORY_SEPARATOR . 'testplanoptions.json';
        if (file_exists($optionfile)) {
            $contents = json_decode(file_get_contents($optionfile), true);
        } else {
            $contents = array();
        }

        $contents[$option] = $value;

        $jsoncontents = json_encode($contents);

        if (!file_put_contents($optionfile, $jsoncontents)) {
            self::performance_exception('File testplanoptions.json can not be created for storing passed user options.');
        }
    }

    /**
     * Return proxy used by the test plan generator.
     *
     * @return array.
     */
    public static function get_option($option) {
        $optionfile = self::get_tool_dir() . DIRECTORY_SEPARATOR . 'testplanoptions.json';

        if (!file_exists($optionfile)) {
            util::performance_exception("Option ".$option." is not set.");
        } else {
            $contents = json_decode(file_get_contents($optionfile), true);
            if (!empty($contents[$option])) {
                return $contents[$option];
            } else {
                util::performance_exception("Option ".$option." is not set.");
            }
        }
    }

    /**
     * Create feature contents and return contents.
     *
     * @param string $featurename resource to create.
     * @param string $sitesize size of site to create
     * @return bool|string
     */
    protected static function get_feature_contents($featurename, $sitesize) {

        $featurepath = self::get_feature_path($featurename);

        if (empty($featurepath)) {
            return false;
        }

        // Create feature file for creating resource.
        $data = file_get_contents($featurepath);

        return $data;
    }

    /**
     * Extract information form har data and return.
     * @param string $capturelabel Capture label.
     * @param string $requesttype (optional) data we are interested in. This should be either get|post
     * @return array.
     */
    public static function get_info_from_har($capturelabel, $requesttype = '') {
        global $CFG;

        $urlcomponents = parse_url($CFG->wwwroot);
        if (empty($urlcomponents['path'])) {
            $sitepath = '';
        } else {
            $sitepath = $urlcomponents['path'];
        }

        $datatoextract = array('get', 'post');

        if (!empty($requesttype) && !in_array($requesttype, $datatoextract)) {
            self::performance_exception("Wrong request type passed while getting har data");
        }

        // Get har data.
        $hardata = file_get_contents(util::get_har_file_path($capturelabel));

        $hardata = json_decode($hardata, true);

        // Everything is in log.
        $hardata = $hardata['log'];

        $entries = $hardata['entries'];

        $requests = array();

        foreach ($entries as $entrykey => $entry) {

            if (!empty($entry['response']['content']['mimeType']) &&
                $entry['response']['content']['mimeType'] == 'text/html; charset=utf-8') {
                if ($entry['request']['method'] == "POST") {
                    $postdata = array();
                    foreach ($entry['request']['postData']['params'] as $param) {
                        $postdata[$param['name']] = $param['value'];
                    }
                    $query = parse_url($entry['request']['url'], PHP_URL_QUERY);
                    if (empty($query)) {
                        $queryarray = array();
                    } else {
                        parse_str($query, $queryarray);
                    }
                    $postdata = array_merge($postdata, $queryarray);
                    $path = parse_url($entry['request']['url'], PHP_URL_PATH);
                    $path = str_replace($sitepath, '', $path);
                    $requests[] = array(
                        'path' => $path,
                        'method' => 'POST',
                        'query' => $postdata,);

                } else {
                    $query = parse_url($entries[$entrykey]['request']['url'], PHP_URL_QUERY);
                    if (empty($query)) {
                        $queryarray = array();
                    } else {
                        parse_str($query, $queryarray);
                    }
                    $path = parse_url($entries[$entrykey]['request']['url'], PHP_URL_PATH);
                    $path = str_replace($sitepath, '', $path);
                    $requests[] = array(
                        'path' => $path,
                        'method' => 'GET',
                        'query' => $queryarray,
                    );
                }

            } else if (empty($entry['response'])) {
                // If no response found then it might be a get request.
                $query = parse_url($entries[$entrykey]['request']['url'], PHP_URL_QUERY);
                if (empty($query)) {
                    $queryarray = array();
                } else {
                    parse_str($query, $queryarray);
                }
                $path = parse_url($entries[$entrykey]['request']['url'], PHP_URL_PATH);
                $path = str_replace($sitepath, '', $path);
                $requests[] = array(
                    'path' => $path,
                    'method' => 'GET',
                    'query' => $queryarray,
                );
            }
        }

        if (!empty($requesttype)) {
            foreach ($requests as $index => $request) {
                if ($request['method'] != $requesttype) {
                    unset ($requests[$index]);
                }
            }
        }

        return $requests;
    }

    /**
     * Save final har data approved by user.
     *
     * @param string $capturelabel capture label
     * @param array $request request to be saved.
     * @return bool true
     */
    public static function save_final_har_data($capturelabel, $request) {
        $updatedtestplanconfigfile = self::get_final_testplan_path() . DIRECTORY_SEPARATOR . 'testplan.json-dist';

        // If we have already written something then update the new file.
        if (file_exists($updatedtestplanconfigfile)) {
            $config = file_get_contents($updatedtestplanconfigfile);
            $config = json_decode($config, true);
        } else {
            $config = util::get_config();
        }

        $config['scenarios'][\behat_hooks::$featurefile]['requests'][$capturelabel] = $request;

        // Update config so it can be used in final release.
        file_put_contents($updatedtestplanconfigfile, json_encode($config));

        // Remove har file as it's work is done.
        unlink(self::get_har_file_path($capturelabel));

        return true;
    }
}