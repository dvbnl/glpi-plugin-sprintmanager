<?php

/**
 * Backlog inflow/outflow chart fragment, optionally filtered on one category.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkRight('plugin_sprint_item', READ);

echo json_encode([
    'success' => true,
    'html'    => GlpiPlugin\Sprint\Backlog::renderFlowChartFragment((int)($_GET['category_id'] ?? 0)),
]);
