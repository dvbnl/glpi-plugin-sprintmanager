<?php

/**
 * Sprint export — printable end-of-sprint HTML report (printed/saved as PDF
 * via the browser, so no server-side PDF library is required).
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

// CSV download — must run before any HTML chrome is emitted.
if (($_GET['format'] ?? '') === 'csv') {
    GlpiPlugin\Sprint\SprintExport::streamCsv($sprint);
    exit;
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
