<?php

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('plugin_sprint_sprint', READ);

$meeting = new GlpiPlugin\Sprint\SprintMeeting();

if (isset($_POST['add'])) {
    $meeting->check(-1, CREATE, $_POST);
    $meeting->add($_POST);
    Html::back();

} elseif (isset($_POST['start_standup'])) {
    // One-click stand-up: open today's stand-up of the sprint, or create it
    // (today, 15 minutes, the current user facilitates) and open that.
    $sprintId = (int)($_POST['plugin_sprint_sprints_id'] ?? 0);
    $sprint   = new GlpiPlugin\Sprint\Sprint();
    if ($sprintId <= 0 || !$sprint->getFromDB($sprintId)) {
        Html::back();
    }
    $standupId = GlpiPlugin\Sprint\SprintMeeting::getTodayStandupId($sprintId);
    if ($standupId <= 0) {
        $input = GlpiPlugin\Sprint\SprintMeeting::getStandupInput($sprintId);
        $meeting->check(-1, CREATE, $input);
        $standupId = (int)$meeting->add($input);
    }
    if ($standupId > 0) {
        Html::redirect(GlpiPlugin\Sprint\SprintMeeting::getFormURLWithID($standupId));
    }
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
