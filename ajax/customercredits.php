<?php

/**
 * Save the per-sprint credit agreement per customer, all in one POST. Mirrors
 * ajax/categorycap.php: sprint_id + limits = JSON
 * {"<customerId>": {"credits": x|null, "max": y|null}}. Two nulls clear the
 * override, so the standing retainer applies again.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$sprintId = (int)($_POST['sprint_id'] ?? 0);
$limits   = json_decode((string)($_POST['limits'] ?? ''), true);

$sprint = new GlpiPlugin\Sprint\Sprint();
if (
    $sprintId <= 0 || !is_array($limits) || !$sprint->getFromDB($sprintId)
    || !Session::haveAccessToEntity($sprint->fields['entities_id'] ?? 0)
) {
    echo json_encode(['success' => false, 'message' => 'Invalid sprint or limits']);
    return;
}

if (!GlpiPlugin\Sprint\SprintCustomer::canEditCredits()) {
    echo json_encode([
        'success' => false,
        'message' => __('You are not allowed to set credit agreements', 'sprint'),
    ]);
    return;
}

$ok = true;
foreach ($limits as $customerId => $limit) {
    $customerId = (int)$customerId;
    // The customer must be reachable and valid for this sprint's entity.
    if (
        $customerId <= 0 || !is_array($limit)
        || !GlpiPlugin\Sprint\SprintCustomer::isVisible($customerId)
        || !GlpiPlugin\Sprint\SprintCustomer::isAvailableInEntity(
            $customerId,
            (int)($sprint->fields['entities_id'] ?? 0)
        )
    ) {
        continue;
    }
    $credits = ($limit['credits'] ?? '') === '' ? null : (float)$limit['credits'];
    $max     = ($limit['max'] ?? '') === '' ? null : (float)$limit['max'];
    $ok = GlpiPlugin\Sprint\SprintCustomer::setSprintCredits($sprintId, $customerId, $credits, $max) && $ok;
}
echo json_encode(['success' => $ok]);
