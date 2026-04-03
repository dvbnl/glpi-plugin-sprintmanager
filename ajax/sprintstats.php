<?php

/**
 * AJAX handler for sprint dashboard statistics
 * GLPI 11 compatible: no exit()
 */

include('../../../inc/includes.php');

header('Content-Type: application/json');

Session::checkRight('plugin_sprint_sprint', READ);

$response = ['success' => false, 'message' => 'Request failed'];

if (!isset($_GET['sprint_id'])) {
    echo json_encode($response);
    return;
}

$sprint = new GlpiPlugin\Sprint\Sprint();
if (!$sprint->getFromDB((int)$_GET['sprint_id'])
    || !Session::haveAccessToEntity($sprint->fields['entities_id'] ?? 0)) {
    $response['message'] = 'Access denied';
    echo json_encode($response);
    return;
}

$stats = $sprint->getSprintStats();

echo json_encode([
    'success' => true,
    'data'    => $stats,
]);
