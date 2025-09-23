<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__.'/../../config.php');
require_once(__DIR__.'/classes/api.php');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_login();
require_sesskey();

$action     = required_param('action', PARAM_ALPHAEXT);
$courseid   = optional_param('courseid', 0, PARAM_INT);
$groupid    = optional_param('groupid', 0, PARAM_INT);
$from       = optional_param('from', 0, PARAM_INT);
$to         = optional_param('to', 0, PARAM_INT);
$categoryid = optional_param('categoryid', 0, PARAM_INT);
$q          = optional_param('q', '', PARAM_TEXT);
// priority for save_note (optional)
$priority   = optional_param('priority', 0, PARAM_INT);

function send_json($data){ echo json_encode($data); exit; }

try {
    $api = new \local_admindash\api();
    switch ($action) {
        case 'init':                send_json($api->init());
        case 'get_courses':         send_json(['coursesList'=>$api->get_courses($categoryid, $q)]);
        case 'get_groups':          send_json(['groups'=>$api->get_groups_for_course($courseid)]);
        case 'grades_by_group':     if (!$courseid) { throw new moodle_exception('courseidrequired'); } send_json($api->get_grades_by_group($courseid, $groupid, $from, $to));
        case 'attendance_by_group': if (!$courseid) { throw new moodle_exception('courseidrequired'); } send_json($api->get_attendance_by_group($courseid, $groupid, $from, $to));
    case 'save_note':           send_json($api->save_note($courseid, $groupid,
                    required_param('userid', PARAM_INT),
                    required_param('itemtype', PARAM_ALPHA),
                    required_param('itemid', PARAM_INT),
                    required_param('note', PARAM_RAW_TRIMMED),
                    optional_param('priority', 0, PARAM_INT)));
        default: throw new moodle_exception('unknownaction');
    }
} catch (Throwable $e) {
    // Log the detailed exception on server side for debugging but don't expose internals to the client.
    debugging($e->getMessage() . '\n' . $e->getTraceAsString(), DEBUG_DEVELOPER);
    http_response_code(500);
    send_json(['error' => true, 'message' => get_string('internalerror', 'local_admindash')]);
}
