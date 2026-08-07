<?php

/**
 * AJAX handler returning one freshly rendered backlog <tr> fragment, so the
 * backlog edit modal can update the row in place instead of reloading the
 * whole page.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkRight('plugin_sprint_item', READ);

$id = (int)($_GET['id'] ?? 0);

$fragment = GlpiPlugin\Sprint\Backlog::renderRowFragment($id);
if ($fragment === null) {
    echo json_encode(['success' => false]);
    return;
}

echo json_encode([
    'success'     => true,
    'html'        => $fragment['html'],
    'is_blocked'  => $fragment['is_blocked'],
    'is_parked'   => $fragment['is_parked'],
    'category_id' => $fragment['category_id'],
]);
