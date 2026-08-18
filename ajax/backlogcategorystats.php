<?php

/**
 * Backlog prognosis matrix fragment (categories × upcoming sprints).
 * Entity restriction is applied inside the fragment's sprint queries.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkRight('plugin_sprint_item', READ);

echo json_encode([
    'success' => true,
    'html'    => GlpiPlugin\Sprint\Backlog::renderCategoryMatrixFragment(
        max(1, min(26, (int)($_GET['horizon'] ?? 4))),
        (string)($_GET['mode'] ?? 'planned') === 'actual'
    ),
]);
