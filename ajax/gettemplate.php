<?php

/**
 * AJAX handler to get sprint template data for pre-filling sprint form
 */

include('../../../inc/includes.php');

header('Content-Type: application/json');

Session::checkCSRFToken();
Session::checkRight('plugin_sprint_sprint', READ);

$response = ['success' => false];

if (!isset($_POST['id']) || (int)$_POST['id'] <= 0) {
    echo json_encode($response);
    return;
}

$template = new GlpiPlugin\Sprint\SprintTemplate();
if (!$template->getFromDB((int)$_POST['id'])) {
    echo json_encode($response);
    return;
}

echo json_encode([
    'success' => true,
    'data'    => $template->getTemplateData(),
]);
