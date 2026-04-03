<?php

/**
 * SprintTemplateMeeting form (add/remove)
 */

include('../../../inc/includes.php');

Session::checkRight('plugin_sprint_sprint', READ);

$item = new GlpiPlugin\Sprint\SprintTemplateMeeting();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $_POST['_no_message_link'] = true;
    $item->add($_POST);
    Html::back();

} elseif (isset($_POST['purge'])) {
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST, 1);
    Html::back();
}
