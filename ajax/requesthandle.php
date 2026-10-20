<?php

/**
 * AJAX handler for accepting/rejecting/cancelling a SprintRequest. Only the
 * Scrum Master of the request's sprint may accept or reject it (accepting
 * performs the requested action); only the requester may cancel it.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$id     = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['request_action'] ?? '');

if ($id <= 0 || !in_array($action, ['accept', 'reject', 'cancel'], true)) {
    echo json_encode(['success' => false, 'message' => __('Request failed', 'sprint')]);
    return;
}

$result = match ($action) {
    'accept' => GlpiPlugin\Sprint\SprintRequest::accept($id),
    'reject' => GlpiPlugin\Sprint\SprintRequest::reject($id),
    'cancel' => GlpiPlugin\Sprint\SprintRequest::cancel($id),
};

// Relay queued GLPI messages (capacity warnings etc.) instead of stacking
// them for the next page load.
$extra = [];
if (isset($_SESSION['MESSAGE_AFTER_REDIRECT']) && is_array($_SESSION['MESSAGE_AFTER_REDIRECT'])) {
    foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] as $msgs) {
        if (is_array($msgs)) {
            foreach ($msgs as $m) {
                $extra[] = strip_tags((string)$m);
            }
        }
    }
    $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
}

$message = $result['message'];
if (!empty($extra)) {
    $message .= "\n" . implode("\n", $extra);
}

echo json_encode([
    'success' => $result['ok'],
    'message' => $message,
]);
