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

use moodlehq\performancetoolkit\sitegenerator\util as dataplanutil;

/**
 * Utility to write test plan.
 *
 * @package    moodlehq_performancetoolkit_testplangenerator
 * @copyright  2015 Rajesh Taneja <rajesh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Utility to write test plan.
 *
 * @package   moodlehq_performancetoolkit_sitegenerator
 * @copyright 2015 Rajesh Taneja
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testplan_writer {

    /**
     * Start test plan.
     *
     * @param string $size size of the site.
     */
    public static function start_testplan($size) {
        global $CFG;

        $urlcomponents = parse_url($CFG->wwwroot);
        if (empty($urlcomponents['path'])) {
            $urlcomponents['path'] = '';
        }

        // Update fixed attributes.
        $propertiestoupdate = array(
            'moodleversion' => $CFG->version,
            'dataplansize' => get_config('core', 'performancesitedata'),
            'host' => $urlcomponents['host'],
            'sitepath' => $urlcomponents['path'],
            'dataplanversion' => dataplanutil::get_tool_version(),
            'testplansize' => $size,
            'testplanversion' => util::get_tool_version(),
        );

        foreach ($propertiestoupdate as $prop => $value) {
            $list['//elementProp[@name=\'' . $prop . '\']//stringProp[@name=\'Argument.value\']'] = $value;
        }

        // Update throughput and any other global value found.
        $globalconfig = util::get_config();
        $globalconfig = $globalconfig['global'];

        foreach ($globalconfig as $key => $value) {
            if (is_array($value)) {
                $value = $value[$size];
            }
            $list['//stringProp[@name=\''.$key.'\']'] = '${__property(throughput,throughput,'.$value.')}';
        }

        self::replace_append_xml_in_testplan($list);
    }

    /**
     * This will create a new threadgroup. Normally called from behat_hooks::before_scenario().
     *
     * @param string $featurename featurename
     * @param string $threadgroupname threadgroup name.
     */
    public static function start_thread_group($featurename, $threadgroupname) {
        $size = strtolower(util::get_option('size'));
        $globalconfig = util::get_feature_config($featurename);
        $globalconfig = $globalconfig['execution'];

        // If hard-coded value given (like for warmup) then don't allow modifications for the given value.
        if (isset($globalconfig['users'][$size])) {
            $users = '${__P(users,'.$globalconfig['users'][$size].')}';
        } else {
            $users = $globalconfig['users'];
        }

        if (isset($globalconfig['rampup'][$size])) {
            $rampup = '${__P(rampup,'.$globalconfig['rampup'][$size].')}';
        } else if (isset($globalconfig['rampup'])) {
            $rampup = $globalconfig['rampup'];
        } else {
            $rampup = 5;
        }

        if (isset($globalconfig['loops'][$size])) {
            $loops = '${__P(loops,'.$globalconfig['loops'][$size].')}';
        } else if (isset($globalconfig['loops'])) {
            $loops = $globalconfig['loops'];
        } else {
            $loops = 5;
        }

        $replacements = array (
            'threadgroupname' => $threadgroupname,
            'users' => $users,
            'rampup' => $rampup,
            'loops' => $loops
        );

        $threadgroup = self::get_testplan_tag_xml('threadgroup', $replacements);

        self::replace_append_xml_in_testplan(array(), $threadgroup, '//jmeterTestPlan/hashTree//hashTree');
        // Append empty hashTree.
        self::replace_append_xml_in_testplan(array(), '<hashTree/>', '//jmeterTestPlan/hashTree//hashTree');
    }

    /**
     * Retrun xml of recorder to be added to each thread group.
     * It assumes follwoing to be under current directory of execution
     * - runs_samples directory
     * - recorder.bsf
     *
     * @return string
     */
    public static function result_collector() {
        $resultcollector = self::get_testplan_tag_xml('resultcollector');

        // Append result collector to testplan.
        $appendxpath = '//ThreadGroup[@testname="'.\behat_hooks::$threadgroupname.'"]/following-sibling::hashTree';

        self::replace_append_xml_in_testplan(array(), $resultcollector, $appendxpath);
        // Append hashTree after resultcollector. This is needed by jMeter jmx.
        self::replace_append_xml_in_testplan(array(), '<hashTree/>', $appendxpath);


        // Add bean shell listner. This is what is used to extract performance data from moodle page.`
        $beanshell= self::get_testplan_tag_xml('beanshelllistner');
        self::replace_append_xml_in_testplan(array(), $beanshell, $appendxpath);
        self::replace_append_xml_in_testplan(array(), '<hashTree/>', $appendxpath);
    }

    /**
     * Create csv data for user, called from the behat step of login as any.
     *
     * @param $filepath
     * @param $rolearchtype
     */
    public static function create_csv_data($filepath, $filename, $rolearchtype) {
        $replacements = array('filepath' => $filepath, 'csvfilename' => $filename, 'rolearchtype' => $rolearchtype);
        $csvhashtree = self::get_testplan_tag_xml('csvdataset', $replacements);

        $appendcsvxpath = '//ThreadGroup[@testname="'.\behat_hooks::$threadgroupname.'"]/following-sibling::hashTree';
        self::replace_append_xml_in_testplan(array(), $csvhashtree, $appendcsvxpath);
        self::replace_append_xml_in_testplan(array(), '<hashTree/>', $appendcsvxpath);
    }

    /**
     * Create csv data for user, called from the behat step of login as any.
     *
     * @param $filepath
     * @param $rolearchtype
     */
    public static function add_request_data($capturelabel, $request, $assertiontext, $regexglobal) {

        // Create http request here.
        $replacements = array(
            'capturelabel' => $capturelabel,
            'path' => $request['path'],
            'method' => $request['method']
        );
        $httpssamplerproxy = self::get_testplan_tag_xml('httpsamplerproxy', $replacements);
        // Append httpssamplerproxy.
        $appendxpath = '//ThreadGroup[@testname="'.\behat_hooks::$threadgroupname.'"]/following-sibling::hashTree';
        self::replace_append_xml_in_testplan(array(), $httpssamplerproxy, $appendxpath);

        // Check if there is any query param.
        if (!empty($request['query'])) {
            $appendelementpropxpath = '//ThreadGroup[@testname="'.\behat_hooks::$threadgroupname.'"]/following-sibling::hashTree'.
                '//HTTPSamplerProxy[@testname="'.$capturelabel.'"]/elementProp/collectionProp';

            foreach ($request['query'] as $name => $value) {
                $elementpropxml = self::get_testplan_tag_xml('elementprop', array('name' => $name, 'value'=> $value));
                self::replace_append_xml_in_testplan(array(), $elementpropxml, $appendelementpropxpath);
            }
        }

        // Append empty hashTree.
        self::replace_append_xml_in_testplan(array(), '<hashTree/>', $appendxpath);

        // Check if we need to add any assertion.
        if (!empty($assertiontext)) {
            $replacements = array(
                'capturelabel' => $capturelabel,
                'searchstring' => htmlentities($assertiontext, ENT_QUOTES),
            );
            $appendassertionxpath = '//ThreadGroup[@testname="'.\behat_hooks::$threadgroupname.'"]/following-sibling::hashTree'.
                '//HTTPSamplerProxy[@testname="'.$capturelabel.'"]/following-sibling::hashTree';

            $responseassertion = self::get_testplan_tag_xml('responseassertion', $replacements);
            // Append hashTree after resultcollector. This is needed by jMeter jmx.
            self::replace_append_xml_in_testplan(array(), $responseassertion, $appendassertionxpath);
            // Append empty hashTree.
            self::replace_append_xml_in_testplan(array(), '<hashTree/>', $appendassertionxpath);
        }

        if (!empty($regexglobal)) {
            $appendregexxpath = '//ThreadGroup[@testname="' . \behat_hooks::$threadgroupname . '"]/following-sibling::hashTree' .
                '//HTTPSamplerProxy[@testname="' . $capturelabel . '"]/following-sibling::hashTree';

            foreach ($regexglobal as $refname => $regex) {
                $replacements = array(
                    'capturelabel' => $capturelabel,
                    'refname' => $refname,
                    'regex' => htmlentities($regex, ENT_QUOTES)
                );
                $responseassertion = self::get_testplan_tag_xml('regexextractor', $replacements);

                // Append hashTree after resultcollector. This is needed by jMeter jmx.
                self::replace_append_xml_in_testplan(array(), $responseassertion, $appendregexxpath);
                // Append empty hashTree.
                self::replace_append_xml_in_testplan(array(), '<hashTree/>', $appendregexxpath);
            }
        }
    }

    /**
     * Helper function to replace required variables in dom.
     *
     * @param array $list list of xpath and value for replacement
     * @param string|DomDocument $dom dom.
     * @return \DOMDocument
     */
    private static function replace_variables($list, $dom) {

        if (is_string($dom)) {
            $xmldom = new \DOMDocument();
            $xmldom->loadXML($dom);
            $dom = $xmldom;
        }

        $domxpath = new \DOMXPath($dom);

        // Replace all values in xml.
        foreach ($list as $xpath => $value) {
            $query = $domxpath->query($xpath);
            $query->item(0)->nodeValue = $value;
        }
        return $dom;
    }

    /**
     * Appeand xml to testplan at specified path.
     *
     * @param array $list list of xpath and value for replacement.
     * @param string $xml (optional) appeand xml.
     * @param string $insertnodepath insert node xpath.
     * @param bool $debug useful when debugging new tag xml.
     */
    private static function replace_append_xml_in_testplan($list, $xml='', $insertnodepath='', $debug = false) {

        $dom = new \DOMDocument();
        $dom->load(util::get_testplan_file_path(true));

        if (!empty($xml)) {
            $xmldom = self::replace_variables($list, $xml);
        } else {
            $dom = self::replace_variables($list, $dom);
        }

        if (!empty($xmldom)) {
            $xmldomxpath = new \DOMXPath($dom);
            $query = $xmldomxpath->query($insertnodepath);

            $importednode = $dom->importNode($xmldom->documentElement, TRUE);

            if ($debug) {
                var_dump($insertnodepath);
                var_dump($query);
            }

            $query->item(0)->appendChild($importednode);
        }
        $dom->save(util::get_testplan_file_path());
    }

    /**
     * Return xml for the given tag.
     *
     * @param string $tag name of the tag
     * @param array $replacements search=> replace in xml. with #!search!# => replace
     * @return string xml for the testplan tag.
     * @throws \moodle_exception
     */
    public static function get_testplan_tag_xml($tag, $replacements = array()) {
        $xmlfilepath = __DIR__."/../fixtures/".$tag.".xml";
        if (!file_exists($xmlfilepath)) {
            util::performance_exception("Xml for tag ".$tag." not found");
        }

        $tagxml = file_get_contents($xmlfilepath);
        // Replace all required values.
        foreach ($replacements as $search => $replace) {
            $tagxml = str_replace('#!'.$search.'!#', $replace, $tagxml);
        }

        return $tagxml;
    }

}