<?php

/**
 * Returns the capacity situation of an owner in a specific sprint as JSON,
 * for the backlog edit modal: total capacity, already used inside the sprint,
 * and what other backlog items proposed for that sprint have pencilled in.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkRight('plugin_sprint_item', READ);

$sprintId = (int)($_GET['sprint_id'] ?? $_POST['sprint_id'] ?? 0);
$userId   = (int)($_GET['users_id'] ?? $_POST['users_id'] ?? 0);
$itemId   = (int)($_GET['item_id'] ?? $_POST['item_id'] ?? 0);

$preview = GlpiPlugin\Sprint\SprintMember::backlogCapacityPreview($sprintId, $userId, $itemId);

if ($preview === null) {
    echo json_encode(['success' => false]);
    return;
}

echo json_encode([
    'success'       => true,
    'is_member'     => $preview['is_member'],
    'total'         => GlpiPlugin\Sprint\SprintMember::formatCapacity($preview['total']),
    'used'          => GlpiPlugin\Sprint\SprintMember::formatCapacity($preview['used']),
    'pending_other' => GlpiPlugin\Sprint\SprintMember::formatCapacity($preview['pending_other']),
    'available'     => GlpiPlugin\Sprint\SprintMember::formatCapacity(
        $preview['total'] - $preview['used'] - $preview['pending_other']
    ),
]);
