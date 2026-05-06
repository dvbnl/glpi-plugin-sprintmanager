<?php

/**
 * AJAX handler — returns the team-activity chart fragment for a given
 * sprint and date range. Used by the date-range picker above the chart
 * so the user can zoom/pan without a full page reload.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('plugin_sprint_sprint', READ);

$sprintId = (int)($_GET['sprint_id'] ?? 0);
$sprint   = new GlpiPlugin\Sprint\Sprint();
if ($sprintId <= 0
    || !$sprint->getFromDB($sprintId)
    || !Session::haveAccessToEntity($sprint->fields['entities_id'] ?? 0)) {
    http_response_code(403);
    return;
}

$parse = function ($v): ?\DateTimeImmutable {
    $v = trim((string)$v);
    if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return null;
    }
    try {
        return new \DateTimeImmutable($v);
    } catch (\Exception $e) {
        return null;
    }
};

$from = $parse($_GET['activity_from'] ?? '');
$to   = $parse($_GET['activity_to']   ?? '');
if ($from && $to && $from > $to) {
    [$from, $to] = [$to, $from];
}

GlpiPlugin\Sprint\SprintDashboard::renderActivityChartFragment($sprintId, $from, $to);
