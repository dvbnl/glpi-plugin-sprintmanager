<?php

/**
 * Sprint form page (create / edit)
 */

include('../../../inc/includes.php');

Session::checkRight('plugin_sprint_sprint', READ);

$sprint = new GlpiPlugin\Sprint\Sprint();

if (isset($_POST['add'])) {
    $sprint->check(-1, CREATE, $_POST);
    $newID = $sprint->add($_POST);
    if ($newID) {
        Html::redirect(GlpiPlugin\Sprint\Sprint::getFormURLWithID($newID));
    }
    Html::back();

} elseif (isset($_POST['update'])) {
    $sprint->check($_POST['id'], UPDATE);
    $sprint->update($_POST);
    Html::back();

} elseif (isset($_POST['delete'])) {
    $sprint->check($_POST['id'], DELETE);
    $sprint->delete($_POST);
    $sprint->redirectToList();

} elseif (isset($_POST['purge'])) {
    $sprint->check($_POST['id'], PURGE);
    $sprint->delete($_POST, 1);
    $sprint->redirectToList();

} else {
    Html::header(
        GlpiPlugin\Sprint\Sprint::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'helpdesk',
        'GlpiPlugin\Sprint\Sprint'
    );

    $ID = $_GET['id'] ?? 0;
    $sprint->display(['id' => $ID]);

    Html::footer();
}
