<?php

/**
 * SprintMeeting form page
 */

include(dirname(__DIR__, 3) . '/inc/includes.php');

Session::checkRight('plugin_sprint_sprint', READ);

$meeting = new GlpiPlugin\Sprint\SprintMeeting();

if (isset($_POST['add'])) {
    $meeting->check(-1, CREATE, $_POST);
    $meeting->add($_POST);
    Html::back();

} elseif (isset($_POST['update'])) {
    $meeting->check($_POST['id'], UPDATE);
    $meeting->update($_POST);
    Html::redirect(GlpiPlugin\Sprint\SprintMeeting::getFormURLWithID($_POST['id']));

} elseif (isset($_POST['purge'])) {
    $meeting->check($_POST['id'], PURGE);
    $meeting->delete($_POST, 1);
    Html::back();

} else {
    Html::header(
        GlpiPlugin\Sprint\SprintMeeting::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'helpdesk',
        'GlpiPlugin\Sprint\Sprint'
    );

    $ID = $_GET['id'] ?? 0;
    $meeting->display(['id' => $ID]);

    Html::footer();
}
