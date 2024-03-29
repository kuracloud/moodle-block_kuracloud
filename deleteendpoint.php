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
 * kuraCloud integration block.
 *
 * @package    block_kuracloud
 * @copyright  2017 Catalyst IT
 * @author     Matt Clarkson <mattc@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_kuracloud;

require_once(dirname(__FILE__).'/../../config.php');
require_once($CFG->libdir.'/adminlib.php');

$context = \context_system::instance();
require_capability('block/kuracloud:configureapi', $context);

admin_externalpage_setup('kuracloudconfig');
$url = new \moodle_url('/blocks/kuracloud/manageendpoints.php');

$manageendpointsstr = get_string('manageendpoints', 'block_kuracloud');

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title($manageendpointsstr);
$PAGE->set_pagelayout('standard');
$PAGE->navbar->add($manageendpointsstr, $url);

$instanceid = required_param('id', PARAM_TEXT);
$confirmed = optional_param('confirmed', false, PARAM_BOOL);

$output = $PAGE->get_renderer('block_kuracloud');


if (!empty($confirmed) && confirm_sesskey()) {
    endpoints::delete($instanceid);
    redirect('manageendpoints.php');
} else {
    echo $output->header();
    echo $output->heading($manageendpointsstr);
    echo $output->confirm(get_string('confirmendpointdelete', 'block_kuracloud'),
        "deleteendpoint.php?confirmed=1&id=".$instanceid, "manageendpoints.php");
    echo $output->footer();
}
