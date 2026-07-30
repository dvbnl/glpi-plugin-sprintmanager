<?php

/**
 * AJAX handler for quick sprint item status updates.
 * GLPI 11 compatible: no exit(), uses return flow.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$response = ['success' => false, 'message' => 'Request failed'];

if (!isset($_POST['id']) || !isset($_POST['status'])) {
    echo json_encode($response);
    return;
}

$item = new GlpiPlugin\Sprint\SprintItem();
if (!$item->getFromDB((int)$_POST['id'])) {
    echo json_encode($response);
    return;
}

// Allow full update right, or own-item right when the user owns the item.
$hasFullUpdate = Session::haveRight('plugin_sprint_item', UPDATE);
$hasOwnOnly = !$hasFullUpdate
    && Session::haveRight('plugin_sprint_item', GlpiPlugin\Sprint\Profile::RIGHT_OWN_ITEMS);
$isOwner = (int)$item->fields['users_id'] === (int)Session::getLoginUserID();

if (!$hasFullUpdate && !($hasOwnOnly && $isOwner)) {
    echo json_encode($response);
    return;
}

$validStatuses = array_keys(GlpiPlugin\Sprint\SprintItem::getAllStatuses());
if (!in_array($_POST['status'], $validStatuses)) {
    echo json_encode($response);
    return;
}

// Moving to Review/Done while the underlying GLPI item is still open: don't
// apply yet — ask the client to confirm ("continue anyway") or visit the
// linked item first. A confirm_linked_open=1 retry skips this gate.
$newStatus         = (string)$_POST['status'];
$confirmLinkedOpen = (int)($_POST['confirm_linked_open'] ?? 0) === 1;
if (
    !$confirmLinkedOpen
    && in_array($newStatus, [
        GlpiPlugin\Sprint\SprintItem::STATUS_REVIEW,
        GlpiPlugin\Sprint\SprintItem::STATUS_DONE,
    ], true)
) {
    $checkRow = array_merge($item->fields, ['status' => $newStatus]);
    if (GlpiPlugin\Sprint\SprintItem::isLinkedItemOpenForRow($checkRow)) {
        $itemtype   = (string)($item->fields['itemtype'] ?? '');
        $itemsId    = (int)($item->fields['items_id'] ?? 0);
        $linkedName = '';
        $linkedUrl  = '';
        if ($itemtype !== '' && $itemsId > 0 && class_exists($itemtype)) {
            $linked = new $itemtype();
            if ($linked->getFromDB($itemsId)) {
                $linkedName = (string)($linked->fields['name'] ?? '');
            }
            $linkedUrl = $itemtype::getFormURLWithID($itemsId);
        }
        echo json_encode([
            'success'       => false,
            'needs_confirm' => true,
            'linked_name'   => $linkedName,
            'linked_url'    => $linkedUrl,
            'message'       => __('The linked ticket/change is not closed/solved yet', 'sprint'),
        ]);
        return;
    }
}

$result = $item->update([
    'id'     => (int)$_POST['id'],
    'status' => $_POST['status'],
]);

// Relay queued messages (e.g. the pending-approval lock reason) to the client
// instead of stacking them up for the next page load.
$messages = [];
if (isset($_SESSION['MESSAGE_AFTER_REDIRECT']) && is_array($_SESSION['MESSAGE_AFTER_REDIRECT'])) {
    foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] as $level => $msgs) {
        if (is_array($msgs)) {
            foreach ($msgs as $m) {
                $messages[] = strip_tags((string)$m);
            }
        }
    }
    $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
}

// Fresh badge state so the board can show/hide "Linked item open" without a
// page reload.
$badgeHtml = '';
if ($result) {
    $item->getFromDB((int)$_POST['id']);
    $badgeHtml = GlpiPlugin\Sprint\SprintItem::renderLinkedItemOpenBadge($item->fields);
}

echo json_encode([
    'success'                => (bool)$result,
    'message'                => $result
        ? 'Status updated'
        : ($messages ? implode("\n", $messages) : 'Update failed'),
    'linked_open_badge_html' => $badgeHtml,
]);
