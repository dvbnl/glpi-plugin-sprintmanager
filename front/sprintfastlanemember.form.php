<?php

/**
 * SprintFastlaneMember form handler (add / update / purge).
 * CSRF is checked by CommonDBTM inside add/update/delete.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('plugin_sprint_item', READ);

$rel = new GlpiPlugin\Sprint\SprintFastlaneMember();

if (isset($_POST['add'])) {
    $rel->check(-1, CREATE, $_POST);
    $rel->add($_POST);
    Html::back();

} elseif (isset($_POST['update'])) {
    $rel->check($_POST['id'], UPDATE);
    $rel->update($_POST);
    Html::back();

} elseif (isset($_POST['purge'])) {
    $rel->check($_POST['id'], PURGE);
    $rel->delete($_POST, 1);
    Html::back();
}

Html::back();
