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

// Optional category reassignments from dragging a row into another category
// section: {itemId: categoryId}. Only known categories (or 0 = none) pass.
$catDecoded = json_decode((string)($_POST['categories'] ?? '{}'), true);
$catMap     = [];
if (is_array($catDecoded)) {
    $known = GlpiPlugin\Sprint\SprintCategory::getAll(false);
    foreach ($catDecoded as $itemId => $catId) {
        $catId = (int)$catId;
        if ($catId === 0 || isset($known[$catId])) {
            $catMap[(int)$itemId] = $catId;
        }
    }
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
    $update = ['id' => $id, 'sort_order' => $pos];
    if (array_key_exists($id, $catMap)) {
        $update['plugin_sprint_sprintcategories_id'] = $catMap[$id];
    }
    if ($item->update($update)) {
        $updated++;
    }
    $pos += 10;
}

echo json_encode(['success' => true, 'updated' => $updated]);
