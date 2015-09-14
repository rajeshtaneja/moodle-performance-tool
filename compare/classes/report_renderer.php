<?php


/**
 * Reports renderer.
 *
 * @package moodle-performance-comparison
 * @copyright 2013 David Monllaó
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_renderer {

    const FILTERS_PER_ROW = 3;

    protected $report;

    /**
     * Adds the report data
     *
     * @param report $report
     * @return void
     */
    public function __construct(report $report) {
        $this->report = $report;
    }

    /**
     * Outputs wrapper.
     *
     * @return void
     */
    public function render() {
        echo $this->output_head();

        echo $this->output_form();
        echo $this->output_runs_info();
        echo $this->output_charts_containers();
        echo $this->output_differences();

        // Link to Sam's tool with detailed data (just the first 2 runs).
        if (!empty($_GET['timestamps']) && count($_GET['timestamps']) >= 2) {
            $urlparams = 'before=' . $_GET['timestamps'][1] . '&after=' . $_GET['timestamps'][0];
            echo '<div class="switchtool"><a href="details.php?' . $urlparams . '" target="_blank">See numeric info</a></div>';
        }


    }

    /**
     * All JS & CSS.
     *
     * @return string HTML
     */
    protected function output_head() {

        google_charts_renderer::render();

        $head = '<script src="webapp/js/jquery.js"></script>';
        $head .= '<script src="webapp/js/jquery-ui.js"></script>';
        $head .= '<script src="webapp/js/ui.js"></script>';
        $head .= '<link rel="stylesheet" type="text/css" href="webapp/css/styles.css" />';
        $head .= '<link rel="stylesheet" type="text/css" href="webapp/css/redmond/jquery-ui.css" />';

        return $head;
    }

    /**
     * Outputs the form.
     *
     * @return string Form HTML
     */
    protected function output_form() {

        list($runs, $runsvalues) = $this->report->get_run_files_info();

        // Filter runs form.
        $output = '<form method="get">';
        $fields = array();
        foreach (test_plan_run::$runparams as $key => $name) {
            $field = $name . ': <select id="id_' . $key . '" name="filters[' . $key . ']">';

            // The options existing in the runs files.
            $field .= '<option value="">(All options)</option>';
            if ($runsvalues) {
                foreach ($runsvalues[$key] as $value) {

                    // The selected one if there is one.
                    $selectedstr = '';
                    if (!empty($_GET['filters'][$key]) && $_GET['filters'][$key] == $value) {
                        $selectedstr = 'selected="selected"';
                    }

                    $field .= '<option value="' . $value . '" ' . $selectedstr . '>' . $value . '</option>';
                }
            }
            $field .= '</select>';
            $fields[] = $field;
        }

        // We want a new tr for the button so we add enough empty tds to have a full row.
        $needsemptycells = count($fields) % self::FILTERS_PER_ROW;
        if ($needsemptycells) {
            for ($i = 0; $i < (self::FILTERS_PER_ROW - $needsemptycells); $i++) {
                $fields[] = '';
            }
        }

        $fields[] = '<input type="submit" value="Filter runs"/>';
        $output .= $this->create_table('Filter runs', $fields, self::FILTERS_PER_ROW);
        $output .= '</form>';

        // Get threadgroup results in different selecet box.
        $threadgroups = array();
        foreach ($runs as $runfile => $testplanrun) {
            preg_match("/^(\d*)_(.*)/", $runfile, $match);
            // $threadgroups['Threadgroupname']['run']
            $threadgroups[$match[2]][$match[1]] = $testplanrun;
        }

        foreach ($threadgroups as $threadgroup => $runs) {
            // Select runs form.
            $output .= '<form method="get">';

            // Restrict the select size.
            $sizestr = '';
            $sizelimit = 20;
            if (count($runs) > $sizelimit) {
                $sizestr = ' size="' . $sizelimit . '" ';
            } else {
                $sizestr = ' size="' . count($runs) . '" ';
            }

            $runsselect = '<select name="timestamps[]" ' . $sizestr . ' multiple="multiple">' . PHP_EOL;
            if ($runs) {
                foreach ($runs as $timestamp => $run) {
                    $selectedstr = '';
                    if (!empty($_GET['timestamps']) && in_array($timestamp, $_GET['timestamps'])) {
                        $selectedstr = 'selected="selected"';
                    }
                    $runsselect .= '<option value="' . $timestamp . '" ' . $selectedstr . '>' . $run->get_run_info_extended_string() . '</option>';
                }
            }
            $runsselect .= '</select>' . PHP_EOL;
            $runsselect .= '<input type="hidden" name="threadgroupname" value="'.$threadgroup.'"/>' . PHP_EOL;

            // Add a message if there are no runs.
            if (!$runs) {
                $link = 'https://github.com/moodlehq/moodle-performance-comparison/blob/master/README.md#usage';
                $runsselect .= '<br/><br/>There are no runs, more info in <a href="' . $link . '" target="_blank">' . $link . '</a>';
            }

            // Keep the filter runs values if there is something filtered.
            if (!empty($_GET['filters'])) {
                foreach ($_GET['filters'] as $filter => $value) {
                    if (!empty($value)) {
                        $runsselect .= '<input type="hidden" name="filters[' . $filter . ']" value="' . $value . '"/>';
                    }
                }
            }

            $runsselect .= '<br/><br/><input type="submit" value="View comparison"/>';
            $output .= $this->create_table('Select runs: '. $threadgroup, array($runsselect), 1);
            $output .= '</form>';
        }

        return $output;
    }

    /**
     * Outputs all the runs info in a one row table.
     *
     * @return string HTML
     */
    protected function output_runs_info() {

        if (!$this->report->get_runs()) {
            return false;
        }

        // Is the web interface read only?.
        $properties = properties_reader::get('readonlyweb');

        $runsinfo = array();
        foreach ($this->report->get_runs() as $run) {

            // Run vars.
            $runinfo = $run->get_run_info();

            $filenamestr = 'filename=' . $run->get_filename(false);

            // Link to download the run.
            $runinfo->downloadlink = '<a href="download_run.php?' . $filenamestr . '">Download</a>';

            // Only if the web is read/write.
            if (empty($properties['readonlyweb'])) {
                // Link to delete the run.
                $returnurlstr = 'returnurl=' . urlencode('timestamps[]=' . implode('&timestamps[]=', $_GET['timestamps']));
                $runinfo->deletelink = '<a href="delete_run.php?' . $filenamestr . '&' . $returnurlstr . '" class="delete-run">Delete</a>';
            }

            $runsinfo[] = $this->get_info_container($runinfo);
        }

        $output = '<div id="confirm-delete-dialog" title="Delete" class=""><p>Are you sure you want to delete this run?</p></div>';
        $output .= $this->create_table('Runs information', $runsinfo, count($runsinfo), '', 'runs-info');

        return $output;
    }

    /**
     * Outputs the charts containers.
     *
     * @return string HTML
     */
    protected function output_charts_containers() {

        if (!$this->report->get_runs()) {
            return false;
        }

        $output = '';

        // Stop on errors.
        if ($errors = $this->report->get_errors()) {
            $output .= '<div class="centeredcontainer">';
            foreach ($errors as $error) {
                $output .= '<div class="error">' . $error . '</div>' . PHP_EOL;
            }
            $output .= '</div>';
            return $output;
        }

        // Number of columns per row.
        $containers = $this->report->get_containers();
        foreach ($this->report->get_charts_declaration() as $chartsdeclaration) {
            $output .= $this->create_table($chartsdeclaration['name'], $containers[$chartsdeclaration['id']], $chartsdeclaration['perrow']);
        }

        return $output;
    }

    /**
     * Outputs all the major differences between runs.
     *
     * @return string HTML
     */
    protected function output_differences() {

        if (!$branches = $this->report->get_big_differences()) {
            return '';
        }

        $lines = array();
        foreach ($branches as $branchnames => $changes) {

            // Output which branches differs.
            $lines[] = '<b>' . $branchnames . '</b>';
            $lines[] = '';

            foreach ($changes as $state => $data) {
                foreach ($data as $var => $step) {
                    foreach ($step as $stepname => $msg) {
                        $lines[] = '<div class="' . $state . '">' . $var . ': ' . $state . ' - ' . $stepname . ', ' . $msg . '</div><br/>';
                    }
                }
            }
        }

        return $this->create_table('Performance changes between runs', $lines, 1);
    }

    /**
     * Returns a run's info.
     *
     * @param stdClass $runinfo
     * @return string HTML
     */
    protected function get_info_container(stdClass $runinfo) {

        $container = '<h2>' . $runinfo->rundesc . '</h2><ul>' . PHP_EOL;
        foreach ($runinfo as $varname => $value) {
            if (is_scalar($value)) {
                $container .= '<li><b>' . $varname . ': </b>' . $value . '</li>';
            }
        }

        return $container;
    }

    /**
     * Generates the HTML of a table based on the provided cells.
     *
     * @param string A title to show as the table heading
     * @param array $cells The cells data
     * @param int $nrows The number of cols per row
     * @param string $class The CSS class
     * @param string $id The id of the div
     * @return string HTML
     */
    protected function create_table($title, $cells, $ncols, $class = '', $id = false) {

        if ($class == '') {
            $class = 'container';
        }

        $idstr = '';
        if ($id) {
            $idstr = ' id="' . $id . '"';
        }

        $output = '<div class="section"' . $idstr . '>' . PHP_EOL;
        $output .= '<h2>' . $title . '</h2>' . PHP_EOL;
        $output .= '<table class="' . $class . '"><tr>' . PHP_EOL;
        for ($i = 0; $i < count($cells); $i++) {
            $output .= '<td>' . $cells[$i] . '</td>';
            if (($i + 1) % $ncols == 0) {
                $output .= '</tr><tr>';
            }
        }
        $output .= '</tr></table>' . PHP_EOL;
        $output .= '</div>';

        return $output;
    }

}
