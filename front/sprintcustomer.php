<?php

/**
 * Customer list.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

if (!GlpiPlugin\Sprint\SprintCustomer::canViewCredits()) {
    Html::displayRightError();
}

Html::header(
    GlpiPlugin\Sprint\SprintCustomer::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    'GlpiPlugin\Sprint\Sprint',
    'credits'
);

GlpiPlugin\Sprint\SprintOverview::navStart('credits');
Search::show(GlpiPlugin\Sprint\SprintCustomer::class);
GlpiPlugin\Sprint\SprintOverview::navEnd();

Html::footer();
