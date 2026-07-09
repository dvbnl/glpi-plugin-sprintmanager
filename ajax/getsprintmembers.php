<?php

/**
 * Returns a sprint's members as JSON for the backlog "add dependency" picker:
 * backlog dependencies may only target members of the selected sprint.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkRight('plugin_sprint_item', READ);

$sprintId = (int)($_GET['sprint_id'] ?? $_POST['sprint_id'] ?? 0);
$exclude  = (int)($_GET['exclude_user'] ?? $_POST['exclude_user'] ?? 0);

if ($sprintId <= 0) {
    echo json_encode(['success' => false, 'members' => []]);
    return;
}

$options = GlpiPlugin\Sprint\SprintMember::getSprintMemberOptions($sprintId);

$members = [];
foreach ($options as $uid => $label) {
    $uid = (int)$uid;
    if ($uid <= 0 || $uid === $exclude) {
        continue;
    }
    $members[] = ['id' => $uid, 'label' => (string)$label];
}

echo json_encode(['success' => true, 'members' => $members]);
