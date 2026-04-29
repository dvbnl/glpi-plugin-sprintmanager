<?php

/**
 * AJAX handler to get sprint template data for pre-filling sprint form
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_sprint', READ);

$response = ['success' => false];

if (!isset($_POST['id']) || (int)$_POST['id'] <= 0) {
    echo json_encode($response);
    return;
}

$template = new GlpiPlugin\Sprint\SprintTemplate();
if (!$template->getFromDB((int)$_POST['id'])
    || !Session::haveAccessToEntity(
        $template->fields['entities_id'] ?? 0,
        (bool)($template->fields['is_recursive'] ?? false)
    )) {
    echo json_encode($response);
    return;
}

echo json_encode([
    'success' => true,
    'data'    => $template->getTemplateData(),
]);
