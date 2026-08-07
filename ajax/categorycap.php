<?php

/**
 * Save the per-category capacity limits (min/max %) of a sprint, all
 * categories in one POST. Requires the sprint update right.
 * Expects: sprint_id + limits = JSON {"<categoryId>": {"min": x, "max": y}, ...}
 * A category with both values 0 has its limits cleared.
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
if ($sprintId <= 0 || !is_array($limits) || !$sprint->getFromDB($sprintId)
    || !Session::haveAccessToEntity($sprint->fields['entities_id'] ?? 0)) {
    echo json_encode(['success' => false, 'message' => 'Invalid sprint or limits']);
    return;
}

if (!GlpiPlugin\Sprint\Sprint::canUpdate()) {
    echo json_encode(['success' => false, 'message' => __('You are not allowed to set category limits', 'sprint')]);
    return;
}

$ok = true;
foreach ($limits as $categoryId => $limit) {
    $categoryId = (int)$categoryId;
    if ($categoryId <= 0 || !is_array($limit)) {
        continue;
    }
    $ok = GlpiPlugin\Sprint\SprintCategory::setLimits(
        $sprintId,
        $categoryId,
        (float)($limit['min'] ?? 0),
        (float)($limit['max'] ?? 0)
    ) && $ok;
}
echo json_encode(['success' => $ok]);
