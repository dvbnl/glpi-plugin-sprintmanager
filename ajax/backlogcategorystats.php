<?php

/**
 * Backlog prognosis matrix fragment: categories × upcoming sprints, or the
 * members × sprints breakdown when view=members.
 * Entity restriction is applied inside the fragment's sprint queries.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkRight('plugin_sprint_item', READ);

$horizon = max(1, min(26, (int)($_GET['horizon'] ?? 4)));
$actual  = (string)($_GET['mode'] ?? 'planned') === 'actual';

echo json_encode([
    'success' => true,
    'html'    => (string)($_GET['view'] ?? 'categories') === 'members'
        ? GlpiPlugin\Sprint\Backlog::renderMemberMatrixFragment($horizon, $actual)
        : GlpiPlugin\Sprint\Backlog::renderCategoryMatrixFragment($horizon, $actual),
]);
