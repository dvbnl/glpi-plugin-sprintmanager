<?php

/**
 * SprintManager landing page: cross-sprint statistics and the sprint list.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

use GlpiPlugin\Sprint\SprintOverview;

Session::checkRight('plugin_sprint_sprint', READ);

// A search submit carries no section; keep those requests on the list.
$searchKeys = ['criteria', 'metacriteria', 'reset', 'start', 'sort', 'order', 'is_deleted', 'savedsearches_id'];
$isSearch   = (bool)array_intersect($searchKeys, array_keys($_GET + $_POST));

$section = (string)($_GET['section'] ?? ($isSearch ? SprintOverview::SECTION_LIST : SprintOverview::SECTION_OVERVIEW));
$period  = (string)($_GET['period'] ?? SprintOverview::PERIOD_YEAR);

Html::header(
    GlpiPlugin\Sprint\Sprint::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    'GlpiPlugin\Sprint\Sprint',
    $section === SprintOverview::SECTION_LIST ? 'sprint' : 'overview'
);

SprintOverview::show($section, $period);

Html::footer();
