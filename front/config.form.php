<?php

/**
 * SprintManager settings form handler (Setup > General > SprintManager tab).
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('config', UPDATE);

if (isset($_POST['update_sprint_config'])) {
    // GLPI 11's kernel already validates (and spends) the CSRF token for legacy
    // front/ POSTs, so checkCSRF() here would fail (HTTP 403). GLPI 10 has no
    // such check for this non-CommonDBTM handler, so keep it there.
    if ((int) explode('.', GLPI_VERSION)[0] < 11) {
        Session::checkCSRF($_POST);
    }
    GlpiPlugin\Sprint\Config::saveConfig($_POST);
    Session::addMessageAfterRedirect(__('SprintManager settings saved', 'sprint'));
    Html::back();
}

Html::back();
