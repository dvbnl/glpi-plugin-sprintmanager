<?php

/**
 * AJAX handler for quick sprint item edits from the meeting view.
 * Accepts the full editable fieldset; sanitization and capacity validation
 * are handled inside SprintItem::prepareInputForUpdate().
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$response = ['success' => false, 'message' => 'Request failed'];

if (!isset($_POST['id'])) {
    echo json_encode($response);
    return;
}

$item = new GlpiPlugin\Sprint\SprintItem();
if (!$item->getFromDB((int)$_POST['id'])) {
    echo json_encode($response);
    return;
}

$hasFullUpdate = Session::haveRight('plugin_sprint_item', UPDATE);
$hasOwnOnly = !$hasFullUpdate
    && Session::haveRight('plugin_sprint_item', GlpiPlugin\Sprint\Profile::RIGHT_OWN_ITEMS);
$isOwner = (int)$item->fields['users_id'] === (int)Session::getLoginUserID();

if (!$hasFullUpdate && !($hasOwnOnly && $isOwner)) {
    echo json_encode($response);
    return;
}

$update = ['id' => (int)$_POST['id']];

// is_adhoc is additionally restricted to the sprint's Scrum Master inside
// SprintItem::prepareInputForUpdate().
$allowed = ['name', 'status', 'priority', 'users_id', 'story_points', 'capacity', 'note',
    'capacity_actual',
    'proposed_sprints_id', 'is_fastlane', 'is_blocked', 'is_adhoc', 'is_parked',
    'plugin_sprint_sprintcategories_id'];

// When capacity edits are restricted to the Scrum Master, a change by someone
// else is turned into an approval request inside
// SprintItem::prepareInputForUpdate() — the field is passed through here so
// that path can see the requested value. Track the guard to skip the overflow
// prompt (the change is not applied directly for guarded users).
$isFastlane = (int)($item->fields['is_fastlane'] ?? 0) === 1;
$capacityGuarded = false;
if (
    !$isFastlane
    && GlpiPlugin\Sprint\Config::isScrumMasterOnlyCapacity()
) {
    $sprintId = (int)($item->fields['plugin_sprint_sprints_id'] ?? 0);
    $capacityGuarded = $sprintId > 0
        && !GlpiPlugin\Sprint\SprintItem::currentUserIsScrumMasterOf($sprintId);
}

foreach ($allowed as $field) {
    if (array_key_exists($field, $_POST)) {
        $update[$field] = $_POST[$field];
    }
}

if (array_key_exists('_tags_json', $_POST)) {
    $decoded = json_decode((string)$_POST['_tags_json'], true);
    $update['_tags'] = is_array($decoded) ? $decoded : [];
}

// DoD checks from the modal; only values from the global definition are stored.
if (array_key_exists('_dod_json', $_POST)) {
    $dod     = GlpiPlugin\Sprint\Config::getDefinitionDone();
    $decoded = json_decode((string)$_POST['_dod_json'], true);
    $update['done_checks'] = json_encode(array_values(array_intersect($dod, is_array($decoded) ? $decoded : [])));
}

// Epic picker is rendered for the Scrum Master only; enforce that here too.
if (array_key_exists('plugin_sprint_sprintepics_id', $_POST)
    && GlpiPlugin\Sprint\SprintItem::currentUserIsScrumMasterOf((int)($item->fields['plugin_sprint_sprints_id'] ?? 0))) {
    $update['plugin_sprint_sprintepics_id'] = (int)$_POST['plugin_sprint_sprintepics_id'];
}

// Motivation for a guarded capacity edit; picked up when the change turns
// into an approval request inside SprintItem::prepareInputForUpdate().
if (array_key_exists('capacity_reason', $_POST)) {
    $update['_capacity_request_reason'] = (string)$_POST['capacity_reason'];
}

// Meeting-view edits send the active meeting id; tag the resulting log rows
// as meeting-sourced (activity chart skips them, audit shows a "via Meeting" badge).
$meetingId = (int)($_POST['meeting_id'] ?? 0);
if ($meetingId > 0) {
    $meeting = new GlpiPlugin\Sprint\SprintMeeting();
    if (
        !$meeting->getFromDB($meetingId)
        || (int)($meeting->fields['plugin_sprint_sprints_id'] ?? 0)
            !== (int)($item->fields['plugin_sprint_sprints_id'] ?? -1)
    ) {
        $meetingId = 0;
    }
}

// Capacity overflow gate: ask the client to confirm once if this edit pushes
// the owner past capacity. Only prompt when the change *increases* their load,
// so editing name/notes of an already-over-capacity item doesn't nag.
$confirmOverflow = (int)($_POST['confirm_overflow'] ?? 0) === 1;
if (!$confirmOverflow && !$isFastlane && !$capacityGuarded) {
    $sprintId   = (int)($item->fields['plugin_sprint_sprints_id'] ?? 0);
    $targetUser = array_key_exists('users_id', $update)
        ? (int)$update['users_id'] : (int)$item->fields['users_id'];
    $targetCap  = array_key_exists('capacity', $update)
        ? (float)$update['capacity'] : (float)($item->fields['capacity'] ?? 0);
    // This item's existing contribution, so an increase can be told apart from a no-op/decrease.
    $priorContribution = ((int)$item->fields['users_id'] === $targetUser)
        ? (float)($item->fields['capacity'] ?? 0) : 0.0;

    if ($sprintId > 0 && $targetUser > 0 && $targetCap > $priorContribution) {
        $info = GlpiPlugin\Sprint\SprintMember::overflowInfo(
            $sprintId,
            $targetUser,
            $targetCap,
            (int)$item->getID()
        );
        if ($info !== null) {
            echo json_encode([
                'success'       => false,
                'needs_confirm' => true,
                'message'       => GlpiPlugin\Sprint\SprintMember::overflowConfirmMessage($info),
            ]);
            return;
        }
    }
}

$beforeLogId = $meetingId > 0
    ? GlpiPlugin\Sprint\SprintAudit::snapshotMaxLogId()
    : 0;

$result = $item->update($update);

if ($meetingId > 0) {
    GlpiPlugin\Sprint\SprintAudit::tagNewLogsAsMeetingSourced(
        $beforeLogId,
        (int)$_POST['id'],
        $meetingId
    );
}

// Drain messages queued via addMessageAfterRedirect so we relay them to the
// client instead of stacking them up for the next page load.
$messages = [];
if (isset($_SESSION['MESSAGE_AFTER_REDIRECT']) && is_array($_SESSION['MESSAGE_AFTER_REDIRECT'])) {
    foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] as $level => $msgs) {
        if (is_array($msgs)) {
            foreach ($msgs as $m) {
                $messages[] = strip_tags((string)$m);
            }
        }
    }
    $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
}

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => $messages ? implode("\n", $messages) : 'Update failed',
    ]);
    return;
}

$item->getFromDB((int)$_POST['id']);

$carryOverId       = 0;
$carryOverSprintId = (int)($_POST['carry_over_to_sprint_id'] ?? 0);
$carryOverMessage  = '';
if ($carryOverSprintId > 0) {
    $sourceSprintId = (int)($item->fields['plugin_sprint_sprints_id'] ?? 0);
    if ($carryOverSprintId === $sourceSprintId) {
        $carryOverSprintId = 0;
    } else {
        $sprint = new GlpiPlugin\Sprint\Sprint();
        if ($sprint->getFromDB($carryOverSprintId)
            && Session::haveAccessToEntity($sprint->fields['entities_id'] ?? 0)) {
            $carryOverId = GlpiPlugin\Sprint\SprintItem::carryOverTo(
                (int)$_POST['id'],
                $carryOverSprintId
            );
            if ($carryOverId > 0) {
                $carryOverMessage = sprintf(
                    __('Carried over to %s', 'sprint'),
                    (string)$sprint->fields['name']
                );
            }
        }
    }
}

$updatedTags = GlpiPlugin\Sprint\SprintItem::getTagsForItem((int)$_POST['id']);

echo json_encode([
    'success'              => true,
    'message'              => $messages ? implode("\n", $messages) : 'Item updated',
    'name'                 => (string)$item->fields['name'],
    'status'               => (string)$item->fields['status'],
    'priority'             => (int)$item->fields['priority'],
    'users_id'             => (int)$item->fields['users_id'],
    'story_points'         => (int)$item->fields['story_points'],
    'capacity'             => GlpiPlugin\Sprint\SprintMember::formatCapacity($item->fields['capacity'] ?? 0),
    'capacity_actual'      => GlpiPlugin\Sprint\SprintItem::formatActualCapacity($item->fields['capacity_actual'] ?? null),
    'note'                 => (string)($item->fields['note'] ?? ''),
    'is_fastlane'          => (int)($item->fields['is_fastlane'] ?? 0),
    'is_blocked'           => (int)($item->fields['is_blocked'] ?? 0),
    'is_adhoc'             => (int)($item->fields['is_adhoc'] ?? 0),
    'is_parked'            => (int)($item->fields['is_parked'] ?? 0),
    'category_id'          => (int)($item->fields['plugin_sprint_sprintcategories_id'] ?? 0),
    'tags'                 => $updatedTags,
    'done_checks_blob'     => implode('|', GlpiPlugin\Sprint\SprintAgility::checklist((string)($item->fields['done_checks'] ?? ''))),
    'epic_id'              => (int)($item->fields['plugin_sprint_sprintepics_id'] ?? 0),
    'tags_blob'            => GlpiPlugin\Sprint\SprintItem::tagsToBlob($updatedTags),
    'tags_pills_html'      => GlpiPlugin\Sprint\SprintItem::renderTagPills($updatedTags),
    'linked_open_badge_html' => GlpiPlugin\Sprint\SprintItem::renderLinkedItemOpenBadge($item->fields),
    'carried_over'         => $carryOverId > 0,
    'carried_over_id'      => $carryOverId,
    'carried_over_sprint'  => $carryOverSprintId,
    'carry_over_message'   => $carryOverMessage,
]);
