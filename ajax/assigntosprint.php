<?php

/**
 * AJAX handler for assigning a backlog SprintItem to a sprint.
 * Returns the sprint's name + URL so the UI can show a confirmation toast.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkCSRF($_POST);
Session::checkRight('plugin_sprint_item', READ);

$response = ['success' => false, 'message' => 'Request failed'];

$id       = (int)($_POST['id'] ?? 0);
$sprintId = (int)($_POST['plugin_sprint_sprints_id'] ?? 0);

if ($id <= 0 || $sprintId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => __('Please select a sprint', 'sprint'),
    ]);
    return;
}

$item = new GlpiPlugin\Sprint\SprintItem();
if (!$item->getFromDB($id)) {
    echo json_encode($response);
    return;
}

$sprint = new GlpiPlugin\Sprint\Sprint();
if (!$sprint->getFromDB($sprintId)) {
    echo json_encode([
        'success' => false,
        'message' => __('Please select a sprint', 'sprint'),
    ]);
    return;
}

// Normal items: only the target sprint's Scrum Master may assign (the general
// plugin UPDATE right is intentionally NOT enough). Fastlane items are
// interrupt work — anyone with backlog access may pull them into a sprint.
$currentUserId = (int)Session::getLoginUserID();
$isFastlane    = (int)($item->fields['is_fastlane'] ?? 0) === 1;
$canAssign = $isFastlane
    || GlpiPlugin\Sprint\Config::isCurrentUserScrumMaster($sprintId)
    || GlpiPlugin\Sprint\SprintMember::isScrumMaster($sprintId, $currentUserId);

if (!$canAssign) {
    echo json_encode([
        'success' => false,
        'message' => sprintf(
            __('Only the Scrum Master of %s can assign items to it.', 'sprint'),
            (string)$sprint->fields['name']
        ),
    ]);
    return;
}

// DoR is mandatory on assign; fastlane items are exempt.
$dor    = GlpiPlugin\Sprint\Config::getDefinitionReady();
$update = [
    'id'                       => $id,
    'plugin_sprint_sprints_id' => $sprintId,
    'proposed_sprints_id'      => 0,
];
if ($dor) {
    $confirmed = array_values(array_intersect($dor, (array)($_POST['ready'] ?? [])));
    if (!$isFastlane && count($confirmed) < count($dor)) {
        echo json_encode([
            'success' => false,
            'message' => __('Definition of Ready incomplete. Confirm every check to assign this item.', 'sprint'),
        ]);
        return;
    }
    $update['ready_checks'] = json_encode($confirmed);
}
$result = $item->update($update);

if ($result) {
    // Coupled items live in one place: drop leftover backlog rows for the coupling.
    GlpiPlugin\Sprint\SprintItem::purgeBacklogCoupling(
        (string)($item->fields['itemtype'] ?? ''),
        (int)($item->fields['items_id'] ?? 0),
        $id
    );
}

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

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => $messages ? implode("\n", $messages) : __('Could not assign item to sprint', 'sprint'),
    ]);
    return;
}

echo json_encode([
    'success'      => true,
    'message'      => sprintf(__('Assigned to %s', 'sprint'), (string)$sprint->fields['name']),
    'sprint_id'    => $sprintId,
    'sprint_name'  => (string)$sprint->fields['name'],
    'sprint_url'   => GlpiPlugin\Sprint\Sprint::getFormURLWithID($sprintId),
]);
