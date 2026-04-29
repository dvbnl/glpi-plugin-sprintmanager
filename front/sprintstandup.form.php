<?php

/**
 * SprintStandup form page
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('plugin_sprint_item', READ);

$standup = new GlpiPlugin\Sprint\SprintStandup();

if (isset($_POST['add'])) {
    $standup->check(-1, CREATE, $_POST);
    $standup->add($_POST);
    Html::back();

} elseif (isset($_POST['update'])) {
    $standup->check($_POST['id'], UPDATE);
    $standup->update($_POST);
    Html::back();

} elseif (isset($_POST['purge'])) {
    $standup->check($_POST['id'], PURGE);
    $standup->delete($_POST, 1);
    Html::back();

} else {
    Html::header(
        GlpiPlugin\Sprint\SprintStandup::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'helpdesk',
        'GlpiPlugin\Sprint\Sprint'
    );

    $ID = $_GET['id'] ?? 0;
    $standup->display(['id' => $ID]);

    Html::footer();
}
