<?php

/** Guided meeting rail session state: start/end, current phase, per-phase notes. */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_sprint', READ);

$response = ['success' => false, 'message' => 'Request failed'];

$meetingId = (int)($_POST['meeting_id'] ?? 0);
$meeting   = new GlpiPlugin\Sprint\SprintMeeting();
if ($meetingId <= 0 || !$meeting->getFromDB($meetingId)) {
    echo json_encode($response);
    return;
}

$sprintId    = (int)($meeting->fields['plugin_sprint_sprints_id'] ?? 0);
$meetingType = (string)($meeting->fields['meeting_type'] ?? '');
$phases      = GlpiPlugin\Sprint\SprintMeeting::getPhaseDefinitions($meetingType);
if ($sprintId <= 0 || count($phases) === 0) {
    echo json_encode($response);
    return;
}

// Only the sprint's Scrum Master or the meeting facilitator may drive.
$uid           = (int)Session::getLoginUserID();
$isScrumMaster = GlpiPlugin\Sprint\SprintItem::currentUserIsScrumMasterOf($sprintId);
$isFacilitator = ((int)($meeting->fields['users_id'] ?? 0) === $uid)
    && GlpiPlugin\Sprint\SprintAgility::isCurrentUserMember($sprintId);
if (!GlpiPlugin\Sprint\Sprint::canUpdate() || (!$isScrumMaster && !$isFacilitator)) {
    $response['message'] = __('Only the Scrum Master or the facilitator can drive the meeting', 'sprint');
    echo json_encode($response);
    return;
}

$now    = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
$action = (string)($_POST['action'] ?? '');
$update = ['id' => $meetingId];
$logged = false;

switch ($action) {
    case 'start_meeting':
        $update['meeting_status']   = 'in_progress';
        $update['current_phase']    = 0;
        $update['started_at']       = $now;
        $update['phase_started_at'] = $now;
        $update['ended_at']         = 'NULL';
        $logged = true;
        break;

    case 'set_phase':
        $phase = max(0, min(count($phases) - 1, (int)($_POST['phase'] ?? 0)));
        $update['current_phase']    = $phase;
        $update['phase_started_at'] = $now;
        if ((string)($meeting->fields['meeting_status'] ?? '') !== 'in_progress') {
            $update['meeting_status'] = 'in_progress';
        }
        break;

    case 'save_phase_note':
        $phaseKey  = (string)($_POST['phase_key'] ?? '');
        $validKeys = array_column($phases, 'key');
        if (!in_array($phaseKey, $validKeys, true)) {
            $response['message'] = 'Unknown phase';
            echo json_encode($response);
            return;
        }
        $notes = json_decode((string)($meeting->fields['phase_notes'] ?? ''), true) ?: [];
        $note  = (string)($_POST['note'] ?? '');
        if (trim($note) === '') {
            unset($notes[$phaseKey]);
        } else {
            $notes[$phaseKey] = $note;
        }
        $update['phase_notes'] = json_encode($notes);
        break;

    case 'end_meeting':
        $update['meeting_status'] = 'completed';
        $update['ended_at']       = $now;
        $logged = true;
        break;

    default:
        $response['message'] = 'Unknown action';
        echo json_encode($response);
        return;
}

// Phase hops and note autosaves would flood the meeting's history log;
// only the start/end transitions are worth a history line.
if (!$meeting->update($update, $logged)) {
    echo json_encode($response);
    return;
}

$meeting->getFromDB($meetingId);
echo json_encode([
    'success'             => true,
    'status'              => (string)($meeting->fields['meeting_status'] ?? 'open'),
    'current_phase'       => (int)($meeting->fields['current_phase'] ?? 0),
    'phase_started_at_ts' => !empty($meeting->fields['phase_started_at'])
        ? (int)strtotime((string)$meeting->fields['phase_started_at']) : 0,
    'server_now_ts'       => time(),
]);
