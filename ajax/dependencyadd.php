<?php

/**
 * AJAX handler that adds an open dependency on a sprint item from the
 * quick-edit modal. Validates capacity server-side and returns inline errors.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$response = ['success' => false, 'message' => 'Request failed'];

$itemId   = (int)($_POST['plugin_sprint_sprintitems_id'] ?? 0);
$userId   = (int)($_POST['users_id'] ?? 0);
$capacity = (int)($_POST['capacity'] ?? 0);

if ($itemId <= 0 || $userId <= 0 || $capacity <= 0) {
    echo json_encode([
        'success' => false,
        'message' => __('Please select a sprint member and a capacity > 0', 'sprint'),
    ]);
    return;
}

$item = new GlpiPlugin\Sprint\SprintItem();
if (!$item->getFromDB($itemId)) {
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

// Dependencies are sprint-scoped: use the item's sprint, or for backlog items
// (sprints_id = 0) the pre-selected proposed_sprints_id.
$sprintId  = (int)($item->fields['plugin_sprint_sprints_id'] ?? 0);
$isBacklog = $sprintId <= 0;
if ($isBacklog) {
    $sprintId = (int)($item->fields['proposed_sprints_id'] ?? 0);
}

if ($sprintId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => __('Dependencies are sprint-scoped — assign this item to a sprint (or pre-select a sprint on the backlog) first.', 'sprint'),
    ]);
    return;
}

// From the backlog, the helper must already be a member of the pre-selected sprint.
if ($isBacklog) {
    $isMember = countElementsInTable(
        GlpiPlugin\Sprint\SprintMember::getTable(),
        ['plugin_sprint_sprints_id' => $sprintId, 'users_id' => $userId]
    ) > 0;
    if (!$isMember) {
        echo json_encode([
            'success' => false,
            'message' => __('You can only add a dependency on a member of the pre-selected sprint.', 'sprint'),
        ]);
        return;
    }
}

// Confirm once before over-committing a helper past sprint capacity. The
// allocation still goes through on confirm — this is a guard rail, not a block.
$confirmOverflow = (int)($_POST['confirm_overflow'] ?? 0) === 1;
if (!$confirmOverflow) {
    $info = GlpiPlugin\Sprint\SprintMember::overflowInfo($sprintId, $userId, $capacity);
    if ($info !== null) {
        echo json_encode([
            'success'       => false,
            'needs_confirm' => true,
            'message'       => GlpiPlugin\Sprint\SprintMember::overflowConfirmMessage($info),
        ]);
        return;
    }
}

$rel    = new GlpiPlugin\Sprint\SprintItemDependency();
$newId  = $rel->add([
    'plugin_sprint_sprintitems_id' => $itemId,
    'users_id'                     => $userId,
    'capacity'                     => $capacity,
    'is_resolved'                  => 0,
]);

$messages = [];
$warnings = [];
if (isset($_SESSION['MESSAGE_AFTER_REDIRECT']) && is_array($_SESSION['MESSAGE_AFTER_REDIRECT'])) {
    foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] as $level => $msgs) {
        if (is_array($msgs)) {
            foreach ($msgs as $m) {
                $text = strip_tags((string)$m);
                $messages[] = $text;
                if ((int)$level === WARNING) {
                    $warnings[] = $text;
                }
            }
        }
    }
    $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
}

if (!$newId) {
    echo json_encode([
        'success' => false,
        'message' => $messages ? implode("\n", $messages) : __('Could not add dependency', 'sprint'),
    ]);
    return;
}

$summaries = GlpiPlugin\Sprint\SprintItemDependency::getOpenSummariesForItems([$itemId]);
$openDeps  = $summaries[$itemId] ?? [];
$openCount = count($openDeps);

$baseMessage = sprintf(__('Dependency added: %s (%d%%)', 'sprint'), getUserName($userId), $capacity);

echo json_encode([
    'success'    => true,
    'message'    => $baseMessage,
    'warning'    => $warnings ? implode("\n", $warnings) : '',
    'open_count' => $openCount,
    'open_deps'  => $openDeps,
    'helper'     => [
        'users_id' => $userId,
        'name'     => getUserName($userId),
        'capacity' => $capacity,
    ],
]);
