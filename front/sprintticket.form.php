<?php

/**
 * SprintTicket link form
 */

include('../../../inc/includes.php');

Session::checkRight('plugin_sprint_sprint', READ);

$link = new GlpiPlugin\Sprint\SprintTicket();

if (isset($_POST['add'])) {
    $link->check(-1, CREATE, $_POST);
    $link->add($_POST);
    Html::back();

} elseif (isset($_POST['purge'])) {
    $link->check($_POST['id'], PURGE);
    $link->delete($_POST, 1);
    Html::back();
}
