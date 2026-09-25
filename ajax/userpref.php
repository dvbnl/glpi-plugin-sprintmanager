<?php

/**
 * Saves a personal (per-user) plugin preference, e.g. the backlog view.
 * Only whitelisted names/values are accepted; a user can only write their own.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkLoginUser();

$allowed = [
    GlpiPlugin\Sprint\UserPref::BACKLOG_SORT => ['team', 'project'],
];

$name  = (string)($_POST['name'] ?? '');
$value = (string)($_POST['value'] ?? '');
if (!isset($allowed[$name]) || !in_array($value, $allowed[$name], true)) {
    echo json_encode(['success' => false, 'message' => 'Unknown preference']);
    return;
}

echo json_encode(['success' => GlpiPlugin\Sprint\UserPref::set($name, $value)]);
