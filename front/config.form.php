<?php

/**
 * Handler for the SprintManager plugin settings form, rendered on the
 * Setup > General > SprintManager tab.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('config', UPDATE);

if (isset($_POST['update_sprint_config'])) {
    GlpiPlugin\Sprint\Config::saveConfig($_POST);
    Session::addMessageAfterRedirect(__('SprintManager settings saved', 'sprint'));
    Html::back();
}

Html::back();
