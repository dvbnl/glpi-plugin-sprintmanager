<?php

/**
 * SprintChange link form
 */

include(dirname(__DIR__, 3) . '/inc/includes.php');

Session::checkRight('plugin_sprint_sprint', READ);

$link = new GlpiPlugin\Sprint\SprintChange();

if (isset($_POST['add'])) {
    $link->check(-1, CREATE, $_POST);
    $link->add($_POST);
    Html::back();

} elseif (isset($_POST['purge'])) {
    $link->check($_POST['id'], PURGE);
    $link->delete($_POST, 1);
    Html::back();
}
