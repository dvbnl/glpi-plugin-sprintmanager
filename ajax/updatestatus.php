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
if (!$item->getFromDB((int)$_POST['id']) || !$item->hasEntityAccess()) {
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
$newStatus = (string)$_POST['status'];

// DoD gate for Review/Done: first attempt returns needs_dod (board shows the
// dialog), the retry carries done[] which is stored. The sprint's Scrum
// Master gets an extra "overrule" button in that dialog: a dod_override=1
// retry stores whatever was ticked and moves the item anyway (the transition
// policy below grants the same exemption, so the two stay in step).
$dod = GlpiPlugin\Sprint\Config::getDefinitionDone();
$doneChecks = null;
$isScrumMaster = GlpiPlugin\Sprint\SprintItem::currentUserIsScrumMasterOf(
    (int)($item->fields['plugin_sprint_sprints_id'] ?? 0)
);
$dodOverride = $isScrumMaster && (int)($_POST['dod_override'] ?? 0) === 1;
if ($dod && in_array($newStatus, [
    GlpiPlugin\Sprint\SprintItem::STATUS_REVIEW,
    GlpiPlugin\Sprint\SprintItem::STATUS_DONE,
], true)) {
    $current = GlpiPlugin\Sprint\SprintAgility::checklist((string)($item->fields['done_checks'] ?? ''));
    if (isset($_POST['done']) || $dodOverride) {
        $doneChecks = array_values(array_intersect($dod, (array)($_POST['done'] ?? [])));
        $item->fields['done_checks'] = json_encode($doneChecks);
    }
    if (!$dodOverride && array_diff($dod, $doneChecks ?? $current)) {
        echo json_encode([
            'success'      => false,
            'needs_dod'    => true,
            'dod'          => array_values($dod),
            'checked'      => array_values(array_intersect($dod, $doneChecks ?? $current)),
            'can_override' => $isScrumMaster,
        ]);
        return;
    }
}

$policy = GlpiPlugin\Sprint\SprintAgility::validateTransition($item, $newStatus, $dodOverride);
if (!$policy['ok']) {
    echo json_encode(['success' => false, 'message' => $policy['message']]);
    return;
}
$overrideNotice = !empty($policy['overridden']) ? (string)$policy['message'] : '';
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

$update = [
    'id'     => (int)$_POST['id'],
    'status' => $_POST['status'],
];
if ($doneChecks !== null) {
    $update['done_checks'] = json_encode($doneChecks);
}
$result = $item->update($update);


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
        ? ($overrideNotice !== '' ? $overrideNotice : 'Status updated')
        : ($messages ? implode("\n", $messages) : 'Update failed'),
    'dod_overridden'         => $result && $overrideNotice !== '',
    'linked_open_badge_html' => $badgeHtml,
]);
