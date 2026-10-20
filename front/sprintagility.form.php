<?php

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('plugin_sprint_sprint', READ);
$input = $_POST;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::redirect(GlpiPlugin\Sprint\Sprint::getSearchURL());
    return;
}
$result = GlpiPlugin\Sprint\SprintAgility::handle($input);
$ok     = (bool)$result;

// AJAX submits get JSON back so the tab can patch in place.
if (!empty($input['_ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $ok,
        'id'      => is_int($result) ? $result : 0,
        'message' => $ok ? __('Saved', 'sprint') : __('Could not save agility settings', 'sprint'),
    ]);
    return;
}
Session::addMessageAfterRedirect(
    $ok ? __('Agility settings saved', 'sprint') : __('Could not save agility settings', 'sprint'),
    false,
    $ok ? INFO : ERROR
);
// Availability exceptions are edited from the Sprint Members tab.
$tab = (string)($input['_tab'] ?? '') === 'members' ? 'SprintMember' : 'SprintAgility';
Html::redirect(GlpiPlugin\Sprint\Sprint::getFormURLWithID((int)($input['sprint_id'] ?? 0))
    . '&forcetab=' . urlencode('GlpiPlugin\\Sprint\\' . $tab . '$1'));
