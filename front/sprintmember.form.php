<?php

/**
 * SprintMember form page
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('plugin_sprint_sprint', READ);

$member = new GlpiPlugin\Sprint\SprintMember();

if (isset($_POST['add'])) {
    $member->check(-1, CREATE, $_POST);
    $member->add($_POST);
    Html::back();

} elseif (isset($_POST['update'])) {
    $member->check($_POST['id'], UPDATE);
    $member->update($_POST);
    Html::back();

} elseif (isset($_POST['purge'])) {
    $member->check($_POST['id'], PURGE);
    $member->delete($_POST, 1);
    Html::back();

} else {
    Html::header(
        GlpiPlugin\Sprint\SprintMember::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'helpdesk',
        'GlpiPlugin\Sprint\Sprint'
    );

    $ID = $_GET['id'] ?? 0;
    $member->display(['id' => $ID]);

    Html::footer();
}
