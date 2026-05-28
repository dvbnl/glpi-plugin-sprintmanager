<?php

/**
 * AJAX handler that adds an open dependency on a sprint item from inside
 * the quick-edit modal. Validates capacity server-side; on overload returns
 * the error message produced by SprintMember::checkCapacityForUser so the
 * modal can show it inline without a page reload.
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

if ((int)($item->fields['plugin_sprint_sprints_id'] ?? 0) <= 0) {
    echo json_encode([
        'success' => false,
        'message' => __('Dependencies are sprint-scoped — assign this item to a sprint first.', 'sprint'),
    ]);
    return;
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
