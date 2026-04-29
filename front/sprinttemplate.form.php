<?php

/**
 * Sprint Template form page (create / edit)
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('plugin_sprint_sprint', READ);

$template = new GlpiPlugin\Sprint\SprintTemplate();

if (isset($_POST['add'])) {
    $template->check(-1, CREATE, $_POST);
    $newID = $template->add($_POST);
    Html::back();

} elseif (isset($_POST['update'])) {
    $template->check($_POST['id'], UPDATE);
    $template->update($_POST);
    Html::back();

} elseif (isset($_POST['delete'])) {
    $template->check($_POST['id'], DELETE);
    $template->delete($_POST);
    $template->redirectToList();

} elseif (isset($_POST['purge'])) {
    $template->check($_POST['id'], PURGE);
    $template->delete($_POST, 1);
    $template->redirectToList();

} else {
    Html::header(
        GlpiPlugin\Sprint\SprintTemplate::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'helpdesk',
        'GlpiPlugin\Sprint\Sprint'
    );

    $ID = $_GET['id'] ?? 0;
    $template->display(['id' => $ID]);

    Html::footer();
}
