<?php

/**
 * Standalone page for the SprintManager plugin settings.
 *
 * Exposed via Sprint::getMenuContent() so the setting is reachable from the
 * plugin menu in addition to the Setup > General tab registration.
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
