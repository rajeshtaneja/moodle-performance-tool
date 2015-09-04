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
 * Helper class to interact with BrowserMobProxy.
 *
 * @package   moodlehq_performancetoolkit_testplangenerator
 * @copyright 2015 rajesh Taneja
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace moodlehq\performancetoolkit\testplangenerator;

use moodlehq\performancetoolkit\testplangenerator\util;

class browsermobproxyclient {

    /** @var string BrowserMobProxy url */
    private $proxyurl;

    /** @var  string port on which proxy is running. */
    private $port;

    /**
     * Class constructor
     *
     * @param string $proxyurl proxy URL for BrowserMobProxy.
     */
    public function __construct($proxyurl) {

        if (preg_match("/^http(s)?:\/\/.*/i", $proxyurl) == 0) {
            $proxyurl = "http://" . $proxyurl;
        }

        $this->proxyurl = $proxyurl;
        util::set_option('proxyurl', $proxyurl);
    }

    /**
     * Create new connection to the proxy
     *
     * @param string $port speficy port if you want to open proxy at specific port.
     * @return string the url for proxy.
     */
    public function create_connection($port='') {

        $parts = parse_url($this->proxyurl);
        $hostname = $parts["host"];

        // Create request to open proxy connection.
        $options = array();
        $headers = array();
        if (!empty($port)) {
            $options = array('port' => $port);
        }

        $response = self::curl_post($this->proxyurl . "/proxy/", $options);
        // Get port on which new proxy connection is created.
        $decoded = json_decode($response, true);
        if ($decoded) {
            $this->port = $decoded["port"];
            util::set_option('proxyport', $this->port);
        }

        self::new_har();
        // Return url on which the request will be handled.
        return $hostname . ":" . $this->port;
    }

    /**
     * Close connection to the proxy
     *
     * @return void
     */
    public function close_connection() {
        return self::curl_delete($this->proxyurl. "/proxy/" . $this->port);
    }

    /**
     * Method for creating a new HAR file
     *
     * @param string $label optional label
     *
     * @return string
     */
    public static function new_har($label = '') {
        $proxyurl = util::get_option('proxyurl');
        $proxyport = util::get_option('proxyport');

        $data = array(
                "captureContent" => 'true',
                "initialPageRef" => $label,
                "captureHeaders" => 'true',
                "captureBinaryContent" => 'true',
                );
        $url = $proxyurl . "/proxy/" . $proxyport . "/har";
        $response = self::curl_put($url, $data);
        return $response;
    }

    /**
     * Send request.
     *
     * @param string $url
     * @param array $data
     * @return string mixed
     */
    public static function curl_post($url, $data = array()) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    /**
     * Put delete request
     *
     * @param string $url
     * @param array $data
     * @return string mixed
     */
    public static function curl_delete($url, $data=array()) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);

        curl_close($ch);
        return $result;
    }

    /**
     * Put delete request
     *
     * @param string $url
     * @param array $data
     * @return string mixed
     */
    public static function curl_put($url, $data=array()) {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS,http_build_query($data));

        $response = curl_exec($ch);
        if(!$response) {
            return false;
        }

        curl_close($ch);
        return $response;
    }

    /**
     * Initialise HAR file, removing any old data.
     *
     * @return string json encoded har data.
     */
    public static function get_har($label = '') {
        return self::new_har($label);
    }
}