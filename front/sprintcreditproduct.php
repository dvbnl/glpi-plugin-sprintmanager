<?php

/**
 * Credit catalogue: the products and tasks with their default credits.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

if (!GlpiPlugin\Sprint\SprintCreditProduct::canView()) {
    Html::displayRightError();
}

Html::header(
    GlpiPlugin\Sprint\SprintCreditProduct::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    'GlpiPlugin\Sprint\Sprint',
    'credits'
);

GlpiPlugin\Sprint\SprintOverview::navStart('catalogue');
GlpiPlugin\Sprint\SprintCreditProduct::showCatalogue();
GlpiPlugin\Sprint\SprintOverview::navEnd();

Html::footer();
