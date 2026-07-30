<?php

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('plugin_sprint_sprint', READ);

Html::header(
    GlpiPlugin\Sprint\SprintTemplate::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    'GlpiPlugin\Sprint\Sprint',
    'sprinttemplate'
);

GlpiPlugin\Sprint\SprintOverview::navStart('sprinttemplate');
Search::show('GlpiPlugin\Sprint\SprintTemplate');
GlpiPlugin\Sprint\SprintOverview::navEnd();

Html::footer();
