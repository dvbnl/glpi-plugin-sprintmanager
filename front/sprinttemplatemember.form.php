<?php

/**
 * SprintTemplateMember form (add/remove)
 */

include(dirname(__DIR__, 3) . '/inc/includes.php');

Session::checkRight('plugin_sprint_sprint', READ);

$member = new GlpiPlugin\Sprint\SprintTemplateMember();

if (isset($_POST['add'])) {
    $member->check(-1, CREATE, $_POST);
    $member->add($_POST);
    Html::back();

} elseif (isset($_POST['purge'])) {
    $member->check($_POST['id'], PURGE);
    $member->delete($_POST, 1);
    Html::back();
}
