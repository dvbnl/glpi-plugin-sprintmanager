<?php

/**
 * AJAX handler for the "Assign all ready" backlog action. Assigns every item
 * with a pre-selected sprint (proposed_sprints_id > 0) the user may assign;
 * skips and reports the rest.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$hasFullUpdate = Session::haveRight('plugin_sprint_item', UPDATE);

$item     = new GlpiPlugin\Sprint\SprintItem();
$ready    = $item->find([
    'plugin_sprint_sprints_id' => 0,
    ['NOT' => ['proposed_sprints_id' => 0]],
]);

$assigned = 0;
$skipped  = 0;
$sprintCache = [];

foreach ($ready as $row) {
    $id       = (int)$row['id'];
    $sprintId = (int)($row['proposed_sprints_id'] ?? 0);
    if ($sprintId <= 0) {
        continue;
    }

    if (!isset($sprintCache[$sprintId])) {
        $sp = new GlpiPlugin\Sprint\Sprint();
        $sprintCache[$sprintId] = $sp->getFromDB($sprintId) ? $sp : false;
    }
    if ($sprintCache[$sprintId] === false) {
        $skipped++;
        continue;
    }

    $currentUserId = (int)Session::getLoginUserID();
    // Fastlane items may be assigned by anyone; normal items need a full updater
    // or the target sprint's Scrum Master.
    $isFastlane = (int)($row['is_fastlane'] ?? 0) === 1;
    $canAssign = $hasFullUpdate
        || $isFastlane
        || GlpiPlugin\Sprint\Config::isCurrentUserScrumMaster($sprintId)
        || GlpiPlugin\Sprint\SprintMember::isScrumMaster($sprintId, $currentUserId);
    if (!$canAssign) {
        $skipped++;
        continue;
    }

    $one = new GlpiPlugin\Sprint\SprintItem();
    if (!$one->getFromDB($id)) {
        $skipped++;
        continue;
    }
    if ($one->update([
        'id'                       => $id,
        'plugin_sprint_sprints_id' => $sprintId,
        'proposed_sprints_id'      => 0,
    ])) {
        GlpiPlugin\Sprint\SprintItem::purgeBacklogCoupling(
            (string)($one->fields['itemtype'] ?? ''),
            (int)($one->fields['items_id'] ?? 0),
            $id
        );
        $assigned++;
    } else {
        $skipped++;
    }
}

$messages = [];
if ($assigned > 0) {
    $messages[] = sprintf(_n('%d item assigned', '%d items assigned', $assigned, 'sprint'), $assigned);
}
if ($skipped > 0) {
    $messages[] = sprintf(__('%d skipped (not the Scrum Master of the target sprint)', 'sprint'), $skipped);
}
if (empty($messages)) {
    $messages[] = __('No items were ready to assign.', 'sprint');
}

echo json_encode([
    'success'  => true,
    'assigned' => $assigned,
    'skipped'  => $skipped,
    'message'  => implode(' · ', $messages),
]);
