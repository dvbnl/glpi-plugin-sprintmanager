<?php

/**
 * AJAX handler for quick edits of a SprintItem's *linked* source item
 * (Ticket / Change / ProjectTask) from the sprint views.
 *
 * Accepted fields per itemtype:
 *   - Ticket       : status (int)
 *   - Change       : status (int)
 *   - ProjectTask  : projectstates_id (int) + percent_done (0-100)
 *
 * Rights are delegated to GLPI's own item rights via $item->canUpdate()
 * so entity / technician / assigned-only restrictions are honored.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);

$fail = function (string $msg = 'Request failed'): void {
    echo json_encode(['success' => false, 'message' => $msg]);
};

$itemtype = (string)($_POST['itemtype'] ?? '');
$id       = (int)($_POST['id'] ?? 0);

$allowed = ['Ticket', 'Change', 'ProjectTask'];
if (!in_array($itemtype, $allowed, true) || $id <= 0 || !class_exists($itemtype)) {
    $fail('Invalid itemtype');
    return;
}

/** @var CommonDBTM $item */
$item = new $itemtype();
if (!$item->getFromDB($id)) {
    $fail('Item not found');
    return;
}

if (!$item->canUpdateItem()) {
    $fail('Not allowed');
    return;
}

$update = ['id' => $id];

if ($itemtype === 'Ticket' || $itemtype === 'Change') {
    if (!isset($_POST['status'])) {
        $fail('Missing status');
        return;
    }
    $update['status'] = (int)$_POST['status'];
} else { // ProjectTask
    if (isset($_POST['projectstates_id'])) {
        $update['projectstates_id'] = (int)$_POST['projectstates_id'];
    }
    if (isset($_POST['percent_done'])) {
        $update['percent_done'] = max(0, min(100, (int)$_POST['percent_done']));
    }
    if (count($update) === 1) {
        $fail('Nothing to update');
        return;
    }
}

$ok = $item->update($update);

// Drain queued session messages so we can relay them to the client.
$messages = [];
if (isset($_SESSION['MESSAGE_AFTER_REDIRECT']) && is_array($_SESSION['MESSAGE_AFTER_REDIRECT'])) {
    foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] as $msgs) {
        if (is_array($msgs)) {
            foreach ($msgs as $m) {
                $messages[] = strip_tags((string)$m);
            }
        }
    }
    $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
}

if (!$ok) {
    echo json_encode([
        'success' => false,
        'message' => $messages ? implode("\n", $messages) : 'Update failed',
    ]);
    return;
}

$item->getFromDB($id);

$response = [
    'success'  => true,
    'message'  => $messages ? implode("\n", $messages) : 'Updated',
    'itemtype' => $itemtype,
    'id'       => $id,
];

if ($itemtype === 'Ticket' || $itemtype === 'Change') {
    $statuses = $itemtype::getAllStatusArray(true);
    $curStatus = (int)$item->fields['status'];
    $response['status']       = $curStatus;
    $response['status_label'] = (string)($statuses[$curStatus] ?? '');
} else {
    $response['projectstates_id'] = (int)($item->fields['projectstates_id'] ?? 0);
    $response['percent_done']     = (int)($item->fields['percent_done'] ?? 0);

    $stateLabel = '';
    $psId = (int)$item->fields['projectstates_id'];
    if ($psId > 0) {
        $ps = new \ProjectState();
        if ($ps->getFromDB($psId)) {
            $stateLabel = (string)($ps->fields['name'] ?? '');
        }
    }
    $response['status_label'] = $stateLabel;
}

echo json_encode($response);
