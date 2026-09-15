<?php

/**
 * Persists the drag order of the credit catalogue: an increasing sort_order
 * for the given sibling ids. Every id must share one parent — moving an
 * entry into another folder stays an explicit edit on its form.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);

use GlpiPlugin\Sprint\SprintCreditProduct;

if (!SprintCreditProduct::canUpdate()) {
    echo json_encode(['success' => false, 'message' => __("You don't have permission to perform this action.")]);
    return;
}

$decoded = json_decode((string)($_POST['order'] ?? '[]'), true);
$ids     = is_array($decoded) ? array_values(array_unique(array_filter(array_map('intval', $decoded)))) : [];
if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No order received']);
    return;
}

$all    = SprintCreditProduct::getAll(false);
$parent = null;
foreach ($ids as $id) {
    if (!isset($all[$id])) {
        echo json_encode(['success' => false, 'message' => 'Unknown catalogue entry']);
        return;
    }
    $pid = (int)($all[$id]['sprintcreditproducts_id'] ?? 0);
    if ($pid > 0 && !isset($all[$pid])) {
        $pid = 0;
    }
    if ($parent !== null && $pid !== $parent) {
        echo json_encode(['success' => false, 'message' => 'Entries must share one folder']);
        return;
    }
    $parent = $pid;
}

global $DB;
$pos = 10;
foreach ($ids as $id) {
    $DB->update(SprintCreditProduct::getTable(), ['sort_order' => $pos], ['id' => $id]);
    $pos += 10;
}
SprintCreditProduct::invalidate();

echo json_encode(['success' => true, 'updated' => count($ids)]);
