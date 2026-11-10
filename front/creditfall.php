<?php

/**
 * Creditfall page: the credits a sprint held at its kick-off against what it
 * delivered, and which items, customers and people the difference sits with.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

if (!GlpiPlugin\Sprint\SprintCustomer::canViewCredits()) {
    Html::displayRightError();
}

Html::header(
    GlpiPlugin\Sprint\SprintCreditFall::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    'GlpiPlugin\Sprint\Sprint',
    'credits'
);

GlpiPlugin\Sprint\SprintOverview::navStart('creditfall');
GlpiPlugin\Sprint\SprintCreditFall::show();
GlpiPlugin\Sprint\SprintOverview::navEnd();

Html::footer();
