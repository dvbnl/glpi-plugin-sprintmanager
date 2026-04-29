<?php

/**
 * Backlog form handler
 *
 * Handles three actions:
 *   - add_to_backlog   : create a SprintItem (sprint_id = 0) from a Ticket / Change / ProjectTask
 *   - assign_to_sprint : move a backlog SprintItem into a real sprint (it then leaves the backlog)
 *   - purge            : delete a backlog SprintItem
 *
 * NOTE: We deliberately do not call Session::checkCSRF() here. CommonDBTM
 * add/update/delete already trigger GLPI's CSRF validation on the same
 * token, and calling checkCSRF() first consumes the token, causing the
 * later validation to fail with HTTP 403. The other form handlers in this
 * plugin (sprintitem.form.php, sprintticket.form.php, ...) follow the same
 * pattern.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('plugin_sprint_item', READ);

if (isset($_POST['add_to_backlog'])) {
    $itemtype = (string)($_POST['itemtype'] ?? '');
    $itemId   = (int)($_POST['items_id'] ?? 0);

    if (GlpiPlugin\Sprint\Backlog::isLinkedItemInAnySprint($itemtype, $itemId)) {
        Session::addMessageAfterRedirect(
            __('This item is already linked to a sprint — use "Carry over to sprint" to move it between sprints.', 'sprint'),
            false,
            ERROR
        );
    } else {
        $newId = GlpiPlugin\Sprint\Backlog::addFromLinkedItem($itemtype, $itemId);
        if ($newId > 0) {
            Session::addMessageAfterRedirect(__('Added to backlog', 'sprint'));
        } else {
            Session::addMessageAfterRedirect(
                __('Could not add item to backlog', 'sprint'),
                false,
                ERROR
            );
        }
    }
    Html::back();
}

if (isset($_POST['toggle_fastlane'])) {
    $id          = (int)($_POST['id'] ?? 0);
    $isFastlane  = (int)(bool)($_POST['is_fastlane'] ?? 0);

    if ($id > 0) {
        $item = new GlpiPlugin\Sprint\SprintItem();
        $item->check($id, UPDATE);
        if ($item->update([
            'id'          => $id,
            'is_fastlane' => $isFastlane,
        ])) {
            Session::addMessageAfterRedirect(
                $isFastlane
                    ? __('Item marked as fastlane', 'sprint')
                    : __('Fastlane flag removed', 'sprint')
            );
        }
    }
    Html::back();
}

if (isset($_POST['toggle_blocked'])) {
    $id        = (int)($_POST['id'] ?? 0);
    $isBlocked = (int)(bool)($_POST['is_blocked'] ?? 0);

    if ($id > 0) {
        $item = new GlpiPlugin\Sprint\SprintItem();
        $item->check($id, UPDATE);
        if ($item->update([
            'id'         => $id,
            'is_blocked' => $isBlocked,
        ])) {
            Session::addMessageAfterRedirect(
                $isBlocked
                    ? __('Item marked as blocked', 'sprint')
                    : __('Blocked flag removed', 'sprint')
            );
        }
    }
    Html::back();
}

if (isset($_POST['back_to_backlog'])) {
    $id = (int)($_POST['id'] ?? 0);

    if ($id > 0) {
        $item = new GlpiPlugin\Sprint\SprintItem();
        $item->check($id, UPDATE);
        if ($item->update([
            'id'                       => $id,
            'plugin_sprint_sprints_id' => 0,
            'is_fastlane'              => 0,
        ])) {
            Session::addMessageAfterRedirect(__('Item moved back to backlog', 'sprint'));
        }
    }

    if (!empty($_POST['_redirect'])) {
        Html::redirect($_POST['_redirect']);
    }
    Html::back();
}

if (isset($_POST['carry_over_to_sprint'])) {
    $id       = (int)($_POST['id'] ?? 0);
    $sprintId = (int)($_POST['plugin_sprint_sprints_id'] ?? 0);

    if ($id <= 0 || $sprintId <= 0) {
        Session::addMessageAfterRedirect(
            __('Please select a sprint', 'sprint'),
            false,
            ERROR
        );
    } else {
        $newId = GlpiPlugin\Sprint\SprintItem::carryOverTo($id, $sprintId);
        $sprint = new GlpiPlugin\Sprint\Sprint();
        $sprintName = ($sprint->getFromDB($sprintId)) ? $sprint->fields['name'] : '';
        if ($newId > 0) {
            Session::addMessageAfterRedirect(
                sprintf(__('Carried over to %s', 'sprint'), $sprintName)
            );
        } else {
            Session::addMessageAfterRedirect(
                __('Could not carry the item over to the target sprint', 'sprint'),
                false,
                ERROR
            );
        }
    }

    if (!empty($_POST['_redirect'])) {
        Html::redirect($_POST['_redirect']);
    }
    Html::back();
}

if (isset($_POST['assign_to_sprint'])) {
    $id       = (int)($_POST['id'] ?? 0);
    $sprintId = (int)($_POST['plugin_sprint_sprints_id'] ?? 0);

    if ($id <= 0 || $sprintId <= 0) {
        Session::addMessageAfterRedirect(
            __('Please select a sprint', 'sprint'),
            false,
            ERROR
        );
        if (!empty($_POST['_redirect'])) {
            Html::redirect($_POST['_redirect']);
        }
        Html::back();
    }

    $item = new GlpiPlugin\Sprint\SprintItem();
    $item->check($id, UPDATE);
    if ($item->update([
        'id'                       => $id,
        'plugin_sprint_sprints_id' => $sprintId,
    ])) {
        Session::addMessageAfterRedirect(__('Item assigned to sprint', 'sprint'));
    }

    if (!empty($_POST['_redirect'])) {
        Html::redirect($_POST['_redirect']);
    }
    Html::back();
}

if (isset($_POST['purge'])) {
    $id   = (int)($_POST['id'] ?? 0);
    $item = new GlpiPlugin\Sprint\SprintItem();
    $item->check($id, PURGE);
    $item->delete(['id' => $id], 1);
    Html::back();
}

Html::back();
