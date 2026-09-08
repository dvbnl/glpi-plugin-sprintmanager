<?php

/**
 * Credits page: customer balances, what the sprints claim of them and the
 * forecast on the remaining credits.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

if (!GlpiPlugin\Sprint\SprintCustomer::canViewCredits()) {
    Html::displayRightError();
}

Html::header(
    GlpiPlugin\Sprint\SprintCredits::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    'GlpiPlugin\Sprint\Sprint',
    'credits'
);

GlpiPlugin\Sprint\SprintOverview::navStart('credits');
GlpiPlugin\Sprint\SprintCredits::show();
GlpiPlugin\Sprint\SprintOverview::navEnd();

Html::footer();
