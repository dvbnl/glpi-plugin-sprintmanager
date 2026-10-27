<?php

/**
 * Sprint Backlog page
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('plugin_sprint_item', READ);

Html::header(
    GlpiPlugin\Sprint\Backlog::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    'GlpiPlugin\Sprint\Sprint',
    'backlog'
);

// Labels of the in-page dialogs (capacity review, reason, credits).
GlpiPlugin\Sprint\SprintI18n::render();
GlpiPlugin\Sprint\SprintOverview::navStart('backlog');
GlpiPlugin\Sprint\Backlog::showBacklog();
GlpiPlugin\Sprint\SprintOverview::navEnd();

Html::footer();
