<?php

/**
 * Backlog prognosis matrix fragment: categories × upcoming sprints, the
 * members × sprints breakdown (view=members) or the reserved credits per
 * customer × sprint (view=credits).
 * Entity restriction is applied inside the fragment's sprint queries.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkRight('plugin_sprint_item', READ);

$horizon = max(1, min(26, (int)($_GET['horizon'] ?? 4)));
$view    = (string)($_GET['view'] ?? 'categories');

if ($view === 'credits') {
    // Credit figures are gated on the credits right, not on the item right.
    $html = GlpiPlugin\Sprint\SprintCustomer::canViewCredits()
        ? GlpiPlugin\Sprint\Backlog::renderCreditMatrixFragment($horizon)
        : GlpiPlugin\Sprint\Backlog::renderCategoryMatrixFragment($horizon);
} elseif ($view === 'members') {
    $html = GlpiPlugin\Sprint\Backlog::renderMemberMatrixFragment($horizon);
} else {
    $html = GlpiPlugin\Sprint\Backlog::renderCategoryMatrixFragment($horizon);
}

echo json_encode(['success' => true, 'html' => $html]);
