<?php

/** Guided meeting rail session state: start/end, current phase, per-phase notes. */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_sprint', READ);

$response = ['success' => false, 'message' => __('Request failed', 'sprint')];

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
            $response['message'] = __('Unknown phase', 'sprint');
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

    case 'set_prev_sprint':
        // Retrospective follow-up: the facilitator overrides the predecessor
        // found in the sprint series (0 = back to automatic). Only earlier
        // sprints of the same entity are accepted.
        if ($meetingType !== GlpiPlugin\Sprint\SprintMeeting::TYPE_RETROSPECTIVE) {
            echo json_encode($response);
            return;
        }
        $sprint = new GlpiPlugin\Sprint\Sprint();
        $chosen = (int)($_POST['prev_sprint_id'] ?? 0);
        if (!$sprint->getFromDB($sprintId)
            || ($chosen > 0 && !isset(GlpiPlugin\Sprint\SprintAgility::resolvePredecessor($sprint->fields)['choices'][$chosen]))) {
            echo json_encode($response);
            return;
        }
        $notes = json_decode((string)($meeting->fields['phase_notes'] ?? ''), true) ?: [];
        if ($chosen > 0) {
            $notes[GlpiPlugin\Sprint\SprintMeeting::PREV_SPRINT_NOTE_KEY] = $chosen;
        } else {
            unset($notes[GlpiPlugin\Sprint\SprintMeeting::PREV_SPRINT_NOTE_KEY]);
        }
        $update['phase_notes'] = json_encode($notes);
        break;

    case 'end_meeting':
        $update['meeting_status'] = 'completed';
        $update['ended_at']       = $now;
        $logged = true;
        break;

    default:
        $response['message'] = __('Unknown action', 'sprint');
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

// Retrospective: once the facilitator moves past "Cluster & vote" (or ends
// the meeting), the top-voted themes become actions, once per sprint.
$promoted = 0;
if ($meetingType === GlpiPlugin\Sprint\SprintMeeting::TYPE_RETROSPECTIVE
    && in_array($action, ['set_phase', 'end_meeting'], true)) {
    $voteIndex = array_search('vote', array_column($phases, 'key'), true);
    $current   = (int)($meeting->fields['current_phase'] ?? 0);
    if ($voteIndex !== false && ($action === 'end_meeting' || $current > (int)$voteIndex)) {
        $promoted = GlpiPlugin\Sprint\SprintAgility::promoteTopVotedInputs($sprintId, $meetingId);
    }
}

echo json_encode([
    'success'             => true,
    // > 0 makes the browser reload: the new actions and the greyed-out
    // remaining input are rendered server-side.
    'promoted'            => $promoted,
    // Echoed so the notes autosave can ignore responses to older saves.
    'seq'                 => (int)($_POST['seq'] ?? 0),
    'phase_key'           => (string)($_POST['phase_key'] ?? ''),
    'status'              => (string)($meeting->fields['meeting_status'] ?? 'open'),
    'current_phase'       => (int)($meeting->fields['current_phase'] ?? 0),
    'phase_started_at_ts' => !empty($meeting->fields['phase_started_at'])
        ? (int)strtotime((string)$meeting->fields['phase_started_at']) : 0,
    'server_now_ts'       => time(),
]);
