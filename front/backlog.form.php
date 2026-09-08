<?php

/**
 * Backlog form handler (add_to_backlog / assign_to_sprint / purge).
 *
 * No Session::checkCSRF() here on purpose: CommonDBTM add/update/delete
 * already validate the same single-use token, so calling checkCSRF() first
 * consumes it and the later validation fails with HTTP 403.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkRight('plugin_sprint_item', READ);

/**
 * Follow only same-site redirect targets; absolute or protocol-relative
 * URLs (open redirect) fall back to the referer.
 */
function plugin_sprint_safe_redirect(string $target): void
{
    if ($target === '' || preg_match('#^\s*(?:[a-z][a-z0-9+.\-]*:|//|\\\\)#i', $target)) {
        Html::back();
    }
    Html::redirect($target);
}

/**
 * Planning fields from the Sprint-tab panel (owner, capacity, sprint,
 * category, customer, credits, fastlane). Only the whitelisted keys reach the item; the values
 * are normalized inside SprintItem::prepareInputForAdd/Update.
 */
function plugin_sprint_backlog_plan_fields(array $post): array
{
    $out = [];
    foreach ([
        'users_id', 'capacity', 'credits', 'proposed_sprints_id',
        'plugin_sprint_sprintcategories_id', 'plugin_sprint_sprintcustomers_id', 'is_fastlane',
        'plugin_sprint_sprintcreditproducts_id',
    ] as $key) {
        if (array_key_exists($key, $post)) {
            $out[$key] = in_array($key, ['capacity', 'credits'], true) ? (float)$post[$key] : (int)$post[$key];
        }
    }
    return $out;
}

/**
 * May the current user edit this backlog row? Same rule as
 * ajax/updateitemquick.php: full UPDATE right, or the "own items" right on a
 * row you own.
 */
function plugin_sprint_backlog_can_edit_row(array $row): bool
{
    $isOwner = (int)($row['users_id'] ?? 0) === (int)Session::getLoginUserID();
    return Session::haveRight('plugin_sprint_item', UPDATE)
        || ($isOwner && Session::haveRight('plugin_sprint_item', GlpiPlugin\Sprint\Profile::RIGHT_OWN_ITEMS));
}

if (isset($_POST['add_to_backlog'])) {
    $itemtype = (string)($_POST['itemtype'] ?? '');
    $itemId   = (int)($_POST['items_id'] ?? 0);
    $hasCreate = Session::haveRight('plugin_sprint_item', CREATE);
    $ownOnly   = !$hasCreate && Session::haveRight('plugin_sprint_item', GlpiPlugin\Sprint\Profile::RIGHT_OWN_ITEMS);

    // The planning fields are only honoured for someone allowed to edit the
    // resulting row: an existing row follows the edit rule, a new row needs
    // CREATE (own-items users may only create rows they own themselves).
    $plan     = plugin_sprint_backlog_plan_fields($_POST);
    $existing = null;
    foreach ((new GlpiPlugin\Sprint\SprintItem())->find([
        'plugin_sprint_sprints_id' => 0, 'itemtype' => $itemtype, 'items_id' => $itemId,
    ], ['id ASC'], 1) as $row) {
        $existing = $row;
    }
    if ($existing !== null) {
        if (!plugin_sprint_backlog_can_edit_row($existing)) {
            $plan = [];
        }
    } elseif ($ownOnly) {
        $plan['users_id'] = (int)Session::getLoginUserID();
    } elseif (!$hasCreate) {
        $plan = [];
    }

    if (!$hasCreate && !$ownOnly) {
        Session::addMessageAfterRedirect(__('You are not allowed to add items to the backlog', 'sprint'), false, ERROR);
    } elseif (GlpiPlugin\Sprint\Backlog::isLinkedItemInAnySprint($itemtype, $itemId)) {
        Session::addMessageAfterRedirect(
            __('This item is already linked to a sprint — use "Carry over to sprint" to move it between sprints.', 'sprint'),
            false,
            ERROR
        );
    } else {
        $newId = GlpiPlugin\Sprint\Backlog::addFromLinkedItem($itemtype, $itemId, $plan);
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

if (isset($_POST['update_backlog_item'])) {
    $id   = (int)($_POST['id'] ?? 0);
    $item = new GlpiPlugin\Sprint\SprintItem();
    if ($id > 0 && $item->getFromDB($id) && (int)$item->fields['plugin_sprint_sprints_id'] === 0) {
        if (plugin_sprint_backlog_can_edit_row($item->fields)) {
            if ($item->update(['id' => $id] + plugin_sprint_backlog_plan_fields($_POST))) {
                Session::addMessageAfterRedirect(__('Backlog item updated', 'sprint'));
            }
        } else {
            Session::addMessageAfterRedirect(__('You are not allowed to edit this backlog item', 'sprint'), false, ERROR);
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

        $reason           = (string)($_POST['reason'] ?? '');
        $removeFromSprint = (int)($_POST['remove_from_sprint'] ?? 0) === 1;
        $outcome = GlpiPlugin\Sprint\SprintItem::backToBacklog($id, $reason, $removeFromSprint);

        if ($outcome['ok']) {
            Session::addMessageAfterRedirect(
                $outcome['stayed']
                    ? __('Underlying item moved to backlog. The sprint item stays for capacity.', 'sprint')
                    : __('Item moved back to backlog', 'sprint')
            );
        }
    }

    if (!empty($_POST['_redirect'])) {
        plugin_sprint_safe_redirect((string)$_POST['_redirect']);
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
            plugin_sprint_safe_redirect((string)$_POST['_redirect']);
        }
        Html::back();
    }

    $item = new GlpiPlugin\Sprint\SprintItem();
    if (!$item->getFromDB($id)) {
        Html::back();
    }

    // Normal items: only the target sprint's Scrum Master may assign (mirrors
    // ajax/assigntosprint.php; UPDATE right is not enough). Fastlane items are
    // interrupt work and may be assigned by anyone.
    $currentUserId = (int)Session::getLoginUserID();
    $isFastlane    = (int)($item->fields['is_fastlane'] ?? 0) === 1;
    $canAssign = $isFastlane
        || GlpiPlugin\Sprint\Config::isCurrentUserScrumMaster($sprintId)
        || GlpiPlugin\Sprint\SprintMember::isScrumMaster($sprintId, $currentUserId);

    if (!$canAssign) {
        Session::addMessageAfterRedirect(
            __('Only the Scrum Master of the selected sprint can assign items to it.', 'sprint'),
            false,
            ERROR
        );
    } elseif ($item->update([
        'id'                       => $id,
        'plugin_sprint_sprints_id' => $sprintId,
        'proposed_sprints_id'      => 0,
    ])) {
        GlpiPlugin\Sprint\SprintItem::purgeBacklogCoupling(
            (string)($item->fields['itemtype'] ?? ''),
            (int)($item->fields['items_id'] ?? 0),
            $id
        );
        Session::addMessageAfterRedirect(__('Item assigned to sprint', 'sprint'));
    }

    if (!empty($_POST['_redirect'])) {
        plugin_sprint_safe_redirect((string)$_POST['_redirect']);
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
