<?php

/**
 * Sprint export — printable end-of-sprint report.
 *
 * Renders a self-contained HTML report (summary stats, member workload,
 * team activity chart, item breakdown). The toolbar offers a "Print /
 * Save as PDF" button that delegates to the browser's print pipeline,
 * so no server-side PDF library is required.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('plugin_sprint_sprint', READ);

$sprintId = (int)($_GET['id'] ?? 0);
if ($sprintId <= 0) {
    Html::displayErrorAndDie(__('Sprint not found', 'sprint'));
}

$sprint = new GlpiPlugin\Sprint\Sprint();
if (!$sprint->getFromDB($sprintId)) {
    Html::displayErrorAndDie(__('Sprint not found', 'sprint'));
}

$title = sprintf(__('Sprint report — %s', 'sprint'), (string)$sprint->fields['name']);

Html::header(
    $title,
    $_SERVER['PHP_SELF'],
    'helpdesk',
    'GlpiPlugin\\Sprint\\Sprint'
);

GlpiPlugin\Sprint\SprintExport::render($sprint, true);

Html::footer();
