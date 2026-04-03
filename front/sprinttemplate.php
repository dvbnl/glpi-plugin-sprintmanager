<?php

/**
 * Sprint Template list / search page
 */

include('../../../inc/includes.php');

Session::checkRight('plugin_sprint_sprint', READ);

Html::header(
    GlpiPlugin\Sprint\SprintTemplate::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    'GlpiPlugin\Sprint\Sprint'
);

Search::show('GlpiPlugin\Sprint\SprintTemplate');

Html::footer();
