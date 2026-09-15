<?php

/**
 * AJAX handler for the quick-edit modal's inline dependency manager:
 *   - action=list   : return all dependencies (open + resolved) for an item
 *   - action=update : change a dependency's capacity %
 *   - action=remove : delete a dependency (and auto-unblock the parent)
 *
 * Listing is read-only; mutations require CSRF + full UPDATE or own-items+ownership.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkRight('plugin_sprint_item', READ);

$action = (string)($_REQUEST['action'] ?? 'list');
$itemId = (int)($_REQUEST['plugin_sprint_sprintitems_id'] ?? 0);

$fail = ['success' => false, 'message' => 'Request failed'];

$item = new GlpiPlugin\Sprint\SprintItem();
if ($itemId <= 0 || !$item->getFromDB($itemId)) {
    echo json_encode($fail);
    return;
}

$hasFullUpdate = Session::haveRight('plugin_sprint_item', UPDATE);
$hasOwnOnly = !$hasFullUpdate
    && Session::haveRight('plugin_sprint_item', GlpiPlugin\Sprint\Profile::RIGHT_OWN_ITEMS);
$isOwner = (int)$item->fields['users_id'] === (int)Session::getLoginUserID();

if (!$hasFullUpdate && !($hasOwnOnly && $isOwner)) {
    echo json_encode($fail);
    return;
}

if ($action === 'list') {
    $rel  = new GlpiPlugin\Sprint\SprintItemDependency();
    $deps = [];
    foreach ($rel->find(['plugin_sprint_sprintitems_id' => $itemId]) as $r) {
        $uid    = (int)$r['users_id'];
        $deps[] = [
            'id'          => (int)$r['id'],
            'users_id'    => $uid,
            'name'        => $uid > 0 ? GlpiPlugin\Sprint\SprintCache::userName($uid) : '',
            'capacity'    => GlpiPlugin\Sprint\SprintMember::formatCapacity($r['capacity']),
            'credits'     => GlpiPlugin\Sprint\SprintCustomer::formatCredits($r['credits'] ?? 0),
            'product_id'  => (int)($r['plugin_sprint_sprintcreditproducts_id'] ?? 0),
            'product'     => (int)($r['plugin_sprint_sprintcreditproducts_id'] ?? 0) > 0
                ? GlpiPlugin\Sprint\SprintCreditProduct::getFullNameFor((int)$r['plugin_sprint_sprintcreditproducts_id'])
                : '',
            'is_resolved' => (int)$r['is_resolved'],
        ];
    }
    echo json_encode(['success' => true, 'deps' => $deps]);
    return;
}

// Mutations require CSRF.
Session::checkCSRF($_POST);

$depId = (int)($_POST['id'] ?? 0);
$rel   = new GlpiPlugin\Sprint\SprintItemDependency();
if (
    $depId <= 0
    || !$rel->getFromDB($depId)
    || (int)($rel->fields['plugin_sprint_sprintitems_id'] ?? 0) !== $itemId
) {
    echo json_encode($fail);
    return;
}

if ($action === 'update') {
    // Capacity and/or credits; whichever the modal sends.
    $update = ['id' => $depId];
    if (array_key_exists('capacity', $_POST)) {
        $capacity = GlpiPlugin\Sprint\SprintMember::normalizeCapacity($_POST['capacity'] ?? 0);
        if ($capacity <= 0) {
            echo json_encode(['success' => false, 'message' => __('Capacity must be greater than 0', 'sprint')]);
            return;
        }
        $update['capacity'] = $capacity;
    } else {
        $capacity = (float)($rel->fields['capacity'] ?? 0);
    }
    if (array_key_exists('credits', $_POST)) {
        $update['credits'] = $_POST['credits'];
    }
    if (array_key_exists('plugin_sprint_sprintcreditproducts_id', $_POST)) {
        $update['plugin_sprint_sprintcreditproducts_id'] = (int)$_POST['plugin_sprint_sprintcreditproducts_id'];
    }

    $rel->update($update);
    $rel->getFromDB($depId);

    // Surface any capacity warning queued by the model.
    $warnings = [];
    if (isset($_SESSION['MESSAGE_AFTER_REDIRECT']) && is_array($_SESSION['MESSAGE_AFTER_REDIRECT'])) {
        foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] as $msgs) {
            if (is_array($msgs)) {
                foreach ($msgs as $m) {
                    $warnings[] = strip_tags((string)$m);
                }
            }
        }
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    }

    $depCredits = (float)($rel->fields['credits'] ?? 0);
    echo json_encode([
        'success' => true,
        'message' => sprintf(__('Dependency updated to %s%%', 'sprint'), GlpiPlugin\Sprint\SprintMember::formatCapacity($capacity))
            . ($depCredits > 0 ? ' · ' . sprintf(__('%s credits', 'sprint'), GlpiPlugin\Sprint\SprintCustomer::formatCredits($depCredits)) : ''),
        'credits' => GlpiPlugin\Sprint\SprintCustomer::formatCredits($depCredits),
        'warning' => $warnings ? implode("\n", $warnings) : '',
    ]);
    return;
}

if ($action === 'remove') {
    $rel->delete(['id' => $depId], 1);
    GlpiPlugin\Sprint\SprintItemDependency::maybeUnblockParent($itemId);
    echo json_encode([
        'success' => true,
        'message' => __('Dependency removed', 'sprint'),
    ]);
    return;
}

echo json_encode($fail);
