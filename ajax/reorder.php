<?php

/**
 * Persists drag order of backlog items: writes an increasing sort_order to each
 * id (backlog rows only). Requires update right (full or own-items).
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$hasFullUpdate = Session::haveRight('plugin_sprint_item', UPDATE);
$hasOwnOnly    = !$hasFullUpdate
    && Session::haveRight('plugin_sprint_item', GlpiPlugin\Sprint\Profile::RIGHT_OWN_ITEMS);
if (!$hasFullUpdate && !$hasOwnOnly) {
    echo json_encode(['success' => false]);
    return;
}

$decoded = json_decode((string)($_POST['order'] ?? '[]'), true);
$ids     = is_array($decoded) ? array_values(array_filter(array_map('intval', $decoded))) : [];
if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No order received']);
    return;
}

$pos     = 10;
$updated = 0;
foreach ($ids as $id) {
    $item = new GlpiPlugin\Sprint\SprintItem();
    if (!$item->getFromDB($id)) {
        continue;
    }
    // Only reorder true backlog rows.
    if ((int)($item->fields['plugin_sprint_sprints_id'] ?? 0) !== 0) {
        continue;
    }
    // Own-items right only covers rows the user owns.
    if ($hasOwnOnly && (int)$item->fields['users_id'] !== (int)Session::getLoginUserID()) {
        continue;
    }
    if ($item->update(['id' => $id, 'sort_order' => $pos])) {
        $updated++;
    }
    $pos += 10;
}

echo json_encode(['success' => true, 'updated' => $updated]);
