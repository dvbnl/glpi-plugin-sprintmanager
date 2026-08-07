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
