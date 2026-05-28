<?php

/**
 * Soft close model: 'resolve' flips is_resolved = 1 (capacity freed, row
 * kept for audit); 'reopen' flips it back; 'purge' hard-deletes.
 *
 * No explicit Session::checkCSRF() — CommonDBTM add/update/delete already
 * validates the same token, calling it here would consume the token and
 * cause the downstream check to fail with 403.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('plugin_sprint_item', READ);

$rel = new GlpiPlugin\Sprint\SprintItemDependency();

if (isset($_POST['add'])) {
    $rel->check(-1, CREATE, $_POST);
    $rel->add($_POST);
    Html::back();

} elseif (isset($_POST['update'])) {
    $rel->check($_POST['id'], UPDATE);
    $rel->update($_POST);
    Html::back();

} elseif (isset($_POST['resolve'])) {
    $rel->check($_POST['id'], UPDATE);
    $rel->update([
        'id'          => (int)$_POST['id'],
        'is_resolved' => 1,
    ]);
    GlpiPlugin\Sprint\SprintItemDependency::maybeUnblockParent(
        (int)($rel->fields['plugin_sprint_sprintitems_id'] ?? 0)
    );
    Session::addMessageAfterRedirect(__('Dependency resolved', 'sprint'));
    Html::back();

} elseif (isset($_POST['reopen'])) {
    $rel->check($_POST['id'], UPDATE);
    $rel->update([
        'id'          => (int)$_POST['id'],
        'is_resolved' => 0,
    ]);
    Session::addMessageAfterRedirect(__('Dependency reopened', 'sprint'));
    Html::back();

} elseif (isset($_POST['purge'])) {
    $rel->check($_POST['id'], PURGE);
    $parentId = (int)($rel->fields['plugin_sprint_sprintitems_id'] ?? 0);
    $rel->delete($_POST, 1);
    GlpiPlugin\Sprint\SprintItemDependency::maybeUnblockParent($parentId);
    Html::back();
}

Html::back();
