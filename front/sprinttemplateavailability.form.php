<?php

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('plugin_sprint_sprint', READ);

$availability = new GlpiPlugin\Sprint\SprintTemplateAvailability();

if (isset($_POST['add'])) {
    $availability->check(-1, CREATE, $_POST);
    $availability->add($_POST);
    Html::back();

} elseif (isset($_POST['purge'])) {
    $availability->check($_POST['id'], PURGE);
    $availability->delete($_POST, 1);
    Html::back();
}
