<?php

/**
 * SprintItem form page
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('plugin_sprint_item', READ);

$item = new GlpiPlugin\Sprint\SprintItem();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $item->add($_POST);
    Html::back();

} elseif (isset($_POST['update'])) {
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();

} elseif (isset($_POST['update_sprint_plan'])) {
    // Quick edit from the planning line on a linked Ticket/Change/Problem/
    // Project task: adhoc + tags (guards in prepareInputForUpdate() turn a
    // non-Scrum-Master adhoc change into a request), then an optional
    // carry-over into a new sprint.
    $item->check($_POST['id'], UPDATE);
    $update = ['id' => (int)$_POST['id']];
    if (array_key_exists('is_adhoc', $_POST)) {
        $update['is_adhoc'] = (int)$_POST['is_adhoc'] ? 1 : 0;
        $update['_adhoc_request_reason'] = trim((string)($_POST['carry_reason'] ?? ''));
    }
    if (array_key_exists('_tags', $_POST)) {
        $update['_tags'] = is_array($_POST['_tags']) ? $_POST['_tags'] : [];
    }
    if (count($update) > 1) {
        $item->update($update);
    }
    $targetId = (int)($_POST['carry_over_to_sprint_id'] ?? 0);
    if ($targetId > 0) {
        $result = GlpiPlugin\Sprint\SprintItem::carryOverOrRequest(
            (int)$_POST['id'],
            $targetId,
            null,
            null,
            trim((string)($_POST['carry_reason'] ?? ''))
        );
        Session::addMessageAfterRedirect($result['message'], false, $result['ok'] ? INFO : ERROR);
    }
    Html::back();

} elseif (isset($_POST['carry_over_item'])) {
    // Carry-over from the item itself (form card or the planning line on a
    // linked GLPI item): direct for the target sprint's Scrum Master, an
    // approval request for anyone else.
    $item->check($_POST['id'], UPDATE);
    $result = GlpiPlugin\Sprint\SprintItem::carryOverOrRequest(
        (int)$_POST['id'],
        (int)($_POST['carry_over_to_sprint_id'] ?? 0),
        null,
        null,
        trim((string)($_POST['carry_reason'] ?? ''))
    );
    Session::addMessageAfterRedirect($result['message'], false, $result['ok'] ? INFO : ERROR);
    if ($result['ok'] && !$result['requested'] && $result['new_id'] > 0 && empty($_POST['_redirect'])) {
        Html::redirect(GlpiPlugin\Sprint\SprintItem::getFormURLWithID($result['new_id']));
    }
    Html::back();

} elseif (isset($_POST['purge'])) {
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST, 1);
    Html::back();

} else {
    Html::header(
        GlpiPlugin\Sprint\SprintItem::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'helpdesk',
        'GlpiPlugin\Sprint\Sprint'
    );

    $ID = $_GET['id'] ?? 0;
    $item->display(['id' => $ID]);

    Html::footer();
}
