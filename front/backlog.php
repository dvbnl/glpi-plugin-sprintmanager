<?php

/**
 * Sprint Backlog page
 */

include(dirname(__DIR__, 3) . '/inc/includes.php');

Session::checkRight('plugin_sprint_item', READ);

Html::header(
    GlpiPlugin\Sprint\Backlog::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    'GlpiPlugin\Sprint\Sprint'
);

GlpiPlugin\Sprint\Backlog::showBacklog();

Html::footer();
