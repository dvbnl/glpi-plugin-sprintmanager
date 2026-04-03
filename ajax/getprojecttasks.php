<?php

/**
 * AJAX handler to get project tasks for a specific project
 * Returns HTML for a select dropdown
 */

include('../../../inc/includes.php');

header('Content-Type: application/json');

Session::checkCSRFToken();
Session::checkRight('plugin_sprint_sprint', READ);

$projectId = (int)($_POST['projects_id'] ?? 0);

if ($projectId <= 0) {
    echo json_encode(['success' => false, 'html' => '']);
    return;
}

$task  = new ProjectTask();
$tasks = $task->find(['projects_id' => $projectId], ['name ASC']);

$options = [];
foreach ($tasks as $row) {
    $options[] = [
        'id'   => $row['id'],
        'name' => $row['name'],
    ];
}

echo json_encode([
    'success' => true,
    'tasks'   => $options,
]);
