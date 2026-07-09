<?php

/**
 * Standalone SprintManager settings page, exposed via Sprint::getMenuContent()
 * so settings are reachable from the plugin menu, not just the Setup tab.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('config', READ);

Html::header(
    __('SprintManager settings', 'sprint'),
    $_SERVER['PHP_SELF'],
    'config'
);

GlpiPlugin\Sprint\Config::showConfigForm();

Html::footer();
