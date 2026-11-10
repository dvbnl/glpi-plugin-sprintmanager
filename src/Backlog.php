<?php

namespace GlpiPlugin\Sprint;

use Html;
use Session;
use Dropdown;
use Plugin;
use User;

/**
 * Sprint backlog: a virtual collection over SprintItem rows where
 * plugin_sprint_sprints_id = 0 (not a CommonDBTM). Provides the menu
 * entry and the listing/assignment UI.
 */
class Backlog
{
    /** UserPref name of the personal backlog view: 'queue' (work queue) or 'planning'. */
    public const VIEW_PREF = 'backlog_view';

    public static function getTypeName($nb = 0): string
    {
        return _n('Backlog', 'Backlog', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-layer-group';
    }

    public static function getSearchURL(bool $full = true): string
    {
        return Plugin::getWebDir('sprint', $full) . '/front/backlog.php';
    }

    public static function getFormURL(bool $full = true): string
    {
        return Plugin::getWebDir('sprint', $full) . '/front/backlog.form.php';
    }

    /**
     * Visibility piggy-backs on the SprintItem READ right (plus the
     * "own items" right), since the backlog is just a filtered view of them.
     */
    public static function canView(): bool
    {
        return Session::haveRight(SprintItem::$rightname, READ)
            || Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(SprintItem::$rightname, CREATE)
            || Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS);
    }


    /**
     * Sprint tab of a Ticket/Change/Problem/ProjectTask. Not on the backlog
     * yet: a 1-click "Add to backlog" button. On the backlog: a compact
     * planning line (owner, capacity, sprint, category, fastlane) with a
     * quick-edit dialog carrying the same fields as the backlog edit dialog,
     * so planning does not need a detour via the backlog page.
     * Renders a notice instead when the item is already in a sprint: backlog
     * and sprint membership are mutually exclusive (use "Carry over" instead).
     */
    public static function showAddToBacklogButton(string $itemtype, int $itemId): void
    {
        if (!Session::haveRight(SprintItem::$rightname, CREATE)
            && !Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS)) {
            return;
        }

        $allowed = ['Ticket', 'Change', 'Problem', 'ProjectTask'];
        if (!in_array($itemtype, $allowed, true) || $itemId <= 0) {
            return;
        }

        if (self::isLinkedItemInAnySprint($itemtype, $itemId)) {
            self::showSprintPlanLine($itemtype, $itemId);
            return;
        }

        $existing = null;
        foreach ((new SprintItem())->find([
            'plugin_sprint_sprints_id' => 0,
            'itemtype'                 => $itemtype,
            'items_id'                 => $itemId,
        ], ['id ASC'], 1) as $row) {
            $existing = $row;
        }

        if ($existing === null) {
            // Real <button> instead of Html::submit() so the icon survives
            // (Html::submit escapes its value).
            echo "<div class='center sprint-plan-add'>";
            echo "<form method='post' action='" . self::getFormURL() . "' class='d-inline'>";
            echo Html::hidden('itemtype', ['value' => $itemtype]);
            echo Html::hidden('items_id', ['value' => $itemId]);
            echo "<button type='submit' name='add_to_backlog' value='1' class='btn btn-outline-secondary'>"
                . "<i class='fas fa-layer-group' aria-hidden='true'></i> " . __('Add to backlog', 'sprint')
                . "</button>";
            Html::closeForm();
            echo "</div>";
            return;
        }

        // Editing mirrors ajax/updateitemquick.php: the UPDATE right, or the
        // "own items" right on a row you own.
        $isOwner = (int)($existing['users_id'] ?? 0) === (int)Session::getLoginUserID();
        $canEdit = Session::haveRight(SprintItem::$rightname, UPDATE)
            || ($isOwner && Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS));

        $ownerId       = (int)($existing['users_id'] ?? 0);
        $capacity      = (float)($existing['capacity'] ?? 0);
        $proposedId    = (int)($existing['proposed_sprints_id'] ?? 0);
        $categoryId    = (int)($existing['plugin_sprint_sprintcategories_id'] ?? 0);
        $isFastlane    = (int)($existing['is_fastlane'] ?? 0) === 1;
        $sprintName    = '';
        if ($proposedId > 0) {
            $sp = new Sprint();
            $sprintName = $sp->getFromDB($proposedId) ? (string)$sp->fields['name'] : ('#' . $proposedId);
        }
        $muted = "<span class='text-muted'>-</span>";
        $chip  = function (string $icon, string $label, string $value): string {
            return "<span class='sprint-plan-chip' title='" . htmlescape($label) . "'>"
                . "<i class='{$icon} text-muted me-1' aria-hidden='true'></i>{$value}</span>";
        };

        // Compact planning line.
        echo "<div class='sprint-plan-panel'>";
        echo "<span class='fw-bold'><i class='fas fa-layer-group me-1' aria-hidden='true'></i>" . __('Backlog planning', 'sprint') . "</span>";
        echo $chip('fas fa-user', __('Owner', 'sprint'), $ownerId > 0 ? htmlescape(SprintCache::userName($ownerId)) : $muted);
        $capValue = $capacity > 0 ? SprintMember::formatCapacity($capacity) . '%' : $muted;
        echo $chip('fas fa-gauge-high', __('Est. capacity', 'sprint'), $capValue);
        echo $chip('fas fa-flag-checkered', __('Proposed sprint', 'sprint'), $sprintName !== '' ? htmlescape($sprintName) : $muted);
        echo $chip('fas fa-folder', __('Category', 'sprint'), $categoryId > 0 ? SprintCategory::renderPill($categoryId) : $muted);
        if (SprintCustomer::canUseCredits()) {
            $customerId = (int)($existing['plugin_sprint_sprintcustomers_id'] ?? 0);
            $credits    = (float)($existing['credits'] ?? 0);
            echo $chip(
                'fas fa-coins',
                __('Customer / credits', 'sprint'),
                ($customerId > 0 ? SprintCustomer::renderPill($customerId) : $muted)
                    . ($credits > 0 ? ' <span class="fw-bold">' . htmlescape(SprintCustomer::formatCredits($credits)) . '</span>' : '')
            );
        }
        if ($isFastlane) {
            echo "<span class='sprint-plan-chip'><i class='fas fa-bolt sprint-tone-orange' aria-hidden='true'></i> " . __('Fastlane', 'sprint') . "</span>";
        }
        // Same planning-completeness rule (and wording) as the backlog row.
        echo self::renderPlanningBadge($existing);
        echo "<span class='sprint-flex-fill'></span>";
        if ($canEdit) {
            echo "<button type='button' class='btn btn-sm btn-outline-primary' data-bs-toggle='modal' data-bs-target='#sprint-plan-modal'>"
                . SprintIcon::html(SprintIcon::EDIT, 'me-1') . __('Quick edit', 'sprint') . "</button>";
            if ($proposedId > 0) {
                self::renderPlanAssignControls($existing, $proposedId, $sprintName, $isFastlane);
            }
        }
        echo "</div>";

        if (!$canEdit) {
            return;
        }

        // Quick-edit dialog: same fields as the backlog edit dialog, plain
        // POST (front/backlog.form.php update_backlog_item) so it works
        // without the backlog page's scripts.
        $rand = mt_rand();
        echo "<div class='modal fade' id='sprint-plan-modal' tabindex='-1' aria-hidden='true' aria-labelledby='sprint-plan-modal-title'>";
        echo "<div class='modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg'><div class='modal-content'>";
        echo "<form method='post' action='" . self::getFormURL() . "'>";
        echo "<div class='modal-header'><h5 class='modal-title' id='sprint-plan-modal-title'>" . SprintIcon::html(SprintIcon::EDIT, 'me-1') . " " . __('Backlog planning', 'sprint') . "</h5>"
            . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='" . __s('Close') . "'></button></div>";
        echo "<div class='modal-body'>";
        echo Html::hidden('itemtype', ['value' => $itemtype]);
        echo Html::hidden('items_id', ['value' => $itemId]);
        echo Html::hidden('id', ['value' => (int)$existing['id']]);
        echo "<div class='row g-3'>";

        echo "<div class='col-md-6 mb-2'><label class='form-label' for='dropdown_users_id{$rand}'>" . __('Owner', 'sprint') . "</label>";
        User::dropdown([
            'name'   => 'users_id',
            'value'  => $ownerId,
            'right'  => 'all',
            'entity' => $_SESSION['glpiactiveentities'] ?? -1,
            'rand'   => $rand,
            'width'  => '100%',
        ]);
        echo "</div>";

        echo "<div class='col-md-6 mb-2'><label class='form-label' for='dropdown_capacity{$rand}'>" . __('Est. capacity', 'sprint') . " %</label>";
        Dropdown::showFromArray('capacity', ['0' => '-----'] + SprintMember::getCapacityChoices(), [
            'value' => $capacity > 0 ? SprintMember::capacityKey($capacity) : '0',
            'rand'  => $rand,
            'width' => '100%',
        ]);
        echo "</div>";
        $justification = SprintItem::capacityJustificationField((string)($existing['capacity_justification'] ?? ''), "sprint-plan-capjust{$rand}");
        if ($justification !== '') {
            echo "<div class='col-12 mb-2'><label class='form-label' for='sprint-plan-capjust{$rand}'>" . __('Reason above the maximum', 'sprint') . "</label>{$justification}</div>";
        }

        echo "<div class='col-md-6 mb-2'><label class='form-label' for='dropdown_proposed_sprints_id{$rand}'>" . __('Proposed sprint', 'sprint') . "</label>";
        Sprint::dropdown([
            'name'  => 'proposed_sprints_id',
            'value' => $proposedId,
            'rand'  => $rand,
            'width' => '100%',
        ]);
        echo "<div class='form-text sprint-small text-muted'>"
            . __s('A proposal only: the item stays on the backlog until the Scrum Master assigns it to this sprint.', 'sprint') . "</div>";
        echo "</div>";

        echo "<div class='col-md-6 mb-2'><label class='form-label' for='sprint-plan-category{$rand}'><i class='fas fa-folder me-1' aria-hidden='true'></i>" . __('Category', 'sprint') . "</label>";
        echo "<select class='form-select' id='sprint-plan-category{$rand}' name='plugin_sprint_sprintcategories_id'>";
        echo "<option value='0'>-----</option>";
        echo SprintCategory::dropdownOptions($categoryId);
        echo "</select></div>";

        if (SprintCustomer::canUseCredits()) {
            echo "<div class='col-md-6 mb-2'><label class='form-label'><i class='fas fa-building me-1' aria-hidden='true'></i>"
                . SprintCustomer::getTypeName(1) . "</label>";
            echo SprintItem::customerSelect(
                'plugin_sprint_sprintcustomers_id',
                (int)($existing['plugin_sprint_sprintcustomers_id'] ?? 0)
            );
            echo "</div>";
            echo "<div class='col-md-6 mb-2'><label class='form-label'><i class='fas fa-coins me-1' aria-hidden='true'></i>"
                . __('Credits', 'sprint') . "</label>";
            echo "<div class='d-flex gap-2'>";
            echo SprintItem::productSelect(
                'plugin_sprint_sprintcreditproducts_id',
                (int)($existing['plugin_sprint_sprintcreditproducts_id'] ?? 0),
                'credits'
            );
            echo SprintItem::creditsInput('credits', $existing['credits'] ?? 0);
            echo "</div>";
            echo "</div>";
            SprintItem::creditProductScript();
        }

        echo "<div class='col-12 d-flex flex-wrap gap-4'>";
        echo "<input type='hidden' name='is_fastlane' value='0'>";
        echo "<label class='form-check form-switch mb-0'>";
        echo "<input class='form-check-input' type='checkbox' name='is_fastlane' value='1'" . ($isFastlane ? ' checked' : '') . ">";
        echo "<span class='form-check-label'><i class='fas fa-bolt sprint-tone-orange' aria-hidden='true'></i> " . __('Fastlane', 'sprint') . "</span>";
        echo "</label>";
        // Adhoc can be pre-flagged on the backlog by anyone who may edit the
        // row; the flag travels with the item into its sprint.
        $isAdhoc = (int)($existing['is_adhoc'] ?? 0) === 1;
        echo "<input type='hidden' name='is_adhoc' value='0'>";
        echo "<label class='form-check form-switch mb-0' title='" . __s('Added to the sprint after kick-off (highlights the item)', 'sprint') . "'>";
        echo "<input class='form-check-input' type='checkbox' name='is_adhoc' value='1'" . ($isAdhoc ? ' checked' : '') . ">";
        echo "<span class='form-check-label'><span class='sprint-adhoc-badge'><i class='fas fa-plus-circle' aria-hidden='true'></i> " . __('Adhoc', 'sprint') . "</span></span>";
        echo "</label></div>";

        $definedTags = Config::getDefinedTags();
        if ($definedTags) {
            $assigned = array_flip(array_map('mb_strtolower', SprintItem::getTagsForItem((int)$existing['id'])));
            echo "<fieldset class='col-12 mb-0'><legend class='form-label fs-5 mb-1'><i class='fas fa-tags me-1' aria-hidden='true'></i>" . __('Tags', 'sprint')
                . (Config::isTagRequired() ? " <span class='text-danger'>*</span>" : '') . "</legend>";
            echo "<input type='hidden' name='_tags' value=''>";
            echo "<div class='d-flex flex-wrap gap-3'>";
            foreach ($definedTags as $tag) {
                echo "<label class='sprint-inline-check'>"
                    . "<input type='checkbox' name='_tags[]' value='" . htmlescape($tag) . "'"
                    . (isset($assigned[mb_strtolower($tag)]) ? ' checked' : '') . ">"
                    . "<span>" . htmlescape($tag) . "</span></label>";
            }
            echo "</div></fieldset>";
        }

        echo "</div></div>";
        echo "<div class='modal-footer'>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . __('Cancel') . "</button>";
        echo "<button type='submit' name='update_backlog_item' value='1' class='btn btn-primary'>"
            . "<i class='fas fa-save me-1' aria-hidden='true'></i>" . __('Save') . "</button>";
        echo "</div>";
        Html::closeForm();
        echo "</div></div></div>";
        // Modals nested in the tab content get clipped by the tab's
        // stacking context; move it to <body> like the other dialogs.
        echo "<script>(function(){var m=document.getElementById('sprint-plan-modal');if(m&&m.parentNode!==document.body){document.body.appendChild(m);}})();</script>";
    }

    /**
     * Planning line for a GLPI item that already sits in a sprint: shows its
     * latest sprint item and offers a quick edit (adhoc, tags) plus carry-over
     * into a new sprint — the way to pick up a ticket that was reopened after
     * its sprint was completed, without opening an old meeting. Posts to
     * front/sprintitem.form.php (update_sprint_plan).
     */
    private static function showSprintPlanLine(string $itemtype, int $itemId): void
    {
        global $DB;

        // Latest sprint first: that is the one a carry-over continues from.
        $row = null;
        foreach ($DB->request([
            'SELECT'    => ['i.*', 's.name AS sprint_name', 's.status AS sprint_status'],
            'FROM'      => SprintItem::getTable() . ' AS i',
            'INNER JOIN' => [
                Sprint::getTable() . ' AS s' => ['ON' => ['i' => 'plugin_sprint_sprints_id', 's' => 'id']],
            ],
            'WHERE'     => ['i.itemtype' => $itemtype, 'i.items_id' => $itemId],
            'ORDER'     => ['s.date_start DESC', 'i.id DESC'],
        ]) as $candidate) {
            if (SprintItem::sprintEntityAccessible((int)$candidate['plugin_sprint_sprints_id'])) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null) {
            return;
        }

        $item = new SprintItem();
        if (!$item->getFromDB((int)$row['id'])) {
            return;
        }
        $canEdit  = $item->canUpdateItem();
        $sprintId = (int)$row['plugin_sprint_sprints_id'];
        $closed   = in_array((string)$row['sprint_status'], [Sprint::STATUS_COMPLETED, Sprint::STATUS_CANCELLED], true);
        $sprintStatuses = Sprint::getAllStatuses();
        $itemStatuses   = SprintItem::getAllStatuses();
        $isAdhoc  = (int)($row['is_adhoc'] ?? 0) === 1;
        $tags     = SprintItem::getTagsForItem((int)$row['id']);
        $muted    = "<span class='text-muted'>-</span>";

        echo "<div class='sprint-plan-panel'>";
        echo "<span class='fw-bold'><i class='fas fa-person-running me-1' aria-hidden='true'></i>" . __('Sprint planning', 'sprint') . "</span>";
        echo "<span class='sprint-plan-chip'><i class='fas fa-flag-checkered text-muted me-1' aria-hidden='true'></i>"
            . "<a href='" . Sprint::getFormURLWithID($sprintId) . "'>" . htmlescape((string)$row['sprint_name']) . "</a>"
            . " <span class='badge " . ($closed ? 'bg-secondary' : 'bg-primary') . "'>"
            . htmlescape($sprintStatuses[$row['sprint_status']] ?? (string)$row['sprint_status']) . "</span></span>";
        echo "<span class='sprint-badge sprint-status-" . htmlescape(str_replace('_', '-', (string)$row['status'])) . "' title='" . __s('Status') . "'>"
            . htmlescape($itemStatuses[$row['status']] ?? (string)$row['status']) . "</span>";
        echo "<span class='sprint-plan-chip' title='" . __s('Owner', 'sprint') . "'><i class='fas fa-user text-muted me-1' aria-hidden='true'></i>"
            . ((int)$row['users_id'] > 0 ? htmlescape(SprintCache::userName((int)$row['users_id'])) : $muted) . "</span>";
        if ($isAdhoc) {
            echo SprintItem::renderAdhocBadge(true);
        }
        if ($tags) {
            echo SprintItem::renderTagPills($tags);
        }
        echo "<span class='sprint-flex-fill'></span>";
        echo "<a class='btn btn-sm btn-outline-secondary' href='" . SprintItem::getFormURLWithID((int)$row['id']) . "'>"
            . "<i class='fas fa-up-right-from-square me-1' aria-hidden='true'></i>" . __('Open sprint item', 'sprint') . "</a>";
        if ($canEdit) {
            echo "<button type='button' class='btn btn-sm btn-outline-primary' data-bs-toggle='modal' data-bs-target='#sprint-plan-modal'>"
                . SprintIcon::html(SprintIcon::EDIT, 'me-1') . __('Quick edit', 'sprint') . "</button>";
        }
        echo "</div>";

        // Reopened after the sprint closed: point at the next step.
        if ($closed && $canEdit) {
            echo "<div class='alert alert-info py-2 sprint-plan-note'>"
                . SprintIcon::html(SprintIcon::CARRYOVER, 'me-1')
                . htmlescape(__('This item\'s sprint is closed. Use Quick edit to carry it over to a new sprint.', 'sprint'))
                . "</div>";
        }
        if (!$canEdit) {
            return;
        }

        $isMaster = SprintItem::currentUserIsScrumMasterOf($sprintId);
        $targets  = Sprint::getMoveTargetOptions($sprintId);
        foreach (array_keys($targets) as $tid) {
            if (SprintItem::isLinkedItemInSprint((int)$tid, $itemtype, $itemId)) {
                unset($targets[$tid]);
            }
        }
        $pendingCarry = SprintRequest::ensureTable() && countElementsInTable(SprintRequest::getTable(), [
            'request_type'                 => SprintRequest::TYPE_CARRYOVER,
            'plugin_sprint_sprintitems_id' => (int)$row['id'],
            'status'                       => SprintRequest::STATUS_PENDING,
        ]) > 0;

        echo "<div class='modal fade' id='sprint-plan-modal' tabindex='-1' aria-hidden='true' aria-labelledby='sprint-plan-modal-title'>";
        echo "<div class='modal-dialog modal-dialog-centered modal-lg'><div class='modal-content'>";
        echo "<form method='post' action='" . SprintItem::getFormURL() . "'>";
        echo "<div class='modal-header'><h5 class='modal-title' id='sprint-plan-modal-title'>" . SprintIcon::html(SprintIcon::EDIT, 'me-1') . " "
            . htmlescape(sprintf(__('Quick edit: %s', 'sprint'), (string)$row['name'])) . "</h5>"
            . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='" . __s('Close') . "'></button></div>";
        echo "<div class='modal-body'>";
        echo Html::hidden('id', ['value' => (int)$row['id']]);

        echo "<div class='mb-3'>";
        echo "<input type='hidden' name='is_adhoc' value='0'>";
        echo "<label class='form-check form-switch mb-0'>";
        echo "<input class='form-check-input' type='checkbox' name='is_adhoc' value='1'" . ($isAdhoc ? ' checked' : '') . ">";
        echo "<span class='form-check-label'><span class='sprint-adhoc-badge'><i class='fas fa-plus-circle' aria-hidden='true'></i> " . __('Adhoc', 'sprint') . "</span> "
            . htmlescape(__('Added to the sprint after kick-off (highlights the item)', 'sprint')) . "</span>";
        echo "</label>";
        if (!$isMaster) {
            echo "<div class='form-text sprint-small text-warning'><i class='fas fa-user-shield me-1' aria-hidden='true'></i>"
                . htmlescape(__('Adhoc changes are sent to the Scrum Master for approval.', 'sprint')) . "</div>";
        }
        echo "</div>";

        $definedTags = Config::getDefinedTags();
        if ($definedTags) {
            $assigned = array_flip(array_map('mb_strtolower', $tags));
            echo "<fieldset class='mb-3'><legend class='form-label fs-5'><i class='fas fa-tags me-1' aria-hidden='true'></i>" . __('Tags', 'sprint')
                . (Config::isTagRequired() ? " <span class='text-danger'>*</span>" : '') . "</legend>";
            echo "<input type='hidden' name='_tags' value=''>";
            echo "<div class='d-flex flex-wrap gap-3'>";
            foreach ($definedTags as $tag) {
                echo "<label class='sprint-inline-check'>"
                    . "<input type='checkbox' name='_tags[]' value='" . htmlescape($tag) . "'"
                    . (isset($assigned[mb_strtolower($tag)]) ? ' checked' : '') . ">"
                    . "<span>" . htmlescape($tag) . "</span></label>";
            }
            echo "</div></fieldset>";
        }

        echo "<div class='mb-2'><label class='form-label' for='sprint-plan-carry'>" . SprintIcon::html(SprintIcon::CARRYOVER, 'sprint-tone-success me-1')
            . __('Carry over to sprint', 'sprint') . "</label>";
        if ($pendingCarry) {
            echo "<div class='text-muted'><i class='fas fa-hourglass-half me-1' aria-hidden='true'></i>"
                . __('A carry-over is already waiting for the Scrum Master.', 'sprint') . "</div>";
        } else {
            echo "<select class='form-select' name='carry_over_to_sprint_id' id='sprint-plan-carry'>";
            echo "<option value='0'>— " . htmlescape(__('Do not carry over', 'sprint')) . " —</option>";
            foreach ($targets as $tid => $tlabel) {
                echo "<option value='" . (int)$tid . "'>" . htmlescape((string)$tlabel) . "</option>";
            }
            echo "</select>";
            echo "<div class='form-text sprint-small text-muted'>"
                . htmlescape(__('A fresh copy is created in the target sprint. The original stays in this sprint.', 'sprint'))
                . " " . htmlescape(__('Carry-overs into a sprint you are not Scrum Master of are sent to that sprint\'s Scrum Master for approval.', 'sprint'))
                . "</div>";
        }
        echo "</div>";
        echo "<div class='mb-2'><label class='form-label' for='sprint-plan-reason'>" . __('Reason', 'sprint') . "</label>";
        echo "<input type='text' class='form-control' name='carry_reason' id='sprint-plan-reason' placeholder='"
            . __s('e.g. the ticket was reopened', 'sprint') . "'>";
        echo "<div class='form-text sprint-small text-muted'>" . __('Shown to the Scrum Master with your request.', 'sprint') . "</div></div>";

        echo "</div>";
        echo "<div class='modal-footer'>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . __('Cancel') . "</button>";
        echo "<button type='submit' name='update_sprint_plan' value='1' class='btn btn-primary'>"
            . "<i class='fas fa-save me-1' aria-hidden='true'></i>" . __('Save') . "</button>";
        echo "</div>";
        Html::closeForm();
        echo "</div></div></div>";
        echo "<script>(function(){var all=document.querySelectorAll('#sprint-plan-modal');for(var i=0;i<all.length-1;i++){all[i].parentNode.removeChild(all[i]);}var m=all[all.length-1];if(m&&m.parentNode!==document.body){document.body.appendChild(m);}})();</script>";
    }

    /**
     * Assign / "ask the Scrum Master" controls of the planning line on the
     * Sprint tab, mirroring the backlog row: the Scrum Master of the proposed
     * sprint (or anyone, for fastlane items) assigns straight away — behind
     * the Definition of Ready when one is configured — everyone else files an
     * assignment request that lands in the Scrum Master's approval panel.
     *
     * Assigning runs through ajax/assigntosprint.php with the dialogs of the
     * backlog row (window.sprintDorConfirm, window.sprintAssignGuard), driven
     * by window.sprintPlanAssign in js/sprint.js. Without those helpers the
     * same button falls back to the plain POST to front/backlog.form.php,
     * with its questions in a server-rendered dialog.
     */
    private static function renderPlanAssignControls(array $row, int $proposedId, string $sprintName, bool $isFastlane): void
    {
        $flags = self::computeRowFlags($row);
        $rowId = (int)$row['id'];

        if ($flags['can_assign']) {
            $dor      = $isFastlane ? [] : Config::getDefinitionReady();
            $modalId  = 'sprint-plan-assign-modal';
            $btnTitle = $isFastlane
                ? __s('Fastlane item — anyone can assign it to a sprint', 'sprint')
                : __s('Assign to the selected sprint', 'sprint');
            $btnLabel = "<i class='fas fa-arrow-right me-1' aria-hidden='true'></i>" . htmlescape(sprintf(__('Assign to %s', 'sprint'), $sprintName));
            // What window.sprintPlanAssign needs to run the AJAX path.
            $assignData = " data-item-id='{$rowId}' data-sprint-id='{$proposedId}'"
                . " data-item-name='" . htmlescape((string)($row['name'] ?? '')) . "'"
                . " data-is-fastlane='" . ($isFastlane ? 1 : 0) . "' data-needs-dor='" . ($dor ? 1 : 0) . "'";

            // The Definition of Ready dialog of the backlog row.
            if ($dor) {
                self::renderDorDialog();
            }

            // The fallback's questions, rendered up front (no JS round trip on
            // that path): items above the maximum capacity per item and
            // customers past their credits for the sprint.
            $capReview = SprintAssignGuard::capacityReview([$row], [])['review'];
            $overruns  = SprintAssignGuard::creditOverruns([$row], $proposedId);

            if (!$dor && !$capReview && !$overruns) {
                // js/sprint.js takes over the submit, otherwise it posts as it is.
                echo "<form method='post' action='" . self::getFormURL() . "' class='d-inline sprint-plan-assign-form'{$assignData}>";
                echo Html::hidden('id', ['value' => $rowId]);
                echo Html::hidden('plugin_sprint_sprints_id', ['value' => $proposedId]);
                echo "<button type='submit' name='assign_to_sprint' value='1' class='btn btn-sm btn-primary' title='{$btnTitle}'>{$btnLabel}</button>";
                Html::closeForm();
                return;
            }

            // Fallback dialog: the checks are confirmed in it and travel with
            // the POST (front/backlog.form.php enforces them again).
            $dialogTitle = $dor ? __s('Definition of Ready', 'sprint') : htmlescape(sprintf(__('Assign to %s', 'sprint'), $sprintName));
            echo "<button type='button' class='btn btn-sm btn-primary sprint-plan-assign-open' data-fallback-modal='{$modalId}'{$assignData} title='{$btnTitle}'>{$btnLabel}</button>";
            echo "<div class='modal fade' id='{$modalId}' tabindex='-1' aria-hidden='true' aria-labelledby='{$modalId}-title'>";
            echo "<div class='modal-dialog modal-dialog-centered'><div class='modal-content'>";
            echo "<form method='post' action='" . self::getFormURL() . "'>";
            echo "<div class='modal-header'><h5 class='modal-title' id='{$modalId}-title'><i class='fas fa-clipboard-check me-1' aria-hidden='true'></i> "
                . $dialogTitle . "</h5>"
                . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='" . __s('Close') . "'></button></div>";
            echo "<div class='modal-body'>";
            echo Html::hidden('id', ['value' => $rowId]);
            echo Html::hidden('plugin_sprint_sprints_id', ['value' => $proposedId]);
            if ($dor) {
                echo "<p class='text-muted sprint-small'>" . __s('Confirm the checks that hold for this item.', 'sprint') . "</p>";
                foreach ($dor as $check) {
                    echo "<label class='form-check d-block'><input class='form-check-input sprint-plan-dor-check' type='checkbox' name='ready[]' value='"
                        . htmlescape($check) . "'><span class='form-check-label'>" . htmlescape($check) . "</span></label>";
                }
            }
            foreach ($capReview as $cr) {
                $capId = "sprint-plan-capreview-{$rowId}";
                echo "<div class='alert alert-warning py-2 mt-2 mb-2 sprint-small'>"
                    . "<div class='fw-bold mb-1'><i class='fas fa-gauge-high me-1' aria-hidden='true'></i>"
                    . htmlescape(__('Capacity above the maximum', 'sprint')) . " <span class='badge bg-yellow-lt'>" . htmlescape($cr['capacity']) . "% (+" . htmlescape($cr['over']) . "%)</span></div>"
                    . "<div class='mb-2'>" . htmlescape(sprintf(__('%s%% is the maximum capacity per item. Approve the reason or lower the capacity.', 'sprint'), SprintMember::formatCapacity(SprintItem::itemCapacityLimit()))) . "</div>"
                    . "<div class='mb-2 sprint-pre-line'>" . ($cr['justification'] !== ''
                        ? "<span class='fw-bold'>" . htmlescape(__('Reason', 'sprint')) . ":</span> " . htmlescape($cr['justification'])
                        : "<span class='text-muted fst-italic'>" . htmlescape(__('No reason given', 'sprint')) . "</span>") . "</div>"
                    . "<label class='form-label mb-1' for='{$capId}'>" . htmlescape(__('Capacity (%)', 'sprint')) . "</label>"
                    . "<input type='number' class='form-control form-control-sm sprint-mw-120' id='{$capId}' name='cap_capacity[{$rowId}]'"
                    . " min='0.5' max='" . htmlescape($cr['capacity']) . "' step='0.5' value='" . htmlescape($cr['capacity']) . "'>"
                    . "<div class='text-muted'>" . htmlescape(__('Keep the value to approve it, or lower it', 'sprint')) . "</div>"
                    . "</div>";
            }
            if ($overruns) {
                echo "<div class='alert alert-warning py-2 mt-2 mb-2 sprint-small'>"
                    . "<div class='fw-bold mb-1'><i class='fas fa-coins me-1' aria-hidden='true'></i>" . htmlescape(__('Credits past the agreement', 'sprint')) . "</div>"
                    . "<div class='sprint-pre-line'>" . htmlescape(SprintAssignGuard::creditOverrunMessage($overruns, $sprintName)) . "</div>"
                    . "<label class='form-check d-block mt-2'><input class='form-check-input sprint-plan-dor-check' type='checkbox' name='confirm_credits' value='1'>"
                    . "<span class='form-check-label'>" . htmlescape(__('Assign anyway', 'sprint')) . "</span></label>"
                    . "</div>";
            }
            echo "</div>";
            echo "<div class='modal-footer'>";
            echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . __s('Cancel') . "</button>";
            echo "<button type='submit' name='assign_to_sprint' value='1' class='btn btn-success sprint-plan-dor-go'" . (($dor || $overruns) ? ' disabled' : '') . ">"
                . "<i class='fas fa-check me-1' aria-hidden='true'></i>" . __s('Assign', 'sprint') . "</button>";
            echo "</div>";
            Html::closeForm();
            echo "</div></div></div>";
            echo <<<HTML
<script>
(function(){
    // Move the dialog to <body> (the tab clips modals); a GLPI ajax-tab reload
    // re-echoes it, so drop the stale copy from an earlier load first.
    var all = document.querySelectorAll('#{$modalId}');
    for (var i = 0; i < all.length - 1; i++) { all[i].parentNode.removeChild(all[i]); }
    var m = all[all.length - 1];
    if (m && m.parentNode !== document.body) { document.body.appendChild(m); }
    if (typeof jQuery === 'undefined' || window.__sprintPlanDorBound) { return; }
    window.__sprintPlanDorBound = true;
    jQuery(document).on('change', '.sprint-plan-dor-check', function(){
        var \$m = jQuery(this).closest('.modal');
        \$m.find('.sprint-plan-dor-go').prop('disabled', \$m.find('.sprint-plan-dor-check:not(:checked)').length > 0);
    });
    // The AJAX path with the shared dialogs when js/sprint.js offers it,
    // otherwise the dialog above with its plain POST.
    jQuery(document).on('click', '.sprint-plan-assign-open', function(){
        if (typeof window.sprintPlanAssign === 'function' && window.sprintPlanAssign(this)) { return; }
        var modal = document.getElementById(this.getAttribute('data-fallback-modal') || '');
        if (modal && window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        }
    });
})();
</script>
HTML;
            return;
        }

        // Not the Scrum Master: ask them. Handled by ajax/requestcreate.php,
        // same as the backlog row button.
        $lblWaiting = __s('Requested — waiting for Scrum Master', 'sprint');
        $waitChip   = "<span class='sprint-chip sprint-chip-warning sprint-plan-requested' title='"
            . __s('Assignment already requested — waiting for the Scrum Master', 'sprint') . "'>"
            . "<i class='fas fa-hourglass-half me-1' aria-hidden='true'></i>{$lblWaiting}</span>";
        if ($flags['pending_assign']) {
            echo $waitChip;
            return;
        }
        echo "<button type='button' class='btn btn-sm btn-outline-primary sprint-plan-request-btn' data-item-id='{$rowId}' "
            . "title='" . __s('Ask the Scrum Master to assign this item to the selected sprint', 'sprint') . "'>"
            . "<i class='fas fa-paper-plane me-1' aria-hidden='true'></i>" . __s('Ask the Scrum Master', 'sprint') . "</button>";
        // Cloned in place of the button once the request is filed.
        echo "<template class='sprint-plan-requested-tpl'>{$waitChip}</template>";

        SprintRequest::renderReasonModalUI();
        $requestUrl = Plugin::getWebDir('sprint') . '/ajax/requestcreate.php';
        $msgRequested = json_encode(__('Requested', 'sprint'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        $msgFailed    = json_encode(__('Request failed', 'sprint'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        echo <<<HTML
<script>
(function(){
    if (typeof jQuery === 'undefined' || window.__sprintPlanRequestBound) { return; }
    window.__sprintPlanRequestBound = true;
    function send(\$btn, itemId, reason) {
        \$btn.prop('disabled', true);
        // sprintUi.post reports a network error itself.
        window.sprintUi.post("{$requestUrl}", { id: itemId, reason: reason }).done(function(resp) {
            if (resp && resp.success) {
                window.sprintNotify(resp.message || {$msgRequested}, 'success');
                var tpl = \$btn.siblings('template.sprint-plan-requested-tpl')[0];
                if (tpl && tpl.content) {
                    \$btn.replaceWith(tpl.content.cloneNode(true));
                } else {
                    \$btn.removeClass('btn-outline-primary').addClass('btn-outline-secondary');
                }
            } else {
                window.sprintNotify((resp && resp.message) || {$msgFailed}, 'error');
                \$btn.prop('disabled', false);
            }
        }).fail(function() {
            \$btn.prop('disabled', false);
        });
    }
    jQuery(document).on('click', '.sprint-plan-request-btn', function() {
        var \$btn   = jQuery(this);
        var itemId = parseInt(\$btn.data('item-id'), 10) || 0;
        if (itemId <= 0) { return; }
        if (typeof window.sprintRequestReason === 'function') {
            window.sprintRequestReason(function(reason){ send(\$btn, itemId, reason); });
        } else {
            send(\$btn, itemId, '');
        }
    });
})();
</script>
HTML;
    }

    /**
     * The Definition of Ready confirmation of a single assignment, shared by
     * the backlog row and the planning line on a linked item. Prints the
     * dialog (nothing when no Definition of Ready is configured) and defines
     * window.sprintDorConfirm(callback, itemName): every check is mandatory,
     * the callback gets the confirmed checks, or null without a Definition of
     * Ready.
     */
    private static function renderDorDialog(): void
    {
        $lblClose = __s('Close');
        $dor      = Config::getDefinitionReady();
        if ($dor) {
            echo "<div class='modal fade' id='sprint-dor-modal' tabindex='-1' aria-hidden='true' aria-labelledby='sprint-dor-title'>";
            echo "<div class='modal-dialog modal-dialog-centered'><div class='modal-content'>";
            echo "<div class='modal-header'><h5 class='modal-title' id='sprint-dor-title'><i class='fas fa-clipboard-check me-1' aria-hidden='true'></i> "
                . __s('Definition of Ready', 'sprint') . "<span class='sprint-dor-itemname'></span></h5>"
                . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='{$lblClose}'></button></div>";
            echo "<div class='modal-body'><p class='text-muted sprint-small'>" . __s('Confirm the checks that hold for this item.', 'sprint') . "</p>";
            foreach ($dor as $check) {
                echo "<label class='form-check d-block'><input class='form-check-input sprint-dor-single-check' type='checkbox' value='"
                    . htmlescape($check) . "'><span class='form-check-label'>" . htmlescape($check) . "</span></label>";
            }
            echo "</div>";
            echo "<div class='modal-footer'>";
            echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . __s('Cancel') . "</button>";
            echo "<button type='button' class='btn btn-success sprint-dor-go' disabled><i class='fas fa-check me-1' aria-hidden='true'></i>"
                . __s('Assign', 'sprint') . "</button>";
            echo "</div>";
            echo "</div></div></div>";
        }

        echo <<<HTML
<script>
(function(){
    // One copy, on <body>: the tab clips modals and a GLPI ajax-tab reload
    // re-echoes the dialog, so the stale copies go first.
    var all = document.querySelectorAll('#sprint-dor-modal');
    for (var i = 0; i < all.length - 1; i++) { all[i].parentNode.removeChild(all[i]); }
    var m = all[all.length - 1];
    if (m && m.parentNode !== document.body) { document.body.appendChild(m); }
    if (typeof jQuery === 'undefined' || window.__sprintDorBound) { return; }
    window.__sprintDorBound = true;

    function dorModal() { return jQuery(document.getElementById('sprint-dor-modal')); }

    window.sprintDorConfirm = function(callback, itemName) {
        var \$dorModal = dorModal();
        if (!\$dorModal.length || !window.bootstrap || !window.bootstrap.Modal) { callback(null); return; }
        \$dorModal.find('.sprint-dor-itemname').text(itemName ? ': ' + itemName : '');
        \$dorModal.find('.sprint-dor-single-check').prop('checked', false);
        \$dorModal.find('.sprint-dor-go').prop('disabled', true);
        \$dorModal.data('cb', callback);
        window.bootstrap.Modal.getOrCreateInstance(\$dorModal[0]).show();
    };

    jQuery(document).on('change', '.sprint-dor-single-check', function(){
        var \$dorModal = dorModal();
        \$dorModal.find('.sprint-dor-go')
            .prop('disabled', \$dorModal.find('.sprint-dor-single-check:not(:checked)').length > 0);
    });

    jQuery(document).on('click', '.sprint-dor-go', function(){
        var \$dorModal = dorModal();
        var cb = \$dorModal.data('cb');
        \$dorModal.removeData('cb');
        var checks = \$dorModal.find('.sprint-dor-single-check:checked')
            .map(function(){ return this.value; }).get();
        window.bootstrap.Modal.getOrCreateInstance(\$dorModal[0]).hide();
        if (typeof cb === 'function') { cb(checks); }
    });
})();
</script>
HTML;
    }

    /** True when the linked GLPI item is in a real sprint (sprints_id > 0). */
    public static function isLinkedItemInAnySprint(string $itemtype, int $itemId): bool
    {
        if ($itemtype === '' || $itemId <= 0) {
            return false;
        }
        return countElementsInTable(
            SprintItem::getTable(),
            [
                'itemtype' => $itemtype,
                'items_id' => $itemId,
                ['NOT' => ['plugin_sprint_sprints_id' => 0]],
            ]
        ) > 0;
    }

    /** Labels for the linked-item type icons, shared by page and row fragments. */
    public static function getTypeLabels(): array
    {
        return [
            ''            => __('Manual', 'sprint'),
            'Ticket'      => __('Ticket'),
            'Change'      => __('Change'),
            'Problem'     => __('Problem'),
            'ProjectTask' => __('Project task'),
        ];
    }

    /**
     * Assign-permission flags for a backlog row, mirroring
     * ajax/assigntosprint.php: fastlane items are assignable by anyone;
     * normal items only by the proposed sprint's Scrum Master. Others get a
     * request button instead (pending_assign marks an open request).
     * Caches are per-request so rendering many rows stays cheap.
     *
     * @return array{can_assign: bool, pending_assign: bool, pending_sprint_id: int}
     */
    public static function computeRowFlags(array $row): array
    {
        static $masterCache  = [];
        static $pendingItems = null;

        if ($pendingItems === null) {
            $pendingItems = [];
            if (SprintRequest::ensureTable()) {
                foreach ((new SprintRequest())->find([
                    'request_type' => SprintRequest::TYPE_ASSIGN,
                    'status'       => SprintRequest::STATUS_PENDING,
                ]) as $req) {
                    $pendingItems[(int)$req['plugin_sprint_sprintitems_id']] = (int)$req['plugin_sprint_sprints_id'];
                }
            }
        }

        $proposedId = (int)($row['proposed_sprints_id'] ?? 0);
        $canAssign  = false;
        if ($proposedId > 0) {
            if ((int)($row['is_fastlane'] ?? 0) === 1) {
                $canAssign = true;
            } else {
                if (!isset($masterCache[$proposedId])) {
                    $masterCache[$proposedId] = SprintItem::currentUserIsScrumMasterOf($proposedId);
                }
                $canAssign = $masterCache[$proposedId];
            }
        }

        return [
            'can_assign'        => $canAssign,
            'pending_assign'    => isset($pendingItems[(int)$row['id']]),
            // Sprint the open request asks for (may differ from the proposal).
            'pending_sprint_id' => (int)($pendingItems[(int)$row['id']] ?? 0),
        ];
    }

    /**
     * Which planning fields of a backlog row are still empty: 'owner',
     * 'estimate' and/or 'sprint' (the proposed sprint). Empty = planning
     * complete — administrative completeness only; the Definition of Ready
     * and the capacity check follow on assignment.
     *
     * @return list<string>
     */
    public static function planningGaps(array $row): array
    {
        $gaps = [];
        if ((int)($row['users_id'] ?? 0) <= 0) {
            $gaps[] = 'owner';
        }
        if ((float)($row['capacity'] ?? 0) <= 0) {
            $gaps[] = 'estimate';
        }
        if ((int)($row['proposed_sprints_id'] ?? 0) <= 0) {
            $gaps[] = 'sprint';
        }
        return $gaps;
    }

    /** Translated labels for the planningGaps() keys. */
    public static function planningGapLabels(): array
    {
        return [
            'owner'    => __('owner', 'sprint'),
            'estimate' => __('estimate', 'sprint'),
            'sprint'   => __('proposed sprint', 'sprint'),
        ];
    }

    /**
     * "Planning complete" badge, or what is still missing when the planning
     * was started but is incomplete. Nothing for an untouched row (the aging
     * badge and the empty sprint column already say it is unplanned).
     */
    public static function renderPlanningBadge(array $row): string
    {
        $gaps = self::planningGaps($row);
        if (!$gaps) {
            return " <span class='sprint-ready-badge' title='"
                . __s('Owner, estimate and proposed sprint are set. The Definition of Ready and the capacity check follow when the item is assigned to the sprint.', 'sprint') . "'>"
                . "<i class='fas fa-check' aria-hidden='true'></i> " . __s('Planning complete', 'sprint') . "</span>";
        }
        if (count($gaps) === 3) {
            return '';
        }
        $labels  = self::planningGapLabels();
        $missing = implode(', ', array_map(static fn($g) => $labels[$g], $gaps));
        return " <span class='sprint-plan-missing-badge' title='"
            . __s('Planning is incomplete: fill in the missing fields in the edit dialog.', 'sprint') . "'>"
            . "<i class='fas fa-circle-exclamation' aria-hidden='true'></i> "
            . htmlescape(sprintf(__('Missing: %s', 'sprint'), $missing)) . "</span>";
    }

    /** Personal backlog view of the current user: 'queue' (default) or 'planning'. */
    public static function viewMode(): string
    {
        return UserPref::get(self::VIEW_PREF, 'queue') === 'planning' ? 'planning' : 'queue';
    }

    /** Give the first <select>/<input> of a rendered helper an id, for <label for>. */
    private static function withId(string $html, string $id): string
    {
        return (string)preg_replace('/<(select|input)\b(?![^>]*\sid=)/', "<$1 id='" . htmlescape($id) . "'", $html, 1);
    }

    public static function showBacklog(): void
    {
        $canedit = Session::haveRight(SprintItem::$rightname, UPDATE)
            || Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS);

        $typeLabels = self::getTypeLabels();

        // Manual drag order wins; un-ordered items (sort_order 0) fall back to
        // priority/date so existing backlogs look unchanged until reordered.
        $orderBy = ['sort_order ASC', 'priority DESC', 'date_creation DESC'];
        $item    = new SprintItem();
        $blocked = $item->find(['plugin_sprint_sprints_id' => 0, 'is_blocked' => 1], $orderBy);
        $parked  = $item->find(['plugin_sprint_sprints_id' => 0, 'is_blocked' => 0, 'is_parked' => 1], $orderBy);
        $items   = $item->find(['plugin_sprint_sprints_id' => 0, 'is_blocked' => 0, 'is_parked' => 0], $orderBy);

        $allRows  = array_merge($blocked, $parked, $items);
        $allIds   = array_map(fn($r) => (int)$r['id'], $allRows);
        $tagsById = SprintItem::getTagsForItems($allIds);

        // Resolve proposed-sprint names once for the read-only sprint column.
        $sprintNames = [];
        foreach ($allRows as $r) {
            $sid = (int)($r['proposed_sprints_id'] ?? 0);
            if ($sid > 0 && !isset($sprintNames[$sid])) {
                $sprint = new Sprint();
                $sprintNames[$sid] = $sprint->getFromDB($sid)
                    ? (string)$sprint->fields['name']
                    : ('#' . $sid);
            }
        }

        // Personal view: "Work queue" puts the daily work right under the
        // title and filters; "Planning" adds the approval requests, the
        // category forecast and the inflow/outflow chart on top. Saved per
        // user (UserPref), switched client-side without a reload.
        $view     = self::viewMode();
        $requests = SprintRequest::getPendingForCurrentMaster();

        echo "<div class='center sprint-backlog-page' data-view='" . htmlescape($view) . "'>";
        echo "<div class='sprint-backlog-titlebar'>";
        echo "<h2 class='mb-0'><i class='" . self::getIcon() . "' aria-hidden='true'></i> " . self::getTypeName(2) . "</h2>";
        echo "<div class='btn-group btn-group-sm sprint-backlog-viewmode' role='group' aria-label='" . __s('Backlog view', 'sprint') . "'>";
        foreach ([
            'queue'    => ['fas fa-list-check', __('Work queue', 'sprint'), __('The backlog items first — forecasts and requests stay out of the way', 'sprint')],
            'planning' => ['fas fa-chart-simple', __('Planning', 'sprint'), __('Approval requests, capacity forecast and inflow/outflow above the backlog items', 'sprint')],
        ] as $mode => [$icon, $label, $tip]) {
            echo "<input type='radio' class='btn-check' name='sprint-backlog-viewmode' id='sprint-backlog-viewmode-{$mode}' value='{$mode}'"
                . ($view === $mode ? ' checked' : '') . ">";
            echo "<label class='btn btn-outline-secondary' for='sprint-backlog-viewmode-{$mode}' title='" . htmlescape($tip) . "'>"
                . "<i class='{$icon} me-1' aria-hidden='true'></i>" . htmlescape($label)
                . ($mode === 'planning' && count($requests) > 0
                    ? " <span class='sprint-chip sprint-chip-warning ms-1'>" . count($requests) . "</span>"
                    : '')
                . "</label>";
        }
        echo "</div>";
        echo "</div>";
        echo "<p class='text-muted'>"
            . __('Items waiting to be planned. Edit an item to set its owner, estimate and proposed sprint. The item stays here until it is assigned to that sprint.', 'sprint')
            . "</p>";

        // Feedback region for every backlog action (and the toast fallback).
        echo "<div class='sprint-backlog-live' role='status' aria-live='polite'></div>";

        if (count($requests) > 0) {
            echo "<div class='sprint-backlog-requests-hint sprint-zone-queue alert alert-warning py-2 sprint-small text-start'"
                . ($view === 'queue' ? '' : " hidden") . ">"
                . "<i class='fas fa-bell me-1' aria-hidden='true'></i>"
                . htmlescape(sprintf(
                    _n('%d request is waiting for your approval.', '%d requests are waiting for your approval.', count($requests), 'sprint'),
                    count($requests)
                ))
                . " <button type='button' class='btn btn-link btn-sm p-0 align-baseline sprint-backlog-goto-planning'>"
                . __s('Show in Planning', 'sprint') . "</button></div>";
        }

        echo "<div class='sprint-backlog-zone-planning'" . ($view === 'planning' ? '' : " hidden") . ">";
        // Pending assign requests for the sprints this user is Scrum Master of.
        SprintRequest::renderPanel();
        // Prognosis views: category × sprint matrix + weekly inflow/outflow.
        // The matrix is only fetched once the Planning view is opened.
        self::renderCategoryDashboard($view !== 'planning');
        self::renderFlowCard();
        echo "</div>";

        // Complete items (owner + sprint + capacity, same rule as the per-row
        // "Planning complete" badge) that THIS user may actually assign — the
        // bulk button only covers those, split per target sprint so a single
        // sprint can be kicked off while future ones stay queued. The names
        // travel along so the dialog lists what is about to move.
        $assignableReady = 0;
        $readyBySprint   = [];
        $readyItems      = [];
        foreach (array_merge($blocked, $items) as $r) {
            if (self::planningGaps($r)) {
                continue;
            }
            $flags = self::computeRowFlags($r);
            if (!$flags['can_assign']) {
                continue;
            }
            $assignableReady++;
            $sid = (int)$r['proposed_sprints_id'];
            $readyBySprint[$sid] = ($readyBySprint[$sid] ?? 0) + 1;
            $readyItems[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'sprint' => $sid];
        }

        echo "<div class='sprint-backlog-itemsbar'>";
        echo "<h3 class='sprint-backlog-itemsbar-title'>"
            . "<i class='fas fa-list' aria-hidden='true'></i> " . __('Backlog items', 'sprint')
            . " <span class='badge bg-secondary sprint-backlog-count'>" . count($items) . "</span></h3>";
        if ($canedit && $assignableReady > 0) {
            echo "<button type='button' class='btn btn-sm btn-success sprint-backlog-bulk-assign'>"
                . "<i class='fas fa-layer-group me-1' aria-hidden='true'></i>"
                . sprintf(__('Assign all planned (%d)…', 'sprint'), $assignableReady)
                . "</button>";
        }
        echo "</div>";

        // Owners present in the backlog, for the owner filter.
        $owners = [];
        foreach ($allRows as $r) {
            $uid = (int)($r['users_id'] ?? 0);
            if ($uid > 0 && !isset($owners[$uid])) {
                $owners[$uid] = SprintCache::userName($uid);
            }
        }
        asort($owners);

        // The filter bar sits above every list (blocked, categories, parked)
        // so one filter, one result count and one reset cover them all.
        self::renderFilterBar($typeLabels, $owners, $sprintNames, $canedit);

        self::renderBlockedSection($blocked, $canedit, $typeLabels, $tagsById, $sprintNames);

        // One collapsible section per admin-defined category ("mini kanban"),
        // plus a catch-all for uncategorized items. Empty categories still
        // render so rows can be dragged into them.
        $categories = SprintCategory::getAll();
        $byCat      = [];
        foreach ($items as $row) {
            $cid = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
            if ($cid > 0 && !isset($categories[$cid])) {
                $cid = 0; // deactivated category → show with the uncategorized items
            }
            $byCat[$cid][] = $row;
        }

        foreach ($categories as $cid => $cat) {
            self::renderCategorySection(
                (int)$cid,
                (string)$cat['name'],
                (string)$cat['color'],
                $byCat[(int)$cid] ?? [],
                $canedit,
                $typeLabels,
                $tagsById,
                $sprintNames,
                (int)($cat['level'] ?? 0)
            );
        }
        self::renderCategorySection(
            0,
            __('No category', 'sprint'),
            SprintChart::NEUTRAL,
            $byCat[0] ?? [],
            $canedit,
            $typeLabels,
            $tagsById,
            $sprintNames
        );

        self::renderParkedSection($parked, $canedit, $typeLabels, $tagsById, $sprintNames);

        // Empty states: an empty backlog and "nothing matches the filters"
        // are different situations with a different next step.
        echo "<div class='sprint-backlog-empty-state sprint-backlog-empty-none'" . (count($allRows) > 0 ? ' hidden' : '') . ">"
            . "<i class='fas fa-inbox fa-2x text-muted mb-2' aria-hidden='true'></i>"
            . "<div class='fw-bold'>" . __s('No items on the backlog', 'sprint') . "</div>"
            . "<div class='text-muted sprint-small'>"
            . __s('Add a ticket, change, problem or project task via its "Sprints" tab ("Add to backlog").', 'sprint') . "</div>"
            . "</div>";
        echo "<div class='sprint-backlog-empty-state sprint-backlog-empty-filtered' hidden>"
            . "<i class='fas fa-filter-circle-xmark fa-2x text-muted mb-2' aria-hidden='true'></i>"
            . "<div class='fw-bold'>" . __s('No results due to the filters', 'sprint') . "</div>"
            . "<div class='text-muted sprint-small mb-2'>"
            . __s('There are backlog items, but none match the active filters.', 'sprint') . "</div>"
            . "<button type='button' class='btn btn-sm btn-outline-secondary sprint-backlog-filter-reset'>"
            . SprintIcon::html(SprintIcon::REJECT, 'me-1') . "" . __s('Reset filters', 'sprint') . "</button>"
            . "</div>";

        echo "</div>";

        // Mounts the modal + JS for the quick-edit-linked-item buttons rendered
        // by SprintItem::getLinkedItemDisplay(); without it they're no-ops.
        SprintItem::renderLinkedQuickEditUI();

        self::renderFeedbackScript($view);
        self::renderInlineEditScript($readyBySprint, $sprintNames, $readyItems);
        self::renderDependencyUI();
        if ($canedit) {
            self::renderEditModal();
        }
    }

    /**
     * Page-wide backlog plumbing, emitted before the other backlog scripts:
     * - window.sprintBacklogNotify(type, msg): one feedback pattern for every
     *   backlog action — GLPI's toast when available, otherwise the inline
     *   status region under the title (never alert()).
     * - window.sprintBacklogReload(msg): reload that keeps filters, scroll
     *   position and a pending confirmation message. The same state is also
     *   saved on pagehide, so the form POSTs (delete, …) that come back via
     *   Html::back() land where the user was. Kept per user and only for a
     *   couple of minutes, so another user on the same browser or a later
     *   visit starts clean.
     * - the "Work queue / Planning" switch, saved per user (UserPref).
     */
    private static function renderFeedbackScript(string $view): void
    {
        $prefUrl   = Plugin::getWebDir('sprint') . '/ajax/userpref.php';
        $prefName  = self::VIEW_PREF;
        $userId    = (int)Session::getLoginUserID();
        $lblClose  = addslashes(__('Close'));
        $viewJs    = $view === 'planning' ? 'planning' : 'queue';
        // Texts js/sprint.js needs on this page only.
        $i18n      = json_encode(
            ['categoryAllPlanned' => __('Everything in this category is planned', 'sprint')],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );

        echo <<<HTML
<script>
window.sprintI18n = Object.assign(window.sprintI18n || {}, {$i18n});
(function(){
    if (typeof jQuery === 'undefined') { return; }
    var stateKey = 'sprint.backlog.state.{$userId}';
    var STATE_TTL = 120000;

    window.sprintBacklogNotify = function(type, msg) {
        if (!msg) { return; }
        var toast = type === 'error' ? window.glpi_toast_error
            : (type === 'warning' ? (window.glpi_toast_warning || window.glpi_toast_info) : window.glpi_toast_info);
        if (typeof toast === 'function') { toast(msg); return; }
        var \$live = jQuery('.sprint-backlog-live').first();
        if (!\$live.length) { return; }
        var cls = type === 'error' ? 'alert-danger' : (type === 'warning' ? 'alert-warning' : 'alert-success');
        var \$msg = jQuery('<div class="alert py-2 sprint-small d-flex align-items-start gap-2"></div>').addClass(cls)
            .append(jQuery('<span class="flex-grow-1 sprint-pre-line"></span>').text(msg))
            .append(jQuery('<button type="button" class="btn-close"></button>').attr('aria-label', "{$lblClose}")
                .on('click', function(){ \$msg.remove(); }));
        \$live.empty().append(\$msg);
        // Errors stay until dismissed; confirmations fade on their own.
        if (type !== 'error') { setTimeout(function(){ \$msg.fadeOut(300, function(){ \$msg.remove(); }); }, 8000); }
    };

    function readFilters() {
        var out = {};
        jQuery('.sprint-backlog-filter').first().find('.sf-text, .sf-type, .sf-owner, .sf-sprint, .sf-tag').each(function(){
            var key = (this.className.match(/\\bsf-[a-z]+\\b/) || [''])[0];
            if (key && this.value) { out[key] = this.value; }
        });
        return out;
    }
    function saveState(msg) {
        try {
            sessionStorage.setItem(stateKey, JSON.stringify({
                ts: Date.now(), path: window.location.pathname,
                filters: readFilters(), scroll: window.scrollY || 0, msg: msg || ''
            }));
        } catch (e) {}
    }
    window.sprintBacklogReload = function(msg) {
        saveState(msg);
        // In a GLPI tab only the tab reloads, on the backlog page the page does.
        if (window.sprintUi && typeof window.sprintUi.refresh === 'function') { window.sprintUi.refresh(); }
        else { window.location.reload(); }
    };
    window.addEventListener('pagehide', function(){
        var prev = null;
        try { prev = JSON.parse(sessionStorage.getItem(stateKey) || 'null'); } catch (e) {}
        // A reload that just queued a message keeps it.
        saveState(prev && prev.msg && Date.now() - prev.ts < 2000 ? prev.msg : '');
    });

    function restoreState() {
        var st = null;
        try {
            st = JSON.parse(sessionStorage.getItem(stateKey) || 'null');
            sessionStorage.removeItem(stateKey);
        } catch (e) { return; }
        if (!st || st.path !== window.location.pathname || Date.now() - (st.ts || 0) > STATE_TTL) { return; }
        var \$bar = jQuery('.sprint-backlog-filter').first();
        var touched = null;
        jQuery.each(st.filters || {}, function(key, value){
            var el = \$bar.find('.' + key)[0];
            if (!el) { return; }
            if (el.tagName === 'SELECT' && !jQuery(el).find('option').filter(function(){ return this.value === value; }).length) { return; }
            el.value = value;
            touched = el;
        });
        if (touched) {
            touched.dispatchEvent(new Event(touched.classList.contains('sf-text') ? 'input' : 'change', { bubbles: true }));
        }
        if (st.scroll > 0) { window.scrollTo(0, st.scroll); }
        if (st.msg) { window.sprintBacklogNotify('success', st.msg); }
    }
    // After the other ready handlers (sections, sort) have run.
    jQuery(function(){ setTimeout(restoreState, 0); });

    // Work queue / Planning switch.
    var currentView = '{$viewJs}';
    function applyView(view) {
        currentView = view === 'planning' ? 'planning' : 'queue';
        jQuery('.sprint-backlog-page').attr('data-view', currentView);
        jQuery('.sprint-backlog-zone-planning').prop('hidden', currentView !== 'planning');
        jQuery('.sprint-zone-queue').prop('hidden', currentView !== 'queue');
        jQuery('#sprint-backlog-viewmode-' + currentView).prop('checked', true);
        var \$dash = jQuery('.sprint-backlog-dash-body');
        if (currentView === 'planning' && \$dash.attr('data-stale') === '1' && window.sprintBacklogReloadMatrix) {
            window.sprintBacklogReloadMatrix();
        }
    }
    function saveView(view) {
        window.sprintUi.post("{$prefUrl}", { name: '{$prefName}', value: view }, { silent: true });
    }
    jQuery(document).on('change', 'input[name="sprint-backlog-viewmode"]', function(){
        applyView(this.value);
        saveView(currentView);
    });
    jQuery(document).on('click', '.sprint-backlog-goto-planning', function(){
        applyView('planning');
        saveView('planning');
        var zone = jQuery('.sprint-backlog-zone-planning')[0];
        if (zone) { zone.scrollIntoView({ block: 'start' }); }
        jQuery('#sprint-backlog-viewmode-planning').trigger('focus');
    });
})();
</script>
HTML;
    }

    /**
     * Compact backlog table header. Type/fastlane/blocked collapsed into the
     * name cell as icons; owner/sprint/capacity are read-only — editing
     * happens in the per-row modal.
     */
    private static function renderTableHeaders(bool $canedit, bool $reorderable): void
    {
        echo "<tr class='tab_bg_2'>";
        if ($reorderable && $canedit) {
            echo "<th class='sprint-backlog-col-grip' title='" . __s('Drag to reorder', 'sprint') . "'><span class='visually-hidden'>" . __s('Drag to reorder', 'sprint') . "</span></th>";
        }
        if ($reorderable) {
            echo "<th class='sprint-backlog-col-rank" . ($canedit ? ' is-editable' : '') . "' title='" . __s('Priority rank — the higher an item sits, the sooner it should be picked up', 'sprint') . "'>#</th>";
        }
        echo "<th>" . __('Name') . "</th>";
        echo "<th>" . __('Linked item', 'sprint') . "</th>";
        echo "<th><i class='fas fa-user me-1' aria-hidden='true'></i>" . __('Owner', 'sprint') . "</th>";
        echo "<th><i class='fas fa-flag-checkered me-1' aria-hidden='true'></i>" . __('Proposed sprint', 'sprint') . "</th>";
        echo "<th title='" . __('Estimated capacity (informational on backlog, enforced once the item joins a sprint)', 'sprint') . "'>"
            . __('Est. capacity', 'sprint') . " %</th>";
        if (SprintCustomer::canUseCredits()) {
            echo "<th><i class='fas fa-coins me-1' aria-hidden='true'></i>"
                . __('Customer / credits', 'sprint') . "</th>";
        }
        if ($canedit) {
            echo "<th class='sprint-backlog-col-actions'>" . __('Actions') . "</th>";
        }
        echo "</tr>";
    }

    /** Number of columns a backlog table renders, for empty-row colspans. */
    private static function columnCount(bool $canedit, bool $reorderable): int
    {
        return 5
            + (($reorderable && $canedit) ? 1 : 0)
            + ($reorderable ? 1 : 0)
            + (SprintCustomer::canUseCredits() ? 1 : 0)
            + ($canedit ? 1 : 0);
    }

    /** Collapsible category section with count, capacity subtotal and ranks. */
    private static function renderCategorySection(int $catId, string $name, string $color, array $rows, bool $canedit, array $typeLabels, array $tagsById, array $sprintNames, int $level = 0): void
    {
        $count    = count($rows);
        $capSum   = 0.0;
        foreach ($rows as $r) {
            $capSum += (float)($r['capacity'] ?? 0);
        }
        $colorEsc = htmlescape($color);
        $capLabel = SprintMember::formatCapacity($capSum);
        // Subcategory sections nest visually under their parent section.
        $subClass = $level > 0 ? ' is-sub' : '';
        $folder   = $level > 0 ? 'fa-folder-open' : 'fa-folder';

        $parentId = $level > 0 ? SprintCategory::getParentFor($catId) : 0;

        // The category colour drives the left border, the head tint and the folder icon.
        echo "<div class='sprint-backlog-section sprint-backlog-cat-section{$subClass}' data-category-id='{$catId}' "
            . "data-parent-category-id='{$parentId}' style='--sprint-cat-color:{$colorEsc};'>";
        echo "<div class='sprint-backlog-section-head sprint-backlog-cat-header' role='button' tabindex='0' aria-expanded='true' "
            . "aria-controls='sprint-backlog-cat-body-{$catId}'>";
        echo "<i class='fas fa-chevron-down sprint-backlog-chevron sprint-backlog-cat-chevron' aria-hidden='true'></i>";
        if ($level > 0) {
            echo "<i class='fas fa-turn-up fa-rotate-90 text-muted sprint-icon-sm' aria-hidden='true'></i>";
        }
        echo "<i class='fas {$folder} sprint-backlog-cat-icon' aria-hidden='true'></i>";
        echo "<span>" . htmlescape($name) . "</span>";
        echo "<span class='badge bg-secondary sprint-backlog-cat-count'>{$count}</span>";
        echo "<span class='badge bg-secondary-lt sprint-backlog-cat-subcount' style='display:none;' title='"
            . __s('Items in the subcategories', 'sprint') . "'></span>";
        echo "<span class='sprint-flex-fill'></span>";
        echo "<span class='text-muted sprint-small fw-normal' title='"
            . __s('Total estimated capacity on the backlog in this category', 'sprint') . "'>"
            . "<i class='fas fa-gauge-high me-1' aria-hidden='true'></i>"
            . "<span class='sprint-backlog-cat-capsum'>{$capLabel}%</span></span>";
        echo "</div>";

        echo "<div class='sprint-backlog-cat-body' id='sprint-backlog-cat-body-{$catId}'>";
        echo "<table class='tab_cadre_fixe sprint-themed sprint-backlog-table m-0'>";
        self::renderTableHeaders($canedit, true);
        $cols = self::columnCount($canedit, true);
        echo "<tr class='sprint-backlog-empty-row'" . ($count > 0 ? " style='display:none;'" : '') . ">"
            . "<td colspan='{$cols}' class='center text-muted'>"
            . "<span class='sprint-backlog-empty-default'>"
            . __('No items — drag a row here or pick this category in the edit dialog.', 'sprint') . "</span>"
            // Shown instead once the last row of the section was assigned (.is-all-planned).
            . "<span class='sprint-backlog-all-planned'><i class='fas fa-circle-check me-1' aria-hidden='true'></i>"
            . __('Everything in this category is planned', 'sprint') . "</span>"
            . "</td></tr>";
        $rank = 1;
        foreach ($rows as $row) {
            self::renderItemRow($row, $canedit, $typeLabels, $tagsById, true, $sprintNames, $rank++, false);
        }
        echo "</table></div></div>";
    }

    /** Parked (non-active) tier: long-term work outside planning and totals. */
    private static function renderParkedSection(array $parkedItems, bool $canedit, array $typeLabels, array $tagsById, array $sprintNames): void
    {
        $count = count($parkedItems);

        echo "<div class='sprint-backlog-section sprint-backlog-parked'>";
        echo "<div class='sprint-backlog-section-head sprint-backlog-parked-header' role='button' tabindex='0' aria-expanded='true' aria-controls='sprint-backlog-parked-body'>";
        echo "<i class='fas fa-chevron-down sprint-backlog-chevron sprint-backlog-parked-chevron' aria-hidden='true'></i>";
        echo "<i class='fas fa-box-archive' aria-hidden='true'></i>";
        echo "<span>" . __s('Parked (long term)', 'sprint') . "</span>";
        echo "<span class='badge bg-secondary'>" . $count . "</span>";
        echo "<span class='sprint-flex-fill'></span>";
        echo "<span class='text-muted sprint-small fw-normal'>"
            . __('Low-priority / long-term work, outside the active planning and capacity totals.', 'sprint') . "</span>";
        echo "</div>";

        echo "<div class='sprint-backlog-parked-body' id='sprint-backlog-parked-body'>";
        if ($count === 0) {
            echo "<div class='center text-muted sprint-backlog-pad'>"
                . __('No parked items.', 'sprint') . "</div>";
        } else {
            echo "<table class='tab_cadre_fixe sprint-themed m-0'>";
            self::renderTableHeaders($canedit, false);
            foreach ($parkedItems as $row) {
                self::renderItemRow($row, $canedit, $typeLabels, $tagsById, false, $sprintNames);
            }
            echo "</table>";
        }
        echo "</div></div>";

        echo "<script>
        (function() {
            var key = 'sprint.backlog.parked.collapsed';
            $(function() {
                var \$wrap = $('.sprint-backlog-parked').last();
                if (!\$wrap.length) return;
                var \$body = \$wrap.find('.sprint-backlog-parked-body');
                var \$chev = \$wrap.find('.sprint-backlog-parked-chevron');
                var \$head = \$wrap.find('.sprint-backlog-parked-header');
                var stored = null;
                try { stored = localStorage.getItem(key); } catch (e) {}
                if (stored !== '0') {
                    \$body.hide();
                    \$chev.css('transform', 'rotate(-90deg)');
                    \$head.attr('aria-expanded', 'false');
                }
                \$head.on('click', function() {
                    var collapsed = \$body.is(':visible');
                    \$body.slideToggle(120);
                    \$chev.css('transform', collapsed ? 'rotate(-90deg)' : 'rotate(0deg)');
                    \$head.attr('aria-expanded', collapsed ? 'false' : 'true');
                    try { localStorage.setItem(key, collapsed ? '1' : '0'); } catch (e) {}
                }).on('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); \$head.trigger('click'); }
                });
            });
        })();
        </script>";
    }

    /**
     * Per-row edit modal: the backlog rows are read-only and compact; every
     * editable field (name, owner, estimated capacity, proposed sprint,
     * fastlane/blocked flags) lives here. Saving goes through
     * ajax/updateitemquick.php and swaps the row in place so derived bits
     * (planning badge, capacity chip, state) stay consistent. The dialog
     * says where the item stands (backlog / waiting for approval) and keeps
     * "propose a sprint" apart from the real "assign to sprint" step.
     */
    private static function renderEditModal(): void
    {
        $endpoint    = Plugin::getWebDir('sprint') . '/ajax/updateitemquick.php';
        $capacityUrl = Plugin::getWebDir('sprint') . '/ajax/backlogcapacity.php';
        $rowUrl      = Plugin::getWebDir('sprint') . '/ajax/backlogrow.php';

        $lblTitle    = __s('Edit backlog item', 'sprint');
        $lblName     = __s('Name');
        $lblOwner    = __s('Owner', 'sprint');
        $lblCapacity = __s('Est. capacity', 'sprint');
        $lblSprint   = __s('Proposed sprint', 'sprint');
        $lblFastlane = __s('Fastlane', 'sprint');
        $lblBlocked  = __s('Blocked', 'sprint');
        $lblAdhoc    = __s('Adhoc', 'sprint');
        $adhocHint   = __s('Marks the item as post-kick-off work. The flag travels with it into the sprint.', 'sprint');
        $lblNameFollows = addslashes(__('The name follows the linked item and cannot be edited here.', 'sprint'));
        $lblCancel   = __s('Cancel');
        $lblSave     = __s('Save');
        $lblDelete   = __s('Delete');
        $lblDeps     = __s('Add dependency', 'sprint');
        $lblAssign   = __s('Save and assign to sprint', 'sprint');
        $assignHint  = __s('Saves the item, then assigns it to the proposed sprint (Scrum Master, or anyone for fastlane items). Others send an assignment request instead.', 'sprint');
        $sprintHint  = __s('A proposal only: the item stays on the backlog until the Scrum Master assigns it to this sprint.', 'sprint');

        // GLPI dropdown ids are "dropdown_<name><rand>"; the labels point at them.
        $idOwner    = 'dropdown__backlog_modal_users_id424242';
        $idSprint   = 'dropdown__backlog_modal_sprint_id424243';
        $idCapacity = 'sprint-be-capacity';

        echo "<div class='modal fade' id='sprint-backlog-edit-modal' tabindex='-1' aria-hidden='true' aria-labelledby='sprint-backlog-edit-title'>";
        echo "<div class='modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down'>";
        echo "<div class='modal-content'>";
        echo "<div class='modal-header'>";
        echo "<h5 class='modal-title' id='sprint-backlog-edit-title'>" . SprintIcon::html(SprintIcon::EDIT, 'me-1') . " {$lblTitle}: <span class='sprint-be-title'></span></h5>";
        echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='" . __s('Close') . "'></button>";
        echo "</div>";
        echo "<div class='modal-body'>";
        // Where the item stands right now: backlog, proposed, or waiting for approval.
        echo "<div class='sprint-be-state sprint-small mb-2'></div>";
        echo "<div class='alert alert-danger sprint-be-error sprint-pre-line' role='alert' style='display:none;'></div>";
        echo "<input type='hidden' name='id' value=''>";

        echo "<div class='mb-3'><label class='form-label' for='sprint-be-name'>{$lblName}</label>";
        echo "<input type='text' id='sprint-be-name' name='name' class='form-control' value=''></div>";

        echo "<div class='row g-3'>";
        echo "<div class='col-md-6 mb-3'><label class='form-label' for='{$idOwner}'>{$lblOwner}</label>";
        User::dropdown([
            'name'   => '_backlog_modal_users_id',
            'value'  => 0,
            'right'  => 'all',
            'entity' => $_SESSION['glpiactiveentities'] ?? -1,
            'rand'   => 424242,
            'width'  => '100%',
        ]);
        echo "</div>";
        echo "<div class='col-md-6 mb-3'><label class='form-label' for='{$idCapacity}'>{$lblCapacity} %</label>";
        // Any value on the 0.5 grid (12.5%), with the usual presets offered.
        echo SprintMember::capacityInput('_backlog_modal_capacity', '', ['id' => $idCapacity]);
        echo "</div>";
        echo "</div>";

        echo "<div class='row g-3'>";
        echo "<div class='col-md-6 mb-3'><label class='form-label' for='{$idSprint}'>{$lblSprint}</label>";
        Sprint::dropdown([
            'name'  => '_backlog_modal_sprint_id',
            'value' => 0,
            'rand'  => 424243,
            'width' => '100%',
        ]);
        echo "<div class='form-text sprint-small text-muted' id='sprint-be-sprint-hint'>{$sprintHint}</div>";
        echo "</div>";
        echo "<div class='col-md-6 mb-3'><label class='form-label' for='sprint-be-category'>"
            . "<i class='fas fa-folder me-1' aria-hidden='true'></i>" . __s('Category', 'sprint') . "</label>";
        echo "<select class='form-select' id='sprint-be-category' name='_backlog_modal_category'>";
        echo "<option value='0'>-----</option>";
        echo SprintCategory::dropdownOptions();
        echo "</select></div>";
        echo "</div>";

        // Live planning completeness for the values in the dialog.
        echo "<div class='sprint-be-completeness sprint-small mb-3'></div>";

        if (SprintCustomer::canUseCredits()) {
            echo "<div class='row g-3'>";
            echo "<div class='col-md-8 mb-3'><label class='form-label' for='sprint-be-customer'>"
                . "<i class='fas fa-building me-1' aria-hidden='true'></i>" . SprintCustomer::getTypeName(1) . "</label>";
            echo self::withId(SprintItem::customerSelect('_backlog_modal_customer', 0), 'sprint-be-customer');
            echo "</div>";
            echo "<div class='col-md-4 mb-3'><label class='form-label' for='sprint-be-credits'>"
                . "<i class='fas fa-coins me-1' aria-hidden='true'></i>" . __s('Credits', 'sprint') . "</label>";
            echo self::withId(SprintItem::creditsInput('_backlog_modal_credits', 0, 'w-100'), 'sprint-be-credits');
            echo "</div>";
            echo "</div>";
            echo "<div class='row g-3'>";
            echo "<div class='col-md-8 mb-3'><label class='form-label' for='sprint-be-product'>"
                . "<i class='fas fa-tags me-1' aria-hidden='true'></i>" . SprintCreditProduct::getTypeName(1) . "</label>";
            echo self::withId(SprintItem::productSelect('_backlog_modal_product', 0, '_backlog_modal_credits', 'w-100'), 'sprint-be-product');
            echo "<div class='form-text sprint-small text-muted'>"
                . htmlescape(__('Picking a product sets its credits. Choose "Manual credits" to enter a different amount.', 'sprint')) . "</div>";
            echo "</div>";
            echo "</div>";
            SprintItem::creditProductScript();
        }

        // Live capacity preview for the chosen owner in the chosen sprint.
        echo "<div class='sprint-be-cap-preview alert py-2 sprint-small mb-3' role='status' style='display:none;'></div>";

        // The switches wrap on narrow screens instead of overflowing.
        echo "<div class='d-flex flex-wrap gap-3 mb-2 sprint-be-switches'>";
        echo "<div class='form-check form-switch'>";
        echo "<input class='form-check-input' type='checkbox' id='sprint-be-fastlane' name='is_fastlane'>";
        echo "<label class='form-check-label' for='sprint-be-fastlane'><i class='fas fa-bolt sprint-tone-orange' aria-hidden='true'></i> {$lblFastlane}</label>";
        echo "</div>";
        echo "<div class='form-check form-switch'>";
        echo "<input class='form-check-input' type='checkbox' id='sprint-be-blocked' name='is_blocked'>";
        echo "<label class='form-check-label' for='sprint-be-blocked'><i class='fas fa-ban sprint-tone-danger' aria-hidden='true'></i> {$lblBlocked}</label>";
        echo "</div>";
        echo "<div class='form-check form-switch' title='{$adhocHint}'>";
        echo "<input class='form-check-input' type='checkbox' id='sprint-be-adhoc' name='is_adhoc' aria-describedby='sprint-be-adhoc-hint'>";
        echo "<label class='form-check-label' for='sprint-be-adhoc'><i class='fas fa-plus-circle sprint-tone-adhoc' aria-hidden='true'></i> {$lblAdhoc}</label>";
        echo "<span class='visually-hidden' id='sprint-be-adhoc-hint'>{$adhocHint}</span>";
        echo "</div>";
        $parkedHint = __s('Long-term / low-priority: keeps the item out of the active planning sections and capacity totals.', 'sprint');
        echo "<div class='form-check form-switch' title='{$parkedHint}'>";
        echo "<input class='form-check-input' type='checkbox' id='sprint-be-parked' name='is_parked' aria-describedby='sprint-be-parked-hint'>";
        echo "<label class='form-check-label' for='sprint-be-parked'><i class='fas fa-box-archive sprint-tone-muted' aria-hidden='true'></i> " . __s('Parked', 'sprint') . "</label>";
        echo "<span class='visually-hidden' id='sprint-be-parked-hint'>{$parkedHint}</span>";
        echo "</div>";
        echo "</div>";

        // Tags can be assigned while the item is still on the backlog, so
        // filtering and planning don't have to wait for sprint assignment.
        $definedTags = Config::getDefinedTags();
        if (!empty($definedTags)) {
            $tagRequired = Config::isTagRequired();
            echo "<fieldset class='mb-2 sprint-be-tags-block' data-tag-required='" . ($tagRequired ? 1 : 0) . "'><legend class='form-label sprint-legend-plain'>"
                . "<i class='fas fa-tags me-1' aria-hidden='true'></i>" . __('Tags', 'sprint')
                . ($tagRequired ? " <span class='text-danger' title='" . __s('Required', 'sprint') . "'>*<span class='visually-hidden'> " . __s('Required', 'sprint') . "</span></span>" : '')
                . "</legend>";
            echo "<div class='d-flex flex-wrap gap-3'>";
            foreach ($definedTags as $tag) {
                echo "<label class='sprint-be-tag-label sprint-inline-check'>"
                    . "<input type='checkbox' class='sprint-be-tag' value='" . htmlescape($tag) . "'>"
                    . "<span>" . htmlescape($tag) . "</span>"
                    . "</label>";
            }
            echo "</div></fieldset>";
        }

        // Delete asks inline (no browser confirm()): name + effect, then the
        // POST flow of backlog.form.php (purge).
        echo "<div class='alert alert-danger sprint-be-delete-confirm mt-3 mb-0' role='alert' hidden>";
        echo "<form method='post' action='" . self::getFormURL() . "' class='sprint-be-delete-form d-flex flex-wrap align-items-center gap-2'>";
        echo Html::hidden('id', ['value' => 0]);
        echo "<span class='flex-grow-1 sprint-be-delete-text'></span>";
        echo "<button type='button' class='btn btn-sm btn-outline-secondary sprint-be-delete-keep'>" . __s('Keep item', 'sprint') . "</button>";
        echo "<button type='submit' name='purge' value='1' class='btn btn-sm btn-danger'>"
            . SprintIcon::html(SprintIcon::DELETE, 'me-1') . "" . __s('Delete permanently', 'sprint') . "</button>";
        Html::closeForm();
        echo "</div>";

        echo "</div>";
        echo "<div class='modal-footer flex-wrap'>";
        echo "<button type='button' class='btn btn-outline-danger me-auto sprint-be-delete-ask'>"
            . SprintIcon::html(SprintIcon::DELETE, 'me-1') . "{$lblDelete}</button>";
        echo "<button type='button' class='btn btn-outline-secondary sprint-be-deps-open'>"
            . "<i class='fas fa-link me-1' aria-hidden='true'></i>{$lblDeps}</button>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>{$lblCancel}</button>";
        echo "<button type='button' class='btn btn-outline-primary sprint-be-assign' title='{$assignHint}'>"
            . "<i class='fas fa-arrow-right me-1' aria-hidden='true'></i>{$lblAssign}</button>";
        echo "<button type='button' class='btn btn-primary sprint-be-save'><i class='fas fa-save me-1' aria-hidden='true'></i> {$lblSave}</button>";
        echo "</div>";
        echo "</div></div></div>";

        $msgNotMember  = addslashes(__('Owner is not a member of the selected sprint', 'sprint'));
        $msgSaveFailed = addslashes(__('Save failed', 'sprint'));
        $msgNetwork    = addslashes(__('Network error — your changes are still in the dialog, try again.', 'sprint'));
        $lblInactive   = addslashes(__('inactive', 'sprint'));
        $msgTagRequired = addslashes(__('Pick at least one tag — tags are required on every item.', 'sprint'));
        $msgDelete     = addslashes(__('Delete "%s" permanently? It disappears from the backlog. The linked GLPI item is not touched.', 'sprint'));
        $stNotPlanned  = addslashes(__('On the backlog — no sprint proposed yet.', 'sprint'));
        $stProposed    = addslashes(__('On the backlog — proposed for %s. It joins that sprint only once it is assigned.', 'sprint'));
        $stRequested   = addslashes(__('Requested — waiting for Scrum Master: Backlog → %s', 'sprint'));
        $okNotPlanned  = addslashes(__('Saved. The item stays on the backlog (no sprint proposed).', 'sprint'));
        $okProposed    = addslashes(__('Saved. The item stays on the backlog, proposed for %s — not assigned yet.', 'sprint'));
        $lblComplete   = addslashes(__('Planning complete — the Definition of Ready and the capacity check follow on assignment.', 'sprint'));
        $lblMissing    = addslashes(__('Missing: %s', 'sprint'));
        $gapLabels     = json_encode(self::planningGapLabels(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $msgAlreadyReq = addslashes(__('Assignment already requested — waiting for the Scrum Master', 'sprint'));

        echo <<<HTML
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }
    var endpoint    = "{$endpoint}";
    var capacityUrl = "{$capacityUrl}";
    var gapLabels   = {$gapLabels};
    var \$modal, \$opener = null;

    function notify(type, msg) {
        if (window.sprintBacklogNotify) { window.sprintBacklogNotify(type, msg); }
    }
    function fmt(tpl, value) { return String(tpl).replace('%s', value); }
    // Names read from the page are text; the GLPI toast parses HTML.
    function escH(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
        });
    }

    jQuery(function(){
        \$modal = jQuery('#sprint-backlog-edit-modal');
        if (\$modal.length === 0) { return; }
        \$modal = \$modal.detach().appendTo('body');

        // Live preview refresh when owner / sprint / capacity change; the
        // dependency + assign buttons stay greyed out until a sprint is selected.
        \$modal.on('change', "select[name='_backlog_modal_users_id'], select[name='_backlog_modal_sprint_id'], input[name='_backlog_modal_capacity']", function(){
            refreshPreview();
            updateDepsButton();
            updateCompleteness();
        });
        \$modal.on('shown.bs.modal', function(){
            \$modal.find('input[name=name]').trigger('focus');
        });
        // Focus goes back to the button that opened the dialog.
        \$modal.on('hidden.bs.modal', function(){
            if (\$opener && document.body.contains(\$opener[0])) { \$opener.trigger('focus'); }
            \$opener = null;
        });
    });

    function selectedSprint() {
        return parseInt(\$modal.find("select[name='_backlog_modal_sprint_id']").val(), 10) || 0;
    }
    function selectedSprintName() {
        var \$opt = \$modal.find("select[name='_backlog_modal_sprint_id'] option:selected");
        return \$opt.length ? \$opt.text() : '';
    }

    function updateDepsButton() {
        var sprintId = selectedSprint();
        \$modal.find('.sprint-be-deps-open, .sprint-be-assign').prop('disabled', sprintId <= 0);
    }

    // Same rule as Backlog::planningGaps(): owner, estimate, proposed sprint.
    function updateCompleteness() {
        var gaps = [];
        if ((parseInt(\$modal.find("select[name='_backlog_modal_users_id']").val(), 10) || 0) <= 0) { gaps.push(gapLabels.owner); }
        if ((parseFloat(\$modal.find("input[name='_backlog_modal_capacity']").val()) || 0) <= 0) { gaps.push(gapLabels.estimate); }
        if (selectedSprint() <= 0) { gaps.push(gapLabels.sprint); }
        var \$box = \$modal.find('.sprint-be-completeness').empty();
        if (!gaps.length) {
            \$box.append(jQuery('<span class="sprint-ready-badge ms-0"></span>')
                .append('<i class="fas fa-check" aria-hidden="true"></i> ')
                .append(document.createTextNode("{$lblComplete}")));
        } else {
            \$box.append(jQuery('<span class="sprint-plan-missing-badge ms-0"></span>')
                .append('<i class="fas fa-circle-exclamation" aria-hidden="true"></i> ')
                .append(document.createTextNode(fmt("{$lblMissing}", gaps.join(', ')))));
        }
    }

    function renderState(\$row) {
        var \$box = \$modal.find('.sprint-be-state').empty();
        var pending = String(\$row.attr('data-pending-assign') || '0') === '1';
        var proposed = String(\$row.attr('data-proposed-sprint-name') || '');
        var cls, icon, txt;
        if (pending) {
            cls = 'sprint-chip sprint-chip-warning sprint-chip-wrap'; icon = 'fa-hourglass-half';
            txt = fmt("{$stRequested}", \$row.attr('data-pending-sprint-name') || proposed);
        } else if (proposed !== '') {
            cls = 'sprint-state-proposed'; icon = 'fa-flag-checkered';
            txt = fmt("{$stProposed}", proposed);
        } else {
            cls = 'sprint-state-backlog'; icon = 'fa-layer-group';
            txt = "{$stNotPlanned}";
        }
        \$box.append(jQuery('<span></span>').addClass(pending ? cls : 'sprint-state-badge ' + cls)
            .append('<i class="fas ' + icon + ' me-1" aria-hidden="true"></i>')
            .append(document.createTextNode(txt)));
    }

    // Select a value without wiping preloaded options (the sprint dropdown is
    // a static select); append the option only when it's missing (ajax
    // select2s like the user dropdown, or a no-longer-listed sprint).
    function setSelect2(\$sel, value, label) {
        if (!\$sel.length) { return; }
        if (!\$sel.find("option[value='" + String(value) + "']").length) {
            \$sel.append(new Option(label || '-----', String(value)));
        }
        \$sel.val(String(value)).trigger('change');
    }

    var previewSeq = 0;
    function refreshPreview() {
        var \$box     = \$modal.find('.sprint-be-cap-preview');
        var sprintId = selectedSprint();
        var userId   = parseInt(\$modal.find("select[name='_backlog_modal_users_id']").val(), 10) || 0;
        var own      = parseFloat(\$modal.find("input[name='_backlog_modal_capacity']").val()) || 0;
        var itemId   = parseInt(\$modal.find('input[name=id]').val(), 10) || 0;

        if (sprintId <= 0 || userId <= 0) {
            \$box.hide();
            return;
        }
        // Server-rendered with the shared capacity model: same terms, bar and
        // "almost full" threshold as the sprint views. Late answers are dropped.
        var seq = ++previewSeq;
        jQuery.ajax({
            url: capacityUrl, type: 'GET', dataType: 'json', cache: false,
            data: { sprint_id: sprintId, users_id: userId, item_id: itemId, capacity: own }
        }).done(function(resp){
            if (seq !== previewSeq) { return; }
            if (!resp || !resp.success) { \$box.hide(); return; }
            \$box.removeClass('alert-info alert-warning alert-danger alert-success');
            if (!resp.is_member) {
                \$box.addClass('alert-warning')
                    .html('<i class="fas fa-user-slash me-1" aria-hidden="true"></i>{$msgNotMember}')
                    .show();
                return;
            }
            var cls = resp.state === 'over' ? 'alert-danger'
                : ((resp.state === 'almost_full' || resp.state === 'full') ? 'alert-warning' : 'alert-info');
            \$box.addClass(cls).html(resp.html || '').show();
        }).fail(function(){ \$box.hide(); });
    }

    jQuery(document).on('click', '.sprint-backlog-edit-btn', function(){
        var \$row = jQuery(this).closest('tr.sprint-backlog-row');
        if (!\$row.length) { return; }
        var itemId = parseInt(\$row.data('item-id'), 10) || 0;
        if (itemId <= 0) { return; }
        \$opener = jQuery(this);

        \$modal.find('.sprint-be-error').hide().text('');
        \$modal.find('.sprint-be-delete-confirm').prop('hidden', true);
        \$modal.find('.sprint-be-title').text(\$row.attr('data-item-name') || '');
        \$modal.find('input[name=id]').val(itemId);
        \$modal.find('input[name=name]').val(\$row.attr('data-item-name') || '');
        // Linked items mirror the underlying item's name (server-enforced);
        // only manual items expose an editable name.
        (function() {
            var isLinked = String(\$row.data('item-type') || 'manual') !== 'manual';
            \$modal.find('input[name=name]')
                .prop('readonly', isLinked)
                .attr('title', isLinked ? '{$lblNameFollows}' : '');
        })();
        \$modal.find('input[name=is_fastlane]').prop('checked', parseInt(\$row.attr('data-is-fastlane'), 10) === 1);
        \$modal.find('input[name=is_blocked]').prop('checked', parseInt(\$row.attr('data-is-blocked'), 10) === 1);
        \$modal.find('input[name=is_adhoc]').prop('checked', parseInt(\$row.attr('data-is-adhoc'), 10) === 1);
        \$modal.find('input[name=is_parked]').prop('checked', parseInt(\$row.attr('data-is-parked'), 10) === 1);
        \$modal.find("select[name='_backlog_modal_category']").val(String(parseInt(\$row.attr('data-category-id'), 10) || 0));
        // Populate tag checkboxes from the row's `|tag1|tag2|` lowercased blob.
        var tagBlob = String(\$row.attr('data-item-tags') || '|').toLowerCase();
        \$modal.find('.sprint-be-tag').each(function() {
            var v = String(jQuery(this).val() || '').toLowerCase();
            jQuery(this).prop('checked', v !== '' && tagBlob.indexOf('|' + v + '|') !== -1);
        });
        \$modal.find('.sprint-be-delete-form input[name=id]').val(itemId);
        \$modal.find('.sprint-be-deps-open').toggle(parseInt(\$row.attr('data-is-fastlane'), 10) !== 1);
        renderState(\$row);

        var ownerId   = parseInt(\$row.attr('data-users-id'), 10) || 0;
        var ownerName = \$row.attr('data-owner-name') || '';
        setSelect2(\$modal.find("select[name='_backlog_modal_users_id']"), ownerId, ownerId > 0 ? ownerName : '-----');

        var sprintId   = parseInt(\$row.attr('data-proposed-sprint-id'), 10) || 0;
        var sprintName = \$row.attr('data-proposed-sprint-name') || '';
        setSelect2(\$modal.find("select[name='_backlog_modal_sprint_id']"), sprintId, sprintId > 0 ? sprintName : '-----');

        // Capacity is a number input: "5.0" and "5" are the same value, 0 = no estimate.
        (function() {
            var num = parseFloat(\$row.attr('data-capacity'));
            \$modal.find("input[name='_backlog_modal_capacity']")
                .val(isNaN(num) || num <= 0 ? '' : String(num)).trigger('change');
        })();

        (function() {
            var \$cust = \$modal.find("select[name='_backlog_modal_customer']");
            if (\$cust.length) {
                // A deactivated customer has no option — inject one so saving
                // cannot silently drop it; it goes when the next item opens.
                var cur = String(parseInt(\$row.attr('data-customer-id'), 10) || 0);
                \$cust.find('option.sprint-be-customer-legacy').remove();
                if (cur !== '0' && !\$cust.find("option[value='" + cur + "']").length) {
                    \$cust.append(jQuery('<option class="sprint-be-customer-legacy"></option>')
                        .val(cur)
                        .text((\$row.attr('data-customer-name') || ('#' + cur)) + ' ({$lblInactive})'));
                }
                \$cust.val(cur);
            }
            var \$cred = \$modal.find("input[name='_backlog_modal_credits']");
            if (\$cred.length) { \$cred.val(\$row.attr('data-credits') || '0'); }
            var \$prod = \$modal.find("select[name='_backlog_modal_product']");
            if (\$prod.length) {
                var pid = String(parseInt(\$row.attr('data-credit-product-id'), 10) || 0);
                \$prod.val(\$prod.find("option[value='" + pid + "']").length ? pid : '0');
            }
        })();

        refreshPreview();
        updateDepsButton();
        updateCompleteness();
        bootstrap.Modal.getOrCreateInstance(\$modal[0]).show();
    });

    // Delete: inline confirmation naming the item and the effect.
    jQuery(document).on('click', '.sprint-be-delete-ask', function(){
        var \$box = \$modal.find('.sprint-be-delete-confirm');
        \$box.find('.sprint-be-delete-text').text(fmt("{$msgDelete}", \$modal.find('.sprint-be-title').text()));
        \$box.prop('hidden', false);
        \$box[0].scrollIntoView({block: 'nearest'});
        \$box.find('.sprint-be-delete-keep').trigger('focus');
    });
    jQuery(document).on('click', '.sprint-be-delete-keep', function(){
        \$modal.find('.sprint-be-delete-confirm').prop('hidden', true);
        \$modal.find('.sprint-be-delete-ask').trigger('focus');
    });

    // Tag required (plugin setting): checked before every save from this
    // modal; the server enforces the same rule.
    function tagsMissing() {
        var \$block = \$modal.find('.sprint-be-tags-block');
        if (!\$block.length || parseInt(\$block.attr('data-tag-required'), 10) !== 1) { return false; }
        if (\$modal.find('.sprint-be-tag:checked').length > 0) { return false; }
        \$modal.find('.sprint-be-error').text("{$msgTagRequired}").show();
        \$block[0].scrollIntoView({block: 'nearest'});
        return true;
    }

    // Reason for a capacity above the maximum per item, asked by the server
    // once (needs_justification) and sent with the next save.
    var capJustification = null;
    function saveItem() {
        var itemId = parseInt(\$modal.find('input[name=id]').val(), 10) || 0;
        var tags = [];
        \$modal.find('.sprint-be-tag:checked').each(function() {
            tags.push(jQuery(this).val());
        });
        return window.sprintUi.post(endpoint, {
                    _tags_json: JSON.stringify(tags),
                    id: itemId,
                    name: \$modal.find('input[name=name]').val(),
                    users_id: parseInt(\$modal.find("select[name='_backlog_modal_users_id']").val(), 10) || 0,
                    capacity: parseFloat(\$modal.find("input[name='_backlog_modal_capacity']").val()) || 0,
                    capacity_justification: capJustification === null ? undefined : capJustification,
                    proposed_sprints_id: selectedSprint(),
                    is_fastlane: \$modal.find('input[name=is_fastlane]').is(':checked') ? 1 : 0,
                    is_blocked: \$modal.find('input[name=is_blocked]').is(':checked') ? 1 : 0,
                    is_adhoc: \$modal.find('input[name=is_adhoc]').is(':checked') ? 1 : 0,
                    is_parked: \$modal.find('input[name=is_parked]').is(':checked') ? 1 : 0,
                    plugin_sprint_sprintcategories_id: parseInt(\$modal.find("select[name='_backlog_modal_category']").val(), 10) || 0,
                    // '' (no option selected) is "not sent"; only an explicit 0 detaches.
                    plugin_sprint_sprintcustomers_id: \$modal.find("select[name='_backlog_modal_customer']").length
                        ? (\$modal.find("select[name='_backlog_modal_customer']").val() === null
                            ? '' : String(\$modal.find("select[name='_backlog_modal_customer']").val()))
                        : undefined,
                    credits: \$modal.find("input[name='_backlog_modal_credits']").length
                        ? \$modal.find("input[name='_backlog_modal_credits']").val()
                        : undefined,
                    plugin_sprint_sprintcreditproducts_id: \$modal.find("select[name='_backlog_modal_product']").length
                        ? \$modal.find("select[name='_backlog_modal_product']").val()
                        : undefined
                }, { silent: true });
    }

    function reloadPage() {
        if (window.sprintBacklogReload) { window.sprintBacklogReload(); }
        else if (window.sprintUi && typeof window.sprintUi.refresh === 'function') { window.sprintUi.refresh(); }
        else { window.location.reload(); }
    }

    // In-place row swap; a category change moves the row to its new section
    // without a page load. Only a blocked/parked tier change still reloads
    // (keeping filters and scroll position), because those lists use a
    // different table layout.
    function refreshRow(itemId, done) {
        jQuery.ajax({
            url: "{$rowUrl}", type: 'GET', dataType: 'json', cache: false,
            data: { id: itemId }
        }).done(function(resp){
            if (!resp || !resp.success || !resp.html) {
                reloadPage();
                return;
            }
            var \$old = jQuery('tr.sprint-backlog-row[data-item-id="' + itemId + '"]');
            if (!\$old.length) { reloadPage(); return; }

            var wasBlocked = parseInt(\$old.attr('data-is-blocked'), 10) === 1;
            var wasParked  = parseInt(\$old.attr('data-is-parked'), 10) === 1;
            var wasCat     = parseInt(\$old.attr('data-category-id'), 10) || 0;
            var newCat     = parseInt(resp.category_id, 10) || 0;
            if (wasBlocked !== (parseInt(resp.is_blocked, 10) === 1)
                || wasParked !== (parseInt(resp.is_parked, 10) === 1)) {
                reloadPage();
                return;
            }
            var \$new = jQuery(resp.html);
            if (wasCat !== newCat && !wasBlocked && !wasParked) {
                var \$target = jQuery('.sprint-backlog-cat-section[data-category-id="' + newCat + '"] .sprint-backlog-table tbody').first();
                if (!\$target.length) { reloadPage(); return; }
                \$old.remove();
                \$target.append(\$new);
            } else {
                \$old.replaceWith(\$new);
            }
            \$new.addClass('sprint-row-flash');
            setTimeout(function(){ \$new.removeClass('sprint-row-flash'); }, 1600);
            // Re-apply the active filters to the fresh row.
            var \$text = jQuery('.sprint-backlog-filter .sf-text');
            if (\$text.length) { \$text[0].dispatchEvent(new Event('input', { bubbles: true })); }
            if (window.sprintBacklogRefreshSections) { window.sprintBacklogRefreshSections(); }
            if (window.sprintBacklogReloadMatrix) { window.sprintBacklogReloadMatrix(); }
            if (done) { done(\$new); }
        }).fail(function(){ reloadPage(); });
    }
    window.sprintBacklogRefreshRow = refreshRow;

    function savedMessage(resp) {
        var sid = selectedSprint();
        var msg = sid > 0 ? fmt("{$okProposed}", escH(selectedSprintName())) : "{$okNotPlanned}";
        if (resp && resp.message && resp.message !== 'Item updated') { msg += "\\n" + resp.message; }
        return msg;
    }

    // Save (and optionally assign right after). A failure keeps the dialog
    // open with the input intact, so the user can simply retry.
    function saveFlow(\$btn, thenAssign) {
        var itemId = parseInt(\$modal.find('input[name=id]').val(), 10) || 0;
        if (itemId <= 0) { return; }
        \$modal.find('.sprint-be-error').hide().text('');
        if (tagsMissing()) { return; }
        \$modal.find('.sprint-be-save, .sprint-be-assign').prop('disabled', true);
        var restore = function(){
            \$modal.find('.sprint-be-save').prop('disabled', false);
            updateDepsButton();
        };
        saveItem().done(function(resp){
            if (resp && resp.needs_justification && window.sprintCapacityJustify) {
                restore();
                window.sprintCapacityJustify(resp, function(reason){
                    capJustification = reason;
                    saveFlow(\$btn, thenAssign);
                });
                return;
            }
            if (resp && resp.success) {
                capJustification = null;
                var msg = savedMessage(resp);
                refreshRow(itemId, function(\$row){
                    restore();
                    bootstrap.Modal.getOrCreateInstance(\$modal[0]).hide();
                    if (!thenAssign) { notify('success', msg); return; }
                    var form = \$row && \$row.find('form.sprint-backlog-assign-form')[0];
                    var \$req = \$row ? \$row.find('.sprint-backlog-request-btn') : jQuery();
                    if (form && window.sprintBacklogAssign) {
                        window.sprintBacklogAssign(form);
                    } else if (\$req.length && !\$req.prop('disabled')) {
                        \$req.trigger('click');
                    } else {
                        notify('info', "{$msgAlreadyReq}");
                    }
                });
            } else {
                \$modal.find('.sprint-be-error')
                    .text((resp && resp.message) || '{$msgSaveFailed}').show();
                restore();
            }
        }).fail(function(){
            \$modal.find('.sprint-be-error').text("{$msgNetwork}").show();
            restore();
        });
    }

    jQuery(document).on('click', '.sprint-be-save', function(){ saveFlow(jQuery(this), false); });
    jQuery(document).on('click', '.sprint-be-assign', function(){ saveFlow(jQuery(this), true); });

    // Add dependency: persist the item first (the endpoint only accepts
    // dependencies for a saved proposed sprint), then stack the deps modal on
    // top of this one so closing it lands back here.
    jQuery(document).on('click', '.sprint-be-deps-open', function(){
        var \$btn     = jQuery(this);
        var itemId   = parseInt(\$modal.find('input[name=id]').val(), 10) || 0;
        var sprintId = selectedSprint();
        if (itemId <= 0 || sprintId <= 0) { return; }
        \$modal.find('.sprint-be-error').hide().text('');
        if (tagsMissing()) { return; }
        \$btn.prop('disabled', true);
        saveItem().done(function(resp){
            \$btn.prop('disabled', false);
            if (!resp || !resp.success) {
                \$modal.find('.sprint-be-error')
                    .text((resp && resp.message) || '{$msgSaveFailed}').show();
                return;
            }
            var name    = \$modal.find('input[name=name]').val() || '';
            var ownerId = parseInt(\$modal.find("select[name='_backlog_modal_users_id']").val(), 10) || 0;
            jQuery('tr.sprint-backlog-row[data-item-id="' + itemId + '"]')
                .attr('data-proposed-sprint-id', sprintId);
            if (window.sprintBacklogOpenDeps) {
                window.sprintBacklogOpenDeps(itemId, name, ownerId, sprintId);
            }
        }).fail(function(){
            \$modal.find('.sprint-be-error').text("{$msgNetwork}").show();
            \$btn.prop('disabled', false);
        });
    });
})();
</script>
HTML;
    }

    /**
     * Modal + JS for the per-row "add dependency" button. Dependencies are
     * limited to members of the row's pre-selected sprint; the member list is
     * fetched live for whatever sprint is chosen in that row's dropdown.
     */
    private static function renderDependencyUI(): void
    {
        $depEndpoint = Plugin::getWebDir('sprint') . '/ajax/dependencyadd.php';
        $membersUrl  = Plugin::getWebDir('sprint') . '/ajax/getsprintmembers.php';
        $depsUrl     = Plugin::getWebDir('sprint') . '/ajax/dependencies.php';

        $capacityOptions = '';
        foreach (SprintMember::getCapacityChoices(false) as $val => $label) {
            $capacityOptions .= "<option value='" . htmlescape((string)$val) . "'>" . htmlescape($label) . "</option>";
        }

        $titleAdd      = __('Add dependency', 'sprint');
        $lblMember     = __('Sprint member', 'sprint');
        $lblCapacity   = __('Capacity', 'sprint');
        $lblCancel     = __('Cancel');
        $lblAdd        = __('Add', 'sprint');
        $msgPickSprint = addslashes(__('Pre-select a sprint for this item first (edit the item and choose a sprint).', 'sprint'));
        $msgNoMembers  = addslashes(__('The selected sprint has no members yet.', 'sprint'));
        $msgSelectBoth = addslashes(__('Please select a sprint member and a capacity > 0', 'sprint'));
        $hint          = __('Only members of the proposed sprint can be added.', 'sprint');
        $lblClose      = __s('Close');
        $lblRemoveDep  = addslashes(__('Remove dependency: %s', 'sprint'));
        $lblAddAnyway  = addslashes(__('Add anyway', 'sprint'));
        $msgFailed     = addslashes(__('Could not add the dependency.', 'sprint'));
        $msgNetwork    = addslashes(__('Network error — nothing was saved, try again.', 'sprint'));
        $msgSaved      = addslashes(__('Dependency added.', 'sprint'));

        // The helper's own billable share (a check, a review), charged to the
        // item's customer on top of the item's credits.
        $creditFields = '';
        if (SprintCustomer::canUseCredits()) {
            $creditFields = "<div class='row g-2'>"
                . "<div class='col-md-8 mb-2'><label class='form-label fw-bold' for='sprint-deps-product'><i class='fas fa-tags me-1' aria-hidden='true'></i>"
                . htmlescape(SprintCreditProduct::getTypeName(1)) . "</label>"
                . self::withId(SprintItem::productSelect('_deps_product', 0, '_deps_credits', 'sprint-deps-product'), 'sprint-deps-product')
                . "</div>"
                . "<div class='col-md-4 mb-2'><label class='form-label fw-bold' for='sprint-deps-credits'><i class='fas fa-coins me-1' aria-hidden='true'></i>"
                . htmlescape(__('Credits', 'sprint')) . "</label>"
                . self::withId(SprintItem::creditsInput('_deps_credits', 0, 'w-100 sprint-deps-credits'), 'sprint-deps-credits')
                . "</div></div>";
            SprintItem::creditProductScript();
        }

        echo <<<HTML
<div class="modal fade" id="sprint-backlog-deps-modal" tabindex="-1" aria-hidden="true" aria-labelledby="sprint-backlog-deps-title">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sprint-backlog-deps-title"><i class="fas fa-link me-1" aria-hidden="true"></i> {$titleAdd}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{$lblClose}"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted sprint-deps-item-name fw-bold mb-2"></p>
        <p class="text-muted sprint-small">{$hint}</p>
        <div class="alert alert-warning py-1 sprint-small sprint-deps-status mb-2" role="status" style="display:none;"></div>
        <div class="sprint-deps-list sprint-small mb-2"></div>
        <div class="mb-2">
          <label class="form-label fw-bold" for="sprint-deps-member">{$lblMember}</label>
          <select class="form-select sprint-deps-member" id="sprint-deps-member"></select>
        </div>
        <div class="mb-2">
          <label class="form-label fw-bold" for="sprint-deps-capacity">{$lblCapacity} %</label>
          <select class="form-select sprint-deps-capacity" id="sprint-deps-capacity">{$capacityOptions}</select>
        </div>
        {$creditFields}
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$lblCancel}</button>
        <button type="button" class="btn btn-primary sprint-deps-add">{$lblAdd}</button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }
    var depEndpoint = "{$depEndpoint}";
    var membersUrl  = "{$membersUrl}";
    var \$modal;

    // Modals below this one keep their focus trap and their body lock, so the
    // deps dialog can open on top of the edit modal and hand control back.
    function modalsBelow() {
        return jQuery('.modal.show').not(\$modal);
    }
    function setFocusTrap(\$modals, on) {
        \$modals.each(function(){
            var inst = bootstrap.Modal.getInstance(this);
            try { on ? inst._focustrap.activate() : inst._focustrap.deactivate(); } catch (e) {}
        });
    }

    jQuery(function(){
        \$modal = jQuery('#sprint-backlog-deps-modal');
        if (\$modal.length === 0) { return; }
        \$modal = \$modal.detach().appendTo('body');

        \$modal.on('show.bs.modal', function(){
            var \$below = modalsBelow();
            if (!\$below.length) { return; }
            \$modal.css('z-index', 1065);
            setFocusTrap(\$below, false);
        });
        \$modal.on('shown.bs.modal', function(){
            if (modalsBelow().length) {
                jQuery('.modal-backdrop').last().css('z-index', 1060);
            }
        });
        \$modal.on('hidden.bs.modal', function(){
            \$modal.css('z-index', '');
            var \$below = modalsBelow();
            if (!\$below.length) { return; }
            jQuery(document.body).addClass('modal-open');
            setFocusTrap(\$below, true);
        });
    });

    // The compact rows no longer embed a sprint dropdown: the pre-selected
    // sprint is exposed on the row as data-proposed-sprint-id.
    function currentSprintFor(itemId) {
        var \$row = jQuery('tr.sprint-backlog-row[data-item-id="' + itemId + '"]');
        return \$row.length ? (parseInt(\$row.attr('data-proposed-sprint-id'), 10) || 0) : 0;
    }

    // Feedback that never fails silently: the page-wide backlog notifier
    // (toast, or the inline status region when GLPI has no toast function).
    function depsWarn(msg) {
        if (window.sprintBacklogNotify) { window.sprintBacklogNotify('warning', msg); }
    }

    function showDepsStatus(msg, ok) {
        var \$status = \$modal ? \$modal.find('.sprint-deps-status') : jQuery();
        if (\$status.length) {
            \$status.toggleClass('alert-warning', !ok).toggleClass('alert-success', !!ok)
                .text(msg).show();
        } else { depsWarn(msg); }
    }

    // Overflow warning: ask inline, with the server's message, instead of confirm().
    function askOverflow(msg, onYes) {
        var \$status = \$modal.find('.sprint-deps-status');
        \$status.removeClass('alert-success').addClass('alert-warning').empty()
            .append(jQuery('<div class="mb-1"></div>').text(msg))
            .append(jQuery('<button type="button" class="btn btn-sm btn-warning"></button>')
                .text("{$lblAddAnyway}")
                .on('click', function(){ \$status.hide(); onYes(); }))
            .show();
        \$status.find('button').trigger('focus');
    }

    // Current dependency list, so multiple helpers can be added in a row.
    function loadDepsList(itemId) {
        var \$list = \$modal.find('.sprint-deps-list').empty();
        jQuery.ajax({
            url: "{$depsUrl}", type: 'GET', dataType: 'json', cache: false,
            data: { action: 'list', plugin_sprint_sprintitems_id: itemId }
        }).done(function(resp){
            if (!resp || !resp.success || !resp.deps || !resp.deps.length) { return; }
            resp.deps.forEach(function(d){
                var \$line = jQuery('<div class="d-flex align-items-center gap-2 py-1"></div>');
                var \$name = jQuery('<span></span>').append(
                    jQuery('<i class="fas fa-link me-1 sprint-dep-link-icon sprint-icon-sm" aria-hidden="true"></i>')
                ).append(document.createTextNode(d.name + ' (' + d.capacity + '%'
                    + (parseFloat(d.credits) > 0 ? ' · ' + d.credits + ' cr.' : '') + ')'));
                if (parseInt(d.is_resolved, 10) === 1) {
                    \$name.css('text-decoration', 'line-through').addClass('text-muted');
                }
                \$line.append(\$name);
                \$line.append(
                    jQuery('<button type="button" class="btn btn-sm btn-outline-danger sprint-deps-remove ms-auto py-0 px-2"><i class="fas fa-xmark" aria-hidden="true"></i></button>')
                        .attr('data-dep-id', d.id)
                        .attr('aria-label', "{$lblRemoveDep}".replace('%s', d.name))
                        .attr('title', "{$lblRemoveDep}".replace('%s', d.name))
                );
                \$list.append(\$line);
            });
        });
    }

    jQuery(document).on('click', '.sprint-deps-remove', function(){
        var \$btn   = jQuery(this);
        var depId  = parseInt(\$btn.attr('data-dep-id'), 10) || 0;
        var itemId = parseInt(\$modal.data('item-id'), 10) || 0;
        if (depId <= 0 || itemId <= 0) { return; }
        \$btn.prop('disabled', true);
        window.sprintUi.post("{$depsUrl}", {
                    action: 'remove',
                    plugin_sprint_sprintitems_id: itemId,
                    id: depId
                }, { silent: true }).done(function(resp){
            if (resp && resp.success) { loadDepsList(itemId); }
            else {
                \$btn.prop('disabled', false);
                showDepsStatus((resp && resp.message) || "{$msgFailed}");
            }
        }).fail(function(){
            \$btn.prop('disabled', false);
            showDepsStatus("{$msgNetwork}");
        });
    });

    function openDepsModal(itemId, name, ownerId, sprintId) {
        if (itemId <= 0) { return; }
        if (!sprintId || sprintId <= 0) { sprintId = currentSprintFor(itemId); }
        if (sprintId <= 0) {
            depsWarn("{$msgPickSprint}");
            return;
        }
        \$modal.data('item-id', itemId);
        \$modal.find('.sprint-deps-item-name').text(name);
        \$modal.find('.sprint-deps-status').hide().text('');
        \$modal.find('.sprint-deps-credits').val('0');
        \$modal.find('.sprint-deps-product').val('0');
        loadDepsList(itemId);
        var \$member = \$modal.find('.sprint-deps-member');
        \$member.html('<option value="0">…</option>');

        jQuery.ajax({
            url: membersUrl, type: 'GET', dataType: 'json', cache: false,
            data: { sprint_id: sprintId, exclude_user: ownerId }
        }).done(function(resp){
            \$member.empty();
            if (resp && resp.success && resp.members && resp.members.length) {
                resp.members.forEach(function(m){
                    \$member.append(jQuery('<option>').val(m.id).text(m.label));
                });
            } else {
                \$member.append('<option value="0">{$msgNoMembers}</option>');
                showDepsStatus("{$msgNoMembers}");
            }
        });

        bootstrap.Modal.getOrCreateInstance(\$modal[0]).show();
    }
    // Used by the backlog edit modal's "Add dependency" button.
    window.sprintBacklogOpenDeps = openDepsModal;

    jQuery(document).on('click', '.sprint-backlog-deps-btn', function(){
        var \$btn = jQuery(this);
        openDepsModal(
            parseInt(\$btn.data('item-id'), 10) || 0,
            \$btn.data('item-name') || '',
            parseInt(\$btn.data('owner-id'), 10) || 0,
            0
        );
    });

    function doAdd(itemId, userId, capacity, confirmOverflow) {
        var \$credits = \$modal.find('.sprint-deps-credits');
        var \$product = \$modal.find('.sprint-deps-product');
        window.sprintUi.post(depEndpoint, {
                    plugin_sprint_sprintitems_id: itemId,
                    users_id: userId,
                    capacity: capacity,
                    credits: \$credits.length ? \$credits.val() : undefined,
                    plugin_sprint_sprintcreditproducts_id: \$product.length ? \$product.val() : undefined,
                    confirm_overflow: confirmOverflow ? 1 : 0
                }, { silent: true }).done(function(resp){
            if (resp && resp.success) {
                // Stay open so more helpers can be added in a row.
                showDepsStatus(resp.message || "{$msgSaved}", true);
                \$modal.find('.sprint-deps-member').val('0');
                if (\$credits.length) { \$credits.val('0'); }
                if (\$product.length) { \$product.val('0'); }
                loadDepsList(itemId);
            } else if (resp && resp.needs_confirm) {
                askOverflow(resp.message, function(){ doAdd(itemId, userId, capacity, true); });
            } else {
                // Error in the dialog itself; the selection stays for a retry.
                showDepsStatus((resp && resp.message) || "{$msgFailed}");
            }
        }).fail(function(){
            showDepsStatus("{$msgNetwork}");
        });
    }

    jQuery(document).on('click', '.sprint-deps-add', function(){
        var itemId   = parseInt(\$modal.data('item-id'), 10) || 0;
        var userId   = parseInt(\$modal.find('.sprint-deps-member').val(), 10) || 0;
        var capacity = parseFloat(\$modal.find('.sprint-deps-capacity').val()) || 0;
        if (itemId <= 0 || userId <= 0 || capacity <= 0) {
            showDepsStatus("{$msgSelectBoth}");
            return;
        }
        \$modal.find('.sprint-deps-status').hide();
        doAdd(itemId, userId, capacity, false);
    });
})();
</script>
HTML;
    }

    /**
     * Backlog list behaviors that live outside the edit modal: the bulk
     * "assign all planned" flow (with per-sprint scoping and a preview of the
     * items that move), the single-row assign with an inline "in sprint"
     * outcome, the non-Scrum-Master "request assignment" button, filter
     * chips / result count / empty states and drag- or keyboard-reorder
     * persistence.
     *
     * @param array<int,int>    $readyBySprint sprintId => count of ready items
     *                          the current user may assign
     * @param array<int,string> $sprintNames   sprintId => sprint name
     * @param list<array{id:int,name:string,sprint:int}> $readyItems the items behind $readyBySprint
     */
    private static function renderInlineEditScript(array $readyBySprint = [], array $sprintNames = [], array $readyItems = []): void
    {
        $bulkUrl  = Plugin::getWebDir('sprint') . '/ajax/bulkassign.php';
        $requestUrl  = Plugin::getWebDir('sprint') . '/ajax/requestcreate.php';
        $reorderUrl  = Plugin::getWebDir('sprint') . '/ajax/reorder.php';
        $assignUrl   = Plugin::getWebDir('sprint') . '/ajax/assigntosprint.php';
        $savedOrderMsg = addslashes(__('Order saved', 'sprint'));
        $prefUrl      = Plugin::getWebDir('sprint') . '/ajax/userpref.php';
        $prefName     = UserPref::BACKLOG_SORT;
        $sortMode     = UserPref::backlogSort();
        $viewSavedMsg = addslashes(__('View saved as your default', 'sprint'));
        $msgFailed    = addslashes(__('Save failed', 'sprint'));
        $msgNetwork   = addslashes(__('Network error — nothing was changed, try again.', 'sprint'));
        $msgAssignFailed = addslashes(__('Could not assign item to sprint', 'sprint'));
        $msgInSprint  = addslashes(__('In sprint: %s', 'sprint'));
        $msgLeft      = addslashes(__('"%1$s" is now in sprint %2$s and has left the backlog.', 'sprint'));
        $lblOpenSprint = addslashes(__('Open sprint', 'sprint'));
        $msgRequested = addslashes(__('Requested — waiting for Scrum Master', 'sprint'));
        $msgMoved     = addslashes(__('Moved to position %d', 'sprint'));
        $lblResult    = addslashes(__('%1$d of %2$d items', 'sprint'));
        $lblResultAll = addslashes(__('%d items', 'sprint'));
        $lblRemoveFilter = addslashes(__('Remove filter: %s', 'sprint'));
        $lblSearch    = addslashes(__('Name'));

        // Sprint-scope modal: assign everything, or kick off one sprint while
        // future sprints (already planned) stay queued. The items that would
        // move are listed for the chosen scope before anything happens.
        $lblBulkTitle  = __s('Assign planned backlog items', 'sprint');
        $lblBulkHint   = __s('Assign every planned item, or limit the kick-off to a single sprint — future sprints that are already planned stay on the backlog.', 'sprint');
        $lblBulkScope  = __s('Target sprint', 'sprint');
        $lblBulkItems  = __s('These items leave the backlog and join their proposed sprint:', 'sprint');
        $lblBulkAll    = __s('All sprints', 'sprint');
        $lblCancel     = __s('Cancel');
        $lblClose      = __s('Close');
        $lblBulkGoN    = addslashes(__('Assign %d items', 'sprint'));

        $dor = Config::getDefinitionReady();
        $dorSection = '';
        if ($dor) {
            $dorSection = "<hr class='my-3'><fieldset><legend class='form-label fw-bold sprint-legend-plain'>"
                . __s('Definition of Ready', 'sprint') . "</legend>"
                . "<p class='text-muted sprint-small mb-2'>"
                . __s('Confirm the checks that hold for the items you are assigning.', 'sprint') . "</p>";
            foreach ($dor as $check) {
                $dorSection .= "<label class='form-check d-block'><input class='form-check-input sprint-dor-check' type='checkbox' value='"
                    . htmlescape($check) . "'><span class='form-check-label'>" . htmlescape($check) . "</span></label>";
            }
            $dorSection .= "</fieldset>";
        }

        $totalReady = array_sum($readyBySprint);
        echo "<div class='modal fade' id='sprint-backlog-bulk-modal' tabindex='-1' aria-hidden='true' aria-labelledby='sprint-backlog-bulk-title'>";
        echo "<div class='modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down'>";
        echo "<div class='modal-content'>";
        echo "<div class='modal-header'><h5 class='modal-title' id='sprint-backlog-bulk-title'>"
            . "<i class='fas fa-layer-group me-1' aria-hidden='true'></i> {$lblBulkTitle}</h5>"
            . "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='{$lblClose}'></button></div>";
        echo "<div class='modal-body'>";
        echo "<p class='text-muted sprint-small'>{$lblBulkHint}</p>";
        echo "<label class='form-label fw-bold' for='sprint-bulk-sprint-choice'>{$lblBulkScope}</label>";
        echo "<select class='form-select sprint-bulk-sprint-choice' id='sprint-bulk-sprint-choice'>";
        echo "<option value='0'>{$lblBulkAll} (" . (int)$totalReady . ")</option>";
        foreach ($readyBySprint as $sid => $cnt) {
            $name = $sprintNames[$sid] ?? ('#' . $sid);
            echo "<option value='" . (int)$sid . "'>" . htmlescape($name) . " (" . (int)$cnt . ")</option>";
        }
        echo "</select>";
        echo "<div class='mt-3 sprint-small'><div class='fw-bold mb-1' id='sprint-bulk-items-title'>{$lblBulkItems}</div>";
        echo "<ul class='sprint-bulk-items mb-0' aria-labelledby='sprint-bulk-items-title'>";
        foreach ($readyItems as $ri) {
            $sid = (int)$ri['sprint'];
            echo "<li data-sprint-id='{$sid}'>" . htmlescape($ri['name'])
                . " <span class='text-muted'>→ " . htmlescape($sprintNames[$sid] ?? ('#' . $sid)) . "</span></li>";
        }
        echo "</ul></div>";
        echo $dorSection;
        echo "</div>";
        echo "<div class='modal-footer'>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>{$lblCancel}</button>";
        echo "<button type='button' class='btn btn-success sprint-backlog-bulk-go'" . ($dor ? ' disabled' : '') . ">"
            . "<i class='fas fa-check me-1' aria-hidden='true'></i><span class='sprint-bulk-go-label'>"
            . htmlescape(sprintf(__('Assign %d items', 'sprint'), (int)$totalReady)) . "</span></button>";
        echo "</div>";
        echo "</div></div></div>";

        // Single-assign DoR dialog, opened via window.sprintDorConfirm.
        self::renderDorDialog();

        // Reason dialog for the non-Scrum-Master "request assignment" button.
        SprintRequest::renderReasonModalUI();

        echo <<<HTML
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }

    function notify(type, msg) {
        if (window.sprintBacklogNotify) { window.sprintBacklogNotify(type, msg); }
    }

    var \$bulkModal;
    jQuery(function(){
        \$bulkModal = jQuery('#sprint-backlog-bulk-modal');
        if (\$bulkModal.length) { \$bulkModal = \$bulkModal.detach().appendTo('body'); }
    });

    jQuery(document).on('change', '.sprint-dor-check', function(){
        \$bulkModal.find('.sprint-backlog-bulk-go')
            .prop('disabled', \$bulkModal.find('.sprint-dor-check:not(:checked)').length > 0);
    });

    // Bulk preview: list exactly the items the chosen scope would move.
    function updateBulkPreview() {
        var sprintId = parseInt(\$bulkModal.find('.sprint-bulk-sprint-choice').val(), 10) || 0;
        var n = 0;
        \$bulkModal.find('.sprint-bulk-items li').each(function(){
            var show = sprintId === 0 || (parseInt(this.getAttribute('data-sprint-id'), 10) || 0) === sprintId;
            this.hidden = !show;
            if (show) { n++; }
        });
        \$bulkModal.find('.sprint-bulk-go-label').text("{$lblBulkGoN}".replace('%d', n));
    }
    jQuery(document).on('change', '.sprint-bulk-sprint-choice', updateBulkPreview);

    // Kick-off: open the scope modal (everything, or one specific sprint).
    jQuery(document).on('click', '.sprint-backlog-bulk-assign', function() {
        if (\$bulkModal && \$bulkModal.length) {
            updateBulkPreview();
            bootstrap.Modal.getOrCreateInstance(\$bulkModal[0]).show();
        }
    });

    var bulkExtra = {};
    jQuery(document).on('click', '.sprint-backlog-bulk-go', function() {
        var \$btn     = jQuery(this);
        var sprintId = parseInt(\$bulkModal.find('.sprint-bulk-sprint-choice').val(), 10) || 0;
        var ready    = \$bulkModal.find('.sprint-dor-check:checked')
            .map(function(){ return this.value; }).get();
        \$btn.prop('disabled', true);
        var data = { sprint_id: sprintId, ready: ready };
        Object.keys(bulkExtra).forEach(function(k) { data[k] = bulkExtra[k]; });
        window.sprintUi.post("{$bulkUrl}", data, { silent: true }).done(function(resp) {
            // Capacity review / credit confirmation per target sprint, then again.
            if (window.sprintAssignGuard && window.sprintAssignGuard(resp, function(more) {
                Object.keys(more).forEach(function(k) {
                    bulkExtra[k] = (k === 'cap_capacity') ? Object.assign(bulkExtra[k] || {}, more[k]) : more[k];
                });
                \$btn.prop('disabled', false).trigger('click');
            })) {
                \$btn.prop('disabled', false);
                return;
            }
            bulkExtra = {};
            if (resp && resp.success) {
                // The message survives the reload (filters + scroll too).
                if (window.sprintBacklogReload) { window.sprintBacklogReload(resp.message); }
                else if (window.sprintUi && typeof window.sprintUi.refresh === 'function') { window.sprintUi.refresh(); }
                else { window.location.reload(); }
            } else {
                notify('error', (resp && resp.message) || "{$msgFailed}");
                \$btn.prop('disabled', false);
            }
        }).fail(function() {
            notify('error', "{$msgNetwork}");
            \$btn.prop('disabled', false);
        });
    });

    // Single-row assign. Handled here in the capture phase (before the
    // generic js/sprint.js handler) so the row can show its new state —
    // "In sprint: X" with a link — before it leaves the backlog, and so
    // failures land in the page's feedback region instead of alert().
    function rowAssign(form) {
        var itemId   = parseInt(form.getAttribute('data-item-id'), 10) || 0;
        var input    = form.querySelector('input[name="plugin_sprint_sprints_id"]');
        var sprintId = input ? (parseInt(input.value, 10) || 0) : 0;
        var row      = form.closest('tr.sprint-backlog-row');
        var name     = row ? (row.getAttribute('data-item-name') || '') : '';
        var btn      = form.querySelector('.sprint-backlog-assign-btn');
        if (itemId <= 0 || sprintId <= 0) { return; }
        var extra = {};

        var doAssign = function(readyChecks) {
            if (btn) { btn.disabled = true; }
            var data = { id: itemId, plugin_sprint_sprints_id: sprintId };
            if (readyChecks && readyChecks.length) { data.ready = readyChecks; }
            Object.keys(extra).forEach(function(k) { data[k] = extra[k]; });
            window.sprintUi.post("{$assignUrl}", data, { silent: true }).done(function(resp) {
                // Capacity review / credit confirmation: answer, then assign again.
                if (window.sprintAssignGuard && window.sprintAssignGuard(resp, function(more) {
                    Object.keys(more).forEach(function(k) { extra[k] = more[k]; });
                    doAssign(readyChecks);
                })) {
                    if (btn) { btn.disabled = false; }
                    return;
                }
                if (!resp || !resp.success) {
                    notify('error', (resp && resp.message) || "{$msgAssignFailed}");
                    if (btn) { btn.disabled = false; }
                    return;
                }
                var sprintName = resp.sprint_name || '';
                var escH = function(v) {
                    return String(v == null ? '' : v).replace(/[&<>"']/g, function(c) {
                        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
                    });
                };
                notify('success', "{$msgLeft}".replace('%1\$s', escH(name)).replace('%2\$s', escH(sprintName)));
                if (!row) { return; }
                // Unmistakable outcome on the row itself, then it leaves the list.
                row.classList.add('sprint-row-assigned');
                var \$row = jQuery(row);
                \$row.find('.sprint-backlog-sprint-cell').empty().append(
                    jQuery('<span class="sprint-state-badge sprint-state-insprint"></span>')
                        .append('<i class="fas fa-circle-check me-1" aria-hidden="true"></i>')
                        .append(document.createTextNode("{$msgInSprint}".replace('%s', sprintName)))
                );
                \$row.find('.sprint-backlog-actions').empty().append(
                    resp.sprint_url
                        ? jQuery('<a class="btn btn-sm btn-outline-success"></a>').attr('href', resp.sprint_url)
                            .append('<i class="fas fa-up-right-from-square me-1" aria-hidden="true"></i>')
                            .append(document.createTextNode("{$lblOpenSprint}"))
                        : jQuery()
                );
                setTimeout(function() {
                    row.style.transition = 'opacity 0.4s';
                    row.style.opacity = '0';
                    setTimeout(function() {
                        var section = row.closest('.sprint-backlog-cat-section');
                        if (row.parentNode) { row.parentNode.removeChild(row); }
                        // The last row of its category: the section says so.
                        if (section && !section.querySelector('tr.sprint-backlog-row')) {
                            section.classList.add('is-all-planned');
                        }
                        if (window.sprintBacklogRefreshSections) { window.sprintBacklogRefreshSections(); }
                        if (window.sprintBacklogReloadMatrix) { window.sprintBacklogReloadMatrix(); }
                    }, 420);
                }, 5000);
            }).fail(function() {
                notify('error', "{$msgNetwork}");
                if (btn) { btn.disabled = false; }
            });
        };

        var isFastlane = row && row.getAttribute('data-is-fastlane') === '1';
        if (!isFastlane && typeof window.sprintDorConfirm === 'function') {
            window.sprintDorConfirm(doAssign, name);
        } else {
            doAssign(null);
        }
    }
    window.sprintBacklogAssign = rowAssign;
    if (!window.__sprintBacklogAssignBound) {
        window.__sprintBacklogAssignBound = true;
        document.addEventListener('submit', function(e) {
            var form = e.target;
            if (!form || !form.classList || !form.classList.contains('sprint-backlog-assign-form')
                || !form.closest('tr.sprint-backlog-row')) {
                return;
            }
            e.preventDefault();
            e.stopImmediatePropagation();
            rowAssign(form);
        }, true);
    }

    // Non-Scrum-Master path: same button spot, but the click files an
    // assignment request that lands in the Scrum Master's approval panel.
    // Ask for a motivation first (same dialog family as back-to-backlog);
    // dismissing the dialog aborts and leaves the button untouched.
    jQuery(document).on('click', '.sprint-backlog-request-btn', function() {
        var \$btn   = jQuery(this);
        var itemId = parseInt(\$btn.data('item-id'), 10) || 0;
        if (itemId <= 0) { return; }
        if (typeof window.sprintRequestReason === 'function') {
            window.sprintRequestReason(function(reason){ sprintSendAssignRequest(\$btn, itemId, reason); });
        } else {
            sprintSendAssignRequest(\$btn, itemId, '');
        }
    });

    function sprintSendAssignRequest(\$btn, itemId, reason) {
        \$btn.prop('disabled', true);
        window.sprintUi.post("{$requestUrl}", { id: itemId, reason: reason }, { silent: true }).done(function(resp) {
            if (resp && resp.success) {
                notify('success', resp.message || "{$msgRequested}");
                // The re-rendered row shows "Requested — waiting for Scrum
                // Master" with Backlog → sprint, never as if already assigned.
                if (window.sprintBacklogRefreshRow) {
                    window.sprintBacklogRefreshRow(itemId);
                } else {
                    \$btn.removeClass('btn-outline-primary').addClass('btn-outline-secondary')
                        .prop('disabled', true)
                        .find('i').attr('class', 'fas fa-hourglass-half');
                }
            } else {
                notify('error', (resp && resp.message) || "{$msgFailed}");
                \$btn.prop('disabled', false);
            }
        }).fail(function() {
            notify('error', "{$msgNetwork}");
            \$btn.prop('disabled', false);
        });
    }

    // Active filters as removable chips, plus "x of y items" and the
    // matching empty state. One reset (the filter bar's) clears them all.
    function backlogFilterSummary(filtering) {
        var \$bar = jQuery('.sprint-backlog-filter').first();
        var \$chips = jQuery('.sprint-backlog-chips').first();
        if (!\$bar.length || !\$chips.length) { return; }
        var \$list = \$chips.find('.sprint-backlog-chip-list').empty();
        \$bar.find('.sf-text, .sf-type, .sf-owner, .sf-sprint, .sf-tag').each(function() {
            var el = this;
            var val = jQuery(el).val();
            if (!val) { return; }
            var label = el.classList.contains('sf-text')
                ? "{$lblSearch}" + ': "' + val + '"'
                : (el.getAttribute('aria-label') || '') + ': ' + jQuery(el).find('option:selected').text();
            var \$chip = jQuery('<button type="button" class="sprint-filter-chip"></button>')
                .attr('aria-label', "{$lblRemoveFilter}".replace('%s', label))
                .append(document.createTextNode(label))
                .append(' <i class="fas fa-xmark" aria-hidden="true"></i>')
                .on('click', function() {
                    jQuery(el).val('');
                    el.dispatchEvent(new Event(el.classList.contains('sf-text') ? 'input' : 'change', { bubbles: true }));
                    var \$next = jQuery('.sprint-backlog-chips .sprint-filter-chip').first();
                    (\$next.length ? \$next : jQuery(el)).trigger('focus');
                });
            \$list.append(\$chip);
        });
        var \$all = jQuery('.sprint-backlog-page tr.sprint-backlog-row');
        var total = \$all.length;
        var shown = \$all.not('.sprint-row-hidden').length;
        \$chips.find('.sprint-backlog-result').text(filtering
            ? "{$lblResult}".replace('%1\$d', shown).replace('%2\$d', total)
            : "{$lblResultAll}".replace('%d', total));
        \$chips.toggleClass('sprint-filtering', filtering);
        jQuery('.sprint-backlog-empty-filtered').prop('hidden', !(filtering && total > 0 && shown === 0));
        // Blocked / non-active lists open up while filtering, so a match is never tucked away.
        if (filtering) {
            jQuery('.sprint-backlog-blocked, .sprint-backlog-parked').each(function() {
                var \$wrap = jQuery(this);
                var hits = \$wrap.find('tr.sprint-backlog-row').not('.sprint-row-hidden').length;
                \$wrap.toggle(hits > 0);
                if (hits > 0) { \$wrap.find('.sprint-backlog-blocked-body, .sprint-backlog-parked-body').show(); }
            });
        } else {
            jQuery('.sprint-backlog-blocked, .sprint-backlog-parked').show();
        }
    }
    jQuery(document).on('click', '.sprint-backlog-filter-reset', function() {
        var reset = jQuery('.sprint-backlog-filter .sf-reset')[0];
        if (reset) { reset.click(); }
        jQuery('.sprint-backlog-filter .sf-text').first().trigger('focus');
    });

    // Counts follow the visible rows; ranks stay tied to the full list.
    function backlogFilterActive() {
        var active = false;
        jQuery('.sprint-backlog-filter').find('.sf-text, .sf-type, .sf-owner, .sf-tag, .sf-sprint').each(function() {
            if (jQuery(this).val()) { active = true; }
        });
        return active;
    }
    function backlogCollapseKey(\$sec) {
        return 'sprint.backlog.cat.' + (\$sec.attr('data-category-id') || '0') + '.collapsed';
    }
    function backlogSubSections(\$sec) {
        var id = \$sec.attr('data-category-id') || '0';
        return id === '0'
            ? jQuery()
            : jQuery(".sprint-backlog-cat-section[data-parent-category-id='" + id + "']");
    }
    // Folding a parent takes its subcategory sections with it; unfolding hands
    // each of them back its own state.
    function backlogApplyCollapse(\$sec) {
        var collapsed = false;
        try { collapsed = localStorage.getItem(backlogCollapseKey(\$sec)) === '1'; } catch (e) {}
        \$sec.find('.sprint-backlog-cat-body').toggle(!collapsed);
        \$sec.find('.sprint-backlog-cat-header').first().attr('aria-expanded', collapsed ? 'false' : 'true');
        \$sec.find('.sprint-backlog-cat-chevron').css('transform', collapsed ? 'rotate(-90deg)' : 'rotate(0deg)');
        backlogSubSections(\$sec).each(function() {
            var \$sub = jQuery(this);
            \$sub.toggle(!collapsed);
            if (!collapsed) { backlogApplyCollapse(\$sub); }
        });
    }
    window.sprintBacklogRefreshSections = function() {
        var filtering = backlogFilterActive();
        jQuery('.sprint-backlog-cat-section').each(function() {
            var \$sec  = jQuery(this);
            var \$rows = \$sec.find('tr.sprint-backlog-row');
            var \$vis  = \$rows.not('.sprint-row-hidden');
            \$sec.find('.sprint-backlog-empty-row').toggle(\$vis.length === 0);
            \$sec.find('.sprint-backlog-cat-count').text(\$vis.length);
            // "Everything is planned" only holds while the section stays empty.
            if (\$rows.length > 0) { \$sec.removeClass('is-all-planned'); }
            var sum = 0;
            \$vis.each(function() { sum += parseFloat(this.getAttribute('data-capacity')) || 0; });
            \$sec.find('.sprint-backlog-cat-capsum').text((Math.round(sum * 10) / 10) + '%');
            // Ranks are the team order: renumbered from the DOM in team view
            // (drag changes them), frozen while the personal sort is active.
            var sortedView = backlogSortMode() === 'project';
            var i = 1;
            \$rows.each(function() {
                if (!sortedView) { this.setAttribute('data-team-rank', i); }
                jQuery(this).find('.sprint-backlog-rank').text(sortedView ? (this.getAttribute('data-team-rank') || '') : i);
                i++;
            });
            // While filtering, matches are always in view and empty buckets step aside.
            if (filtering) {
                \$sec.toggle(\$vis.length > 0);
                \$sec.find('.sprint-backlog-cat-body').show();
                \$sec.find('.sprint-backlog-cat-header').first().attr('aria-expanded', 'true');
                \$sec.find('.sprint-backlog-cat-chevron').css('transform', 'rotate(0deg)');
            } else if ((\$sec.attr('data-parent-category-id') || '0') === '0') {
                // Subsections follow their parent's cascade, not their own turn.
                \$sec.show();
                backlogApplyCollapse(\$sec);
            }
        });
        jQuery('.sprint-backlog-cat-section').each(function() {
            var \$sec = jQuery(this);
            var subs = 0;
            backlogSubSections(\$sec).each(function() {
                subs += jQuery(this).find('tr.sprint-backlog-row').not('.sprint-row-hidden').length;
            });
            \$sec.find('.sprint-backlog-cat-subcount').text('+' + subs).toggle(subs > 0);
        });
        jQuery('.sprint-backlog-count').text(
            jQuery('.sprint-backlog-cat-section tr.sprint-backlog-row').not('.sprint-row-hidden').length
        );
        backlogFilterSummary(filtering);
    };

    // Collapsible category sections (state per category in localStorage).
    jQuery(function() {
        jQuery(".sprint-backlog-cat-section[data-parent-category-id='0']").each(function() {
            backlogApplyCollapse(jQuery(this));
        });
    });
    jQuery(document).on('click', '.sprint-backlog-cat-header', function() {
        var \$sec  = jQuery(this).closest('.sprint-backlog-cat-section');
        var \$body = \$sec.find('.sprint-backlog-cat-body');
        var collapsed = \$body.is(':visible');
        \$body.slideToggle(120);
        jQuery(this).attr('aria-expanded', collapsed ? 'false' : 'true');
        \$sec.find('.sprint-backlog-cat-chevron').css('transform', collapsed ? 'rotate(-90deg)' : 'rotate(0deg)');
        try { localStorage.setItem(backlogCollapseKey(\$sec), collapsed ? '1' : '0'); } catch (e) {}
        backlogSubSections(\$sec).each(function() {
            var \$sub = jQuery(this);
            if (collapsed) {
                \$sub.slideUp(120);
            } else {
                \$sub.slideDown(120);
                backlogApplyCollapse(\$sub);
            }
        });
    });

    jQuery(document).on('keydown', '.sprint-backlog-cat-header', function(e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); jQuery(this).trigger('click'); }
    });

    // Keyboard alternative to drag: move a row one place up/down inside its
    // category; the order is persisted like a drop and focus stays on the
    // button so the move can be repeated.
    jQuery(document).on('click', '.sprint-backlog-move', function(e) {
        e.preventDefault();
        var up  = this.getAttribute('data-dir') === 'up';
        var row = this.closest('tr.sprint-backlog-row');
        if (!row) { return; }
        var sib = up ? row.previousElementSibling : row.nextElementSibling;
        while (sib && !sib.classList.contains('sprint-backlog-row')) {
            sib = up ? sib.previousElementSibling : sib.nextElementSibling;
        }
        if (!sib) { return; }
        row.parentNode.insertBefore(row, up ? sib : sib.nextSibling);
        if (window.sprintBacklogRefreshSections) { window.sprintBacklogRefreshSections(); }
        this.focus();
        var rank = jQuery(row).find('.sprint-backlog-rank').text();
        persistBacklogOrder({}, "{$msgMoved}".replace('%d', rank));
    });

    // Multi-select: Ctrl/Cmd-click toggles a row, Shift-click extends from the
    // last toggled row; a plain click outside links/inputs clears the selection.
    var sprintLastSelected = null;
    jQuery(document).on('click', '.sprint-backlog-table .sprint-backlog-row', function(e) {
        if (e.ctrlKey || e.metaKey) {
            e.preventDefault();
            jQuery(this).toggleClass('sprint-row-selected');
            sprintLastSelected = jQuery(this).hasClass('sprint-row-selected') ? this : null;
            return;
        }
        if (e.shiftKey && sprintLastSelected && sprintLastSelected !== this) {
            var \$all = jQuery('.sprint-backlog-table .sprint-backlog-row');
            var a = \$all.index(sprintLastSelected);
            var b = \$all.index(this);
            if (a > -1 && b > -1) {
                e.preventDefault();
                if (window.getSelection) { window.getSelection().removeAllRanges(); }
                \$all.slice(Math.min(a, b), Math.max(a, b) + 1).addClass('sprint-row-selected');
                return;
            }
        }
        if (!jQuery(e.target).closest('a, button, input, select, label, form').length) {
            jQuery('.sprint-row-selected').removeClass('sprint-row-selected');
            sprintLastSelected = null;
        }
    });

    // Drag-to-reorder via the grip; dropping in another section recategorizes.
    // Dragging a selected row takes the whole selection along.
    var sprintDragRow  = null;
    var sprintDragRows = null;
    jQuery(document).on('mousedown', '.sprint-backlog-table .sprint-backlog-grip', function() {
        var row = this.closest('tr');
        if (row) { row.setAttribute('draggable', 'true'); }
    });
    jQuery(document).on('dragstart', '.sprint-backlog-table .sprint-backlog-row', function(e) {
        sprintDragRow = this;
        var \$sel = jQuery('.sprint-backlog-table .sprint-backlog-row.sprint-row-selected');
        sprintDragRows = (\$sel.length > 1 && jQuery(this).hasClass('sprint-row-selected'))
            ? \$sel.toArray()
            : [this];
        sprintDragRows.forEach(function(r) { r.classList.add('sprint-row-dragging'); });
        try {
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData('text/plain', '');
        } catch (ex) {}
    });
    jQuery(document).on('dragover', '.sprint-backlog-table .sprint-backlog-row', function(e) {
        if (!sprintDragRow || this === sprintDragRow) { return; }
        e.preventDefault();
        var rect  = this.getBoundingClientRect();
        var after = (e.originalEvent.clientY - rect.top) > rect.height / 2;
        var parent = this.parentNode;
        parent.insertBefore(sprintDragRow, after ? this.nextSibling : this);
    });
    // Empty sections have no row to hover — accept the drop on the table.
    jQuery(document).on('dragover', '.sprint-backlog-cat-section .sprint-backlog-table', function(e) {
        if (!sprintDragRow) { return; }
        var \$tbody = jQuery(this).find('tbody').first();
        if (\$tbody.find('tr.sprint-backlog-row').length === 0) {
            e.preventDefault();
            \$tbody.append(sprintDragRow);
        }
    });
    jQuery(document).on('dragend', '.sprint-backlog-table .sprint-backlog-row', function() {
        if (!sprintDragRow) { return; }
        var row   = sprintDragRow;
        var group = sprintDragRows || [row];
        sprintDragRow  = null;
        sprintDragRows = null;
        group.forEach(function(r) {
            r.classList.remove('sprint-row-dragging');
            r.removeAttribute('draggable');
        });

        // Group drag: gather the other selected rows around the dropped anchor,
        // keeping the group's own order (members before the anchor stay before).
        if (group.length > 1 && row.parentNode) {
            var before = [], after = [], seen = false;
            group.forEach(function(r) {
                if (r === row) { seen = true; return; }
                (seen ? after : before).push(r);
            });
            before.forEach(function(r) { row.parentNode.insertBefore(r, row); });
            var ref = row;
            after.forEach(function(r) { row.parentNode.insertBefore(r, ref.nextSibling); ref = r; });
        }

        var catMap = {};
        group.forEach(function(r) {
            var rowId = parseInt(r.getAttribute('data-item-id'), 10) || 0;
            var \$sec  = jQuery(r).closest('.sprint-backlog-cat-section');
            if (rowId && \$sec.length) {
                var newCat = parseInt(\$sec.attr('data-category-id'), 10) || 0;
                var oldCat = parseInt(r.getAttribute('data-category-id'), 10) || 0;
                if (newCat !== oldCat) {
                    catMap[rowId] = newCat;
                    r.setAttribute('data-category-id', newCat);
                }
            }
        });
        jQuery('.sprint-row-selected').removeClass('sprint-row-selected');
        sprintLastSelected = null;

        if (window.sprintBacklogRefreshSections) { window.sprintBacklogRefreshSections(); }
        persistBacklogOrder(catMap);
    });

    // Persist the current DOM order of the category tables as the ranks
    // (plus any category moves), shared by drag-and-drop and the sort button.
    function persistBacklogOrder(catMap, okMsg) {
        var ids = [];
        jQuery('.sprint-backlog-table .sprint-backlog-row').each(function() {
            var id = parseInt(this.getAttribute('data-item-id'), 10) || 0;
            if (id) { ids.push(id); }
        });
        if (!ids.length) { return; }
        window.sprintUi.post("{$reorderUrl}", {
                    order: JSON.stringify(ids),
                    categories: JSON.stringify(catMap || {})
                }, { silent: true }).done(function(resp) {
            if (resp && resp.success) {
                notify('success', okMsg || '{$savedOrderMsg}');
                if (window.sprintBacklogReloadMatrix) { window.sprintBacklogReloadMatrix(); }
            } else {
                notify('error', (resp && resp.message) || "{$msgFailed}");
            }
        }).fail(function() {
            notify('error', "{$msgNetwork}");
        });
    }

    // Sort every category by project, then by task name/number. Rows without
    // a project go last. Natural comparison keeps "Task 2" ahead of "Task 10".
    var sprintNatural = (typeof Intl !== 'undefined' && Intl.Collator)
        ? new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })
        : { compare: function(a, b) { return a.localeCompare(b); } };
    function sprintProjectNameCompare(a, b) {
        var pa = a.getAttribute('data-project-name') || '';
        var pb = b.getAttribute('data-project-name') || '';
        if (pa !== pb) {
            if (pa === '') { return 1; }
            if (pb === '') { return -1; }
            var c = sprintNatural.compare(pa, pb);
            if (c !== 0) { return c; }
        }
        return sprintNatural.compare(
            a.getAttribute('data-item-name') || '',
            b.getAttribute('data-item-name') || ''
        );
    }
    function sprintTeamRankCompare(a, b) {
        return (parseInt(a.getAttribute('data-team-rank'), 10) || 0) - (parseInt(b.getAttribute('data-team-rank'), 10) || 0);
    }
    var backlogSortCurrent = '{$sortMode}';
    function backlogSortMode() { return backlogSortCurrent; }
    function applyBacklogSort(mode) {
        backlogSortCurrent = mode === 'project' ? 'project' : 'team';
        var cmp = backlogSortCurrent === 'project' ? sprintProjectNameCompare : sprintTeamRankCompare;
        jQuery('.sprint-backlog-cat-section .sprint-backlog-table').each(function() {
            var \$tbody = jQuery(this).find('tbody').first();
            var rows    = \$tbody.find('tr.sprint-backlog-row').toArray();
            if (rows.length < 2) { return; }
            rows.sort(cmp);
            rows.forEach(function(r) { \$tbody.append(r); });
        });
        // No drag-to-rank inside a personal sort: the grip would rewrite the team order.
        jQuery('.sprint-backlog-grip, .sprint-backlog-move').css('visibility', backlogSortCurrent === 'project' ? 'hidden' : '');
        jQuery('.sprint-backlog-sort').val(backlogSortCurrent);
        jQuery('.sprint-row-selected').removeClass('sprint-row-selected');
        sprintLastSelected = null;
        if (window.sprintBacklogRefreshSections) { window.sprintBacklogRefreshSections(); }
    }
    // The choice is this user's default from now on (ajax/userpref.php).
    jQuery(document).on('change', '.sprint-backlog-sort', function() {
        var mode = this.value === 'project' ? 'project' : 'team';
        applyBacklogSort(mode);
        window.sprintUi.post("{$prefUrl}", { name: '{$prefName}', value: mode }, { silent: true }).done(function(resp) {
            if (resp && resp.success) {
                notify('success', '{$viewSavedMsg}');
            } else {
                notify('error', "{$msgFailed}");
            }
        }).fail(function() {
            notify('error', "{$msgNetwork}");
        });
    });
    jQuery(function() { applyBacklogSort(backlogSortCurrent); });
})();
</script>
HTML;
    }

    /**
     * Prognosis dashboard: category × upcoming-sprints matrix + work-supply.
     * $lazy (Work queue view) skips the server-side matrix; it is fetched
     * the first time the Planning view is opened.
     */
    private static function renderCategoryDashboard(bool $lazy = false): void
    {
        $statsUrl = Plugin::getWebDir('sprint') . '/ajax/backlogcategorystats.php';
        $capUrl    = Plugin::getWebDir('sprint') . '/ajax/categorycap.php';
        $creditUrl = Plugin::getWebDir('sprint') . '/ajax/customercredits.php';

        // Two views over the same window: category × sprint (the mix against
        // the limits) and member × sprint (each person's share of that mix).
        // The preferred view lives in localStorage; the JS mirrors it into a
        // cookie so the server can render that view straight away instead of
        // the categories first and a second request right after.
        $view = (string)($_COOKIE['sprint_backlog_matrix_view'] ?? 'categories');
        if (
            !in_array($view, ['categories', 'members', 'credits'], true)
            || ($view === 'credits' && !SprintCustomer::canViewCredits())
        ) {
            $view = 'categories';
        }
        $checked = static fn(string $v) => $v === $view ? ' checked' : '';
        $actions = "<div class='sprint-backlog-view btn-group btn-group-sm' role='group' title='"
            . __s('Switch between the capacity per category and the capacity per member', 'sprint') . "'>";
        $actions .= "<input type='radio' class='btn-check' name='sprint-backlog-view' id='sprint-view-categories' value='categories'" . $checked('categories') . ">"
            . "<label class='btn btn-outline-secondary' for='sprint-view-categories'><i class='fas fa-layer-group me-1' aria-hidden='true'></i>" . __('Categories', 'sprint') . "</label>";
        $actions .= "<input type='radio' class='btn-check' name='sprint-backlog-view' id='sprint-view-members' value='members'" . $checked('members') . ">"
            . "<label class='btn btn-outline-secondary' for='sprint-view-members'><i class='fas fa-users me-1' aria-hidden='true'></i>" . __('Members', 'sprint') . "</label>";
        // Reserved credits per customer per sprint, credits right only.
        if (SprintCustomer::canViewCredits()) {
            $actions .= "<input type='radio' class='btn-check' name='sprint-backlog-view' id='sprint-view-credits' value='credits'" . $checked('credits') . ">"
                . "<label class='btn btn-outline-secondary' for='sprint-view-credits'><i class='fas fa-coins me-1' aria-hidden='true'></i>" . __('Credits', 'sprint') . "</label>";
        }
        $actions .= "</div>";
        $actions .= "<label class='text-muted sprint-small mb-0' for='sprint-backlog-horizon'>" . __('Horizon', 'sprint') . "</label>";
        $actions .= "<select id='sprint-backlog-horizon' class='form-select form-select-sm sprint-backlog-horizon sprint-mw-140'>";
        foreach ([4, 8, 13] as $h) {
            $actions .= "<option value='{$h}'>" . sprintf(_n('%d sprint', '%d sprints', $h, 'sprint'), $h) . "</option>";
        }
        $actions .= "</select>";

        echo "<div class='card sprint-backlog-card sprint-backlog-dash'><div class='card-body'>";
        echo SprintUi::cardHeader(
            'fas fa-chart-simple',
            __('Capacity per category', 'sprint'),
            __('Capacity per category across the upcoming sprints: work already assigned plus backlog proposals, including dependencies and subcategories, against optional min/max limits.', 'sprint'),
            $actions
        );
        $fragment = $lazy
            ? "<div class='center text-muted sprint-small sprint-backlog-pad'><i class='fas fa-spinner fa-spin me-1' aria-hidden='true'></i>"
                . __s('Loading…', 'sprint') . "</div>"
            : ($view === 'credits'
                ? self::renderCreditMatrixFragment()
                : ($view === 'members' ? self::renderMemberMatrixFragment() : self::renderCategoryMatrixFragment()));
        echo "<div class='sprint-backlog-dash-body' data-view='" . htmlescape($view) . "'"
            . ($lazy ? " data-stale='1'" : '') . ">" . $fragment . "</div>";
        echo "</div></div>";

        // Limits modal: one dual-range slider (min/max %) per category, saved
        // for the sprint whose header button opened it.
        $lblTitle  = __s('Category limits', 'sprint');
        $lblHint   = __s('Reserve a minimum and cap a maximum share of capacity per category for this sprint. 0 = no limit.', 'sprint');
        $lblMin    = __s('min', 'sprint');
        $lblMax    = __s('max', 'sprint');
        $lblCancel = __s('Cancel');
        $lblSave   = __s('Save');
        echo "<div class='modal fade' id='sprint-limits-modal' tabindex='-1' aria-hidden='true' aria-labelledby='sprint-limits-title'>";
        echo "<div class='modal-dialog modal-dialog-centered modal-dialog-scrollable'>";
        echo "<div class='modal-content'>";
        echo "<div class='modal-header'>";
        echo "<h5 class='modal-title' id='sprint-limits-title'><i class='fas fa-sliders me-1' aria-hidden='true'></i> {$lblTitle}: <span class='sprint-limits-sprintname'></span></h5>";
        echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='" . __s('Close') . "'></button>";
        echo "</div>";
        echo "<div class='modal-body'>";
        echo "<div class='text-muted sprint-small mb-3'>{$lblHint}</div>";
        echo "<div class='sprint-limits-rows'></div>";
        echo "</div>";
        echo "<div class='modal-footer'>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>{$lblCancel}</button>";
        echo "<button type='button' class='btn btn-primary sprint-limits-save'><i class='fas fa-save me-1' aria-hidden='true'></i> {$lblSave}</button>";
        echo "</div>";
        echo "</div></div></div>";

        // Like the category limits, but per customer: what they get this sprint.
        if (SprintCustomer::canEditCredits()) {
            $lblCredTitle = __s('Credit agreement', 'sprint');
            $lblCredHint  = __s('What each customer is granted for this sprint. Leave a field empty to follow their standing agreement.', 'sprint');
            echo "<div class='modal fade' id='sprint-credits-modal' tabindex='-1' aria-hidden='true' aria-labelledby='sprint-credits-title'>";
            echo "<div class='modal-dialog modal-dialog-centered modal-dialog-scrollable'><div class='modal-content'>";
            echo "<div class='modal-header'>";
            echo "<h5 class='modal-title' id='sprint-credits-title'><i class='fas fa-coins me-1' aria-hidden='true'></i> {$lblCredTitle}: <span class='sprint-credits-sprintname'></span></h5>";
            echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='" . __s('Close') . "'></button>";
            echo "</div>";
            echo "<div class='modal-body'>";
            echo "<div class='text-muted sprint-small mb-3'>{$lblCredHint}</div>";
            echo "<div class='sprint-credits-rows'></div>";
            echo "</div>";
            echo "<div class='modal-footer'>";
            echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>{$lblCancel}</button>";
            echo "<button type='button' class='btn btn-primary sprint-credits-save'><i class='fas fa-save me-1' aria-hidden='true'></i> {$lblSave}</button>";
            echo "</div>";
            echo "</div></div></div>";
        }

        $lblCredits    = __s('Credits', 'sprint');
        $lblCreditsMax = __s('Max', 'sprint');
        $lblAgreedFmt  = addslashes(__('standing agreement: %s', 'sprint'));
        $msgDashFailed = addslashes(__('Save failed', 'sprint'));
        $msgDashNet    = addslashes(__('Network error — nothing was changed, try again.', 'sprint'));
        $msgDashSaved  = addslashes(__('Limits saved', 'sprint'));

        echo <<<HTML
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }

    var SLIDER_MAX = 1000;

    function currentHorizon() {
        return parseInt(jQuery('.sprint-backlog-horizon').val(), 10) || 4;
    }
    function currentView() {
        var v = jQuery('input[name="sprint-backlog-view"]:checked').val();
        return (v === 'members' || v === 'credits') ? v : 'categories';
    }
    // Remember the categories/members/credits view per user.
    (function(){
        var \$view = jQuery('input[name="sprint-backlog-view"]');
        if (!\$view.length) { return; }
        try {
            var stored = localStorage.getItem('sprint.backlog.matrix.view');
            // A stored view whose radio is gone falls back to the default.
            if (stored && \$view.filter('[value="' + stored + '"]').length) {
                \$view.filter('[value="' + stored + '"]').prop('checked', true);
            }
        } catch (e) {}
        \$view.on('change', function(){
            rememberView();
            reloadMatrix();
        });
    })();
    // The preference is kept in localStorage; the cookie only tells the server
    // which view to render first, so the page does not load one and fetch another.
    function rememberView() {
        try { localStorage.setItem('sprint.backlog.matrix.view', currentView()); } catch (e) {}
        try { document.cookie = 'sprint_backlog_matrix_view=' + currentView() + ';path=/;max-age=31536000;SameSite=Lax'; } catch (e) {}
    }
    if (currentView() !== String(jQuery('.sprint-backlog-dash-body').attr('data-view') || 'categories')) {
        rememberView();
        jQuery(reloadMatrix);
    }

    // While the Planning zone is hidden (Work queue view) the matrix is only
    // marked stale; opening the zone fetches it (sprintBacklogShowPlanning).
    function reloadMatrix() {
        var \$body = jQuery('.sprint-backlog-dash-body');
        if (\$body.closest('.sprint-backlog-zone-planning').is('[hidden]')) {
            \$body.attr('data-stale', '1');
            return;
        }
        \$body.removeAttr('data-stale');
        jQuery('.sprint-backlog-dash-body').css('opacity', 0.5);
        jQuery.ajax({ url: "{$statsUrl}", type: 'GET', dataType: 'json', cache: false,
            data: { horizon: currentHorizon(), view: currentView() } })
        .done(function(resp){
            if (resp && resp.success) {
                jQuery('.sprint-backlog-dash-body').html(resp.html);
                applyCollapsed();
            }
        }).always(function(){ jQuery('.sprint-backlog-dash-body').css('opacity', 1); });
    }

    // Subcategory rows show by default; collapsing a parent is remembered per
    // user. The parent keeps showing the rolled-up totals either way.
    function collapsedCats() {
        try {
            var raw = JSON.parse(localStorage.getItem('sprint.backlog.matrix.collapsed') || '[]');
            return Array.isArray(raw) ? raw.map(String) : [];
        } catch (e) { return []; }
    }
    function setCollapsed(catId, collapsed) {
        jQuery(".sprint-backlog-dash-body .sprint-matrix-toggle[data-cat-id='" + catId + "']")
            .toggleClass('collapsed', collapsed)
            .attr('aria-expanded', collapsed ? 'false' : 'true');
        jQuery(".sprint-backlog-dash-body .sprint-matrix-subrow[data-parent-cat='" + catId + "']")
            .toggle(!collapsed);
    }
    function applyCollapsed() {
        var list = collapsedCats();
        jQuery('.sprint-backlog-dash-body .sprint-matrix-toggle').each(function(){
            var id = String(jQuery(this).attr('data-cat-id') || '0');
            setCollapsed(id, list.indexOf(id) !== -1);
        });
    }
    jQuery(document).on('click', '.sprint-matrix-toggle', function(){
        var id        = String(jQuery(this).attr('data-cat-id') || '0');
        var collapsed = !jQuery(this).hasClass('collapsed');
        setCollapsed(id, collapsed);
        var list = collapsedCats().filter(function(v){ return v !== id; });
        if (collapsed) { list.push(id); }
        try { localStorage.setItem('sprint.backlog.matrix.collapsed', JSON.stringify(list)); } catch (e) {}
    });
    jQuery(function(){ applyCollapsed(); });
    // Row edits and drags elsewhere on the page keep the matrix in sync.
    window.sprintBacklogReloadMatrix = reloadMatrix;

    // Planning horizon: persisted per user, restored on load.
    jQuery(document).on('change', '.sprint-backlog-horizon', function(){
        try { localStorage.setItem('sprint.backlog.matrix.horizon', this.value); } catch (e) {}
        reloadMatrix();
    });
    (function(){
        var stored = 0;
        try { stored = parseInt(localStorage.getItem('sprint.backlog.matrix.horizon'), 10) || 0; } catch (e) {}
        if (stored > 0 && jQuery('.sprint-backlog-horizon option[value="' + stored + '"]').length) {
            jQuery('.sprint-backlog-horizon').val(String(stored));
            if (stored !== 4) { reloadMatrix(); }
        }
    })();

    // Cap 0 = no ceiling: that thumb parks at the far right, clear of the min.
    function maxToSlider(max) { return max > 0 ? max : SLIDER_MAX; }
    function sliderToMax(v)   { return v >= SLIDER_MAX ? 0 : v; }

    function updateFill(\$row) {
        var min   = parseFloat(\$row.find('.sprint-limit-min').val()) || 0;
        var max   = parseFloat(\$row.find('.sprint-limit-max').val()) || 0;
        var left  = 0, width = 0;
        if (max > 0) {
            left  = Math.min(min, max) / SLIDER_MAX * 100;
            width = (max - Math.min(min, max)) / SLIDER_MAX * 100;
        } else if (min > 0) {
            // Minimum without cap: filled from the floor to the end.
            left  = min / SLIDER_MAX * 100;
            width = 100 - left;
        }
        \$row.find('.sprint-limits-fill').css({ left: left + '%', width: Math.max(0, width) + '%' });
    }

    function buildRow(cat) {
        var \$row = jQuery(
            "<div class='sprint-limits-row' data-category-id='" + (parseInt(cat.id, 10) || 0) + "'>" +
            "<div class='sprint-limits-row-head'>" +
            "<span class='sprint-limits-dot'></span><strong class='sprint-limits-name'></strong>" +
            "<span class='sprint-limits-vals'>" +
            "<label>{$lblMin} <input type='number' class='form-control form-control-sm sprint-limit-min' min='0' max='" + SLIDER_MAX + "' step='0.5'></label>" +
            "<label>{$lblMax} <input type='number' class='form-control form-control-sm sprint-limit-max' min='0' max='" + SLIDER_MAX + "' step='0.5'></label>" +
            "<span class='text-muted sprint-small'>%</span></span></div>" +
            "<div class='sprint-limits-slider'>" +
            "<div class='sprint-limits-track'><div class='sprint-limits-fill'></div></div>" +
            "<input type='range' class='sprint-range-min' min='0' max='" + SLIDER_MAX + "' step='5'>" +
            "<input type='range' class='sprint-range-max' min='0' max='" + SLIDER_MAX + "' step='5'>" +
            "</div></div>");
        \$row.find('.sprint-limits-dot').css('background', String(cat.color || '#6c757d'));
        \$row.find('.sprint-limits-name').text(String(cat.name || ''));
        \$row.find('.sprint-limit-min').val(cat.min > 0 ? cat.min : 0);
        \$row.find('.sprint-limit-max').val(cat.max > 0 ? cat.max : 0);
        \$row.find('.sprint-range-min').val(cat.min || 0);
        \$row.find('.sprint-range-max').val(maxToSlider(cat.max || 0));
        updateFill(\$row);
        return \$row;
    }

    jQuery(document).on('click', '.sprint-limits-open', function(){
        var \$btn = jQuery(this);
        var cats  = [];
        try { cats = JSON.parse(\$btn.attr('data-limits') || '[]'); } catch (e) { cats = []; }
        var \$modal = jQuery('#sprint-limits-modal');
        \$modal.attr('data-sprint-id', \$btn.attr('data-sprint-id') || '0');
        \$modal.find('.sprint-limits-sprintname').text(\$btn.attr('data-sprint-name') || '');
        var \$rows = \$modal.find('.sprint-limits-rows').empty();
        cats.forEach(function(cat){ \$rows.append(buildRow(cat)); });
        bootstrap.Modal.getOrCreateInstance(\$modal[0]).show();
    });

    // Keep sliders, number inputs and the fill in sync; min never crosses max.
    jQuery(document).on('input', '#sprint-limits-modal .sprint-range-min', function(){
        var \$row = jQuery(this).closest('.sprint-limits-row');
        var v    = parseFloat(this.value) || 0;
        var max  = parseFloat(\$row.find('.sprint-limit-max').val()) || 0;
        if (max > 0 && v > max) { v = max; this.value = v; }
        \$row.find('.sprint-limit-min').val(v);
        updateFill(\$row);
    });
    jQuery(document).on('input', '#sprint-limits-modal .sprint-range-max', function(){
        var \$row = jQuery(this).closest('.sprint-limits-row');
        var v    = sliderToMax(parseFloat(this.value) || 0);
        var min  = parseFloat(\$row.find('.sprint-limit-min').val()) || 0;
        if (v > 0 && v < min) {
            \$row.find('.sprint-range-min').val(v);
            \$row.find('.sprint-limit-min').val(v);
        }
        \$row.find('.sprint-limit-max').val(v);
        updateFill(\$row);
    });
    jQuery(document).on('change', '#sprint-limits-modal .sprint-limit-min, #sprint-limits-modal .sprint-limit-max', function(){
        var \$row = jQuery(this).closest('.sprint-limits-row');
        var min = Math.max(0, Math.min(SLIDER_MAX, parseFloat(\$row.find('.sprint-limit-min').val()) || 0));
        var max = Math.max(0, Math.min(SLIDER_MAX, parseFloat(\$row.find('.sprint-limit-max').val()) || 0));
        if (max > 0 && min > max) {
            if (jQuery(this).hasClass('sprint-limit-min')) { max = min; } else { min = max; }
        }
        \$row.find('.sprint-limit-min').val(min);
        \$row.find('.sprint-limit-max').val(max);
        \$row.find('.sprint-range-min').val(min);
        \$row.find('.sprint-range-max').val(maxToSlider(max));
        updateFill(\$row);
    });

    // One row per customer; empty = follow their standing retainer.
    function buildCreditRow(row) {
        var \$row = jQuery(
            "<div class='sprint-credits-row' data-customer-id='" + (parseInt(row.id, 10) || 0) + "'>" +
            "<div class='sprint-credits-row-head'>" +
            "<span class='sprint-limits-dot'></span><strong class='sprint-credits-name'></strong>" +
            "<span class='sprint-credits-default text-muted sprint-small'></span>" +
            "</div>" +
            "<div class='sprint-credits-inputs'>" +
            "<label>{$lblCredits}<input type='number' min='0' step='0.25' class='form-control form-control-sm sprint-credit-value'></label>" +
            "<label>{$lblCreditsMax}<input type='number' min='0' step='0.25' class='form-control form-control-sm sprint-credit-max'></label>" +
            "</div></div>"
        );
        \$row.find('.sprint-limits-dot').css('background', String(row.color || '#6c757d'));
        \$row.find('.sprint-credits-name').text(String(row.name || ''));
        \$row.find('.sprint-credits-default').text('{$lblAgreedFmt}'.replace('%s', String(row.default || '0')));
        \$row.find('.sprint-credit-value').val(row.credits === '' ? '' : row.credits).attr('placeholder', String(row.default || '0'));
        \$row.find('.sprint-credit-max').val(row.max === '' ? '' : row.max).attr('placeholder', String(row.defaultMax || '0'));
        return \$row;
    }

    jQuery(document).on('click', '.sprint-credits-open', function(){
        var \$btn = jQuery(this);
        var rows = [];
        try { rows = JSON.parse(\$btn.attr('data-rows') || '[]'); } catch (e) { rows = []; }
        var \$modal = jQuery('#sprint-credits-modal');
        if (!\$modal.length) { return; }
        \$modal.attr('data-sprint-id', \$btn.attr('data-sprint-id') || '0');
        \$modal.find('.sprint-credits-sprintname').text(\$btn.attr('data-sprint-name') || '');
        var \$rows = \$modal.find('.sprint-credits-rows').empty();
        rows.forEach(function(row){ \$rows.append(buildCreditRow(row)); });
        bootstrap.Modal.getOrCreateInstance(\$modal[0]).show();
    });

    jQuery(document).on('click', '.sprint-credits-save', function(){
        var \$modal   = jQuery('#sprint-credits-modal');
        var sprintId = parseInt(\$modal.attr('data-sprint-id'), 10) || 0;
        if (sprintId <= 0) { return; }
        var limits = {};
        \$modal.find('.sprint-credits-row').each(function(){
            var \$row = jQuery(this);
            var id   = parseInt(\$row.attr('data-customer-id'), 10) || 0;
            if (id <= 0) { return; }
            limits[id] = {
                credits: jQuery.trim(String(\$row.find('.sprint-credit-value').val() || '')),
                max: jQuery.trim(String(\$row.find('.sprint-credit-max').val() || ''))
            };
        });
        window.sprintUi.post("{$creditUrl}", {
                    sprint_id: sprintId, limits: JSON.stringify(limits)
                }, { silent: true }).done(function(resp){
            if (resp && resp.success) {
                bootstrap.Modal.getOrCreateInstance(\$modal[0]).hide();
                if (window.sprintBacklogNotify) { window.sprintBacklogNotify('success', "{$msgDashSaved}"); }
                reloadMatrix();
            } else if (window.sprintBacklogNotify) {
                window.sprintBacklogNotify('error', (resp && resp.message) || "{$msgDashFailed}");
            }
        }).fail(function(){
            if (window.sprintBacklogNotify) { window.sprintBacklogNotify('error', "{$msgDashNet}"); }
        });
    });

    jQuery(document).on('click', '.sprint-limits-save', function(){
        var \$modal   = jQuery('#sprint-limits-modal');
        var sprintId = parseInt(\$modal.attr('data-sprint-id'), 10) || 0;
        if (sprintId <= 0) { return; }
        var limits = {};
        \$modal.find('.sprint-limits-row').each(function(){
            var \$row  = jQuery(this);
            var catId = parseInt(\$row.attr('data-category-id'), 10) || 0;
            if (catId <= 0) { return; }
            limits[catId] = {
                min: parseFloat(\$row.find('.sprint-limit-min').val()) || 0,
                max: parseFloat(\$row.find('.sprint-limit-max').val()) || 0
            };
        });
        window.sprintUi.post("{$capUrl}", {
                    sprint_id: sprintId, limits: JSON.stringify(limits)
                }, { silent: true }).done(function(resp){
            if (resp && resp.success) {
                bootstrap.Modal.getOrCreateInstance(\$modal[0]).hide();
                if (window.sprintBacklogNotify) { window.sprintBacklogNotify('success', "{$msgDashSaved}"); }
                reloadMatrix();
            } else if (window.sprintBacklogNotify) {
                window.sprintBacklogNotify('error', (resp && resp.message) || "{$msgDashFailed}");
            }
        }).fail(function(){
            if (window.sprintBacklogNotify) { window.sprintBacklogNotify('error', "{$msgDashNet}"); }
        });
    });
})();
</script>
HTML;
    }

    /**
     * Matrix fragment: credits per customer × upcoming sprints. A cell is the
     * credits on items assigned to that sprint plus the backlog items proposed
     * for it, held against the agreement in force for that sprint — the
     * recorded override, else the retainer inside its window. The trailing
     * columns hold the pipeline and the wallet.
     */
    public static function renderCreditMatrixFragment(int $horizon = 4): string
    {
        $horizon = max(1, min(26, $horizon));

        $sprints = (new Sprint())->find(
            ['status' => Sprint::STATUS_PLANNED]
                + getEntitiesRestrictCriteria(Sprint::getTable(), '', '', true),
            ['date_start ASC'],
            $horizon
        );
        $sprintIds = array_map(fn($s) => (int)$s['id'], $sprints);

        // Assigned work and proposed backlog work are kept apart: the cell
        // shows their sum, its title says how much is only a proposal.
        // sid > 0 = that sprint, 0 = backlog with no target, -1 = proposed for
        // a sprint beyond the horizon.
        $assigned = [];
        $proposed = [];
        $add      = static function (array &$cells, int $customerId, int $sid, float $credits): void {
            if ($credits == 0.0) {
                return;
            }
            $cells[$customerId][$sid] = ($cells[$customerId][$sid] ?? 0) + $credits;
        };

        foreach ((new SprintItem())->find(['plugin_sprint_sprints_id' => 0, 'is_parked' => 0]) as $row) {
            $sid = (int)($row['proposed_sprints_id'] ?? 0);
            if ($sid > 0 && !in_array($sid, $sprintIds, true)) {
                $sid = -1;
            }
            $add($proposed, (int)($row['plugin_sprint_sprintcustomers_id'] ?? 0), $sid, (float)($row['credits'] ?? 0));
        }
        if ($sprintIds) {
            foreach ((new SprintItem())->find(['plugin_sprint_sprints_id' => $sprintIds]) as $row) {
                // Same rule as the balances: parked open work claims nothing.
                if (SprintCustomer::bucketFor($row) === null) {
                    continue;
                }
                $add(
                    $assigned,
                    (int)($row['plugin_sprint_sprintcustomers_id'] ?? 0),
                    (int)$row['plugin_sprint_sprints_id'],
                    (float)($row['credits'] ?? 0)
                );
            }
        }

        $balances = SprintCustomer::balances(false);
        // Every customer with a balance or a claim, plus the unattributed row.
        // A claim on a customer outside this session's entities is dropped, so
        // the matrix never hints at another entity's customers.
        $rowIds = array_keys($balances);
        foreach (array_unique(array_merge(array_keys($assigned), array_keys($proposed))) as $cid) {
            $cid = (int)$cid;
            if (!in_array($cid, $rowIds, true) && SprintCustomer::isVisible($cid)) {
                $rowIds[] = $cid;
            }
        }
        sort($rowIds);

        ob_start();
        echo "<div class='table-responsive sprint-matrix-wrap'>";
        if (!$sprintIds) {
            echo "<div class='text-muted'>" . __('No sprints to show yet.', 'sprint') . "</div></div>";
            return (string)ob_get_clean();
        }

        echo "<table class='table table-sm mb-1 sprint-backlog-matrix sprint-credit-matrix'>";
        echo "<thead><tr><th class='sprint-matrix-rowhead'>" . SprintCustomer::getTypeName(1) . "</th>";
        $canSetLimits  = SprintCustomer::canEditCredits();
        $creditsTitle  = __s('Set the credits agreed with each customer for this sprint', 'sprint');
        $overrides     = SprintCustomer::getSprintOverrides();
        foreach ($sprints as $sp) {
            $sid = (int)$sp['id'];
            $sub = !empty($sp['date_start']) ? substr((string)$sp['date_start'], 0, 10) : '';
            echo "<th class='text-center'>" . htmlescape((string)$sp['name'])
                . ($sub !== '' ? "<div class='text-muted sprint-small fw-normal'>" . htmlescape($sub) . "</div>" : '');
            if ($canSetLimits) {
                $data = [];
                foreach ($rowIds as $cid) {
                    $cid = (int)$cid;
                    if ($cid <= 0 || !isset($balances[$cid])) {
                        continue;
                    }
                    // Only customers valid for this sprint's entity can have an
                    // agreement on it; the rest are not offered.
                    if (!SprintCustomer::isAvailableInEntity($cid, (int)($sp['entities_id'] ?? 0))) {
                        continue;
                    }
                    $over     = $overrides[$sid][$cid] ?? [];
                    // What an empty field falls back to: the retainer for
                    // *this* sprint, 0 outside its window.
                    $standing = SprintCustomer::standingAgreementFor($cid, $sid);
                    $data[]   = [
                        'id'         => $cid,
                        'name'       => (string)$balances[$cid]['name'],
                        'color'      => (string)$balances[$cid]['color'],
                        'credits'    => $over['credits'] ?? '',
                        'max'        => $over['max'] ?? '',
                        'default'    => SprintCustomer::formatCredits($standing['grant']),
                        'defaultMax' => SprintCustomer::formatCredits($standing['cap']),
                    ];
                }
                echo "<div class='sprint-matrix-headaction'><button type='button' class='sprint-cap-edit sprint-credits-open' "
                    . "title='{$creditsTitle}' data-sprint-id='{$sid}' "
                    . "data-sprint-name='" . htmlescape((string)$sp['name']) . "' "
                    . "data-rows='" . htmlescape((string)json_encode($data)) . "'>"
                    . "<i class='fas fa-sliders' aria-hidden='true'></i> " . __('Agreement', 'sprint') . "</button></div>";
            }
            echo "</th>";
        }
        echo "<th class='text-center' title='" . __s('Credits on backlog items that have no sprint yet', 'sprint') . "'>"
            . __('Unplanned', 'sprint') . "</th>";
        echo "<th class='text-center' title='" . __s('The wallet today: bought credits after what completed and running sprints drew past their agreement, corrections and lapsed lots. Below it, what open work still needs beyond the agreements.', 'sprint') . "'>"
            . __('Left', 'sprint') . "</th>";
        echo "</tr></thead><tbody>";

        $noCustomer  = __('No customer', 'sprint');
        $lblReserved = __('reserved', 'sprint');
        foreach ($rowIds as $cid) {
            $cid  = (int)$cid;
            $bal  = $balances[$cid] ?? null;
            $rowAssigned = $assigned[$cid] ?? [];
            $rowProposed = $proposed[$cid] ?? [];
            // A deactivated customer with nothing outstanding is noise.
            if (
                $bal !== null && (int)$bal['is_active'] === 0
                && !$rowAssigned && !$rowProposed && (float)$bal['purchased'] == 0.0
            ) {
                continue;
            }
            $name  = $cid > 0 ? SprintCustomer::getNameFor($cid) : $noCustomer;
            $color = $cid > 0 ? SprintCustomer::getColorFor($cid) : SprintChart::NEUTRAL;
            echo "<tr>";
            echo "<td><span class='sprint-matrix-dot' style='background:" . htmlescape($color) . ";'></span>"
                . ($name !== '' ? htmlescape($name) : '#' . $cid) . "</td>";
            foreach ($sprintIds as $sid) {
                $agreement = $cid > 0 ? SprintCustomer::agreementFor($cid, $sid) : null;
                echo "<td class='text-center'>" . self::creditCell(
                    (float)($rowAssigned[$sid] ?? 0),
                    (float)($rowProposed[$sid] ?? 0),
                    (float)($agreement['grant'] ?? 0),
                    (float)($agreement['cap'] ?? 0)
                ) . "</td>";
            }
            $unplanned = (float)($rowProposed[0] ?? 0) + (float)($rowProposed[-1] ?? 0);
            echo "<td class='text-center'>"
                . ($unplanned > 0 ? htmlescape(SprintCustomer::formatCredits($unplanned)) : "<span class='text-muted'>—</span>")
                . "</td>";
            if ($bal === null) {
                echo "<td class='text-center text-muted'>—</td>";
            } else {
                $left     = (float)$bal['wallet_balance'];
                $free     = (float)$bal['available'];
                $alert    = (float)$bal['alert'];
                $reserved = (float)$bal['wallet_reserved'];
                $tone     = $left < 0 ? 'danger' : (($free < 0 || ($alert > 0 && $free < $alert)) ? 'orange' : 'success');
                echo "<td class='text-center fw-bold sprint-tone-{$tone}'>"
                    . htmlescape(SprintCustomer::formatCredits($left));
                if ($reserved > 0) {
                    echo "<div class='sprint-small fw-normal text-muted'>" . htmlescape(SprintCustomer::formatCredits($reserved))
                        . ' ' . htmlescape($lblReserved) . "</div>";
                }
                echo "</td>";
            }
            echo "</tr>";
        }
        echo "</tbody></table>";
        echo "<div class='text-muted sprint-small'>"
            . __('A cell is the credits claimed for that sprint: work already assigned to it plus the backlog items proposed for it. The bar holds that against the credits agreed for that sprint — the recorded agreement, else the retainer inside its window.', 'sprint')
            . "</div>";
        echo "</div>";
        return (string)ob_get_clean();
    }

    /**
     * Claimed against the agreement: green inside, amber past it, red past the
     * cap. A cap without a grant still flags; a proposal is named in the title.
     */
    private static function creditCell(float $assigned, float $proposed, float $grant, float $cap): string
    {
        $claimed = $assigned + $proposed;
        if ($claimed <= 0 && $grant <= 0) {
            return "<span class='text-muted'>—</span>";
        }
        $overCap = $cap > 0 && $claimed > $cap;
        $parts   = [];
        if ($proposed > 0) {
            $parts[] = sprintf(
                __('%1$s assigned, %2$s proposed from the backlog', 'sprint'),
                SprintCustomer::formatCredits($assigned),
                SprintCustomer::formatCredits($proposed)
            );
        }
        if ($cap > 0) {
            $parts[] = sprintf(__('flagged above %s', 'sprint'), SprintCustomer::formatCredits($cap));
        }

        if ($grant <= 0) {
            $out   = "<span class='fw-bold" . ($overCap ? ' sprint-tone-danger' : '') . "' title='" . htmlescape(implode(' — ', $parts)) . "'>"
                . htmlescape(SprintCustomer::formatCredits($claimed)) . "</span>";
            if ($overCap) {
                $out .= "<div class='sprint-small sprint-tone-danger'>" . htmlescape(__('over agreed cap', 'sprint')) . "</div>";
            }
            return $out;
        }

        $tone  = $overCap ? 'danger' : ($claimed > $grant ? 'orange' : 'success');
        $pct   = min(100, round(100 * $claimed / $grant, 2));
        array_unshift($parts, sprintf(
            __('%1$s of the %2$s agreed for this sprint', 'sprint'),
            SprintCustomer::formatCredits($claimed),
            SprintCustomer::formatCredits($grant)
        ));

        $out = "<span class='fw-bold sprint-tone-{$tone}' title='" . htmlescape(implode(' — ', $parts)) . "'>"
            . htmlescape(SprintCustomer::formatCredits($claimed)) . "</span>"
            . "<span class='text-muted sprint-small'> / " . htmlescape(SprintCustomer::formatCredits($grant)) . "</span>";
        $out .= "<div class='sprint-wallet-bar mt-1'><span class='sprint-fill-{$tone}' style='width:{$pct}%;'></span></div>";
        if ($overCap) {
            $out .= "<div class='sprint-small sprint-tone-danger'>" . htmlescape(__('over agreed cap', 'sprint')) . "</div>";
        }
        return $out;
    }

    /** Matrix fragment: categories × upcoming sprints (+ unplanned + work-supply). */
    public static function renderCategoryMatrixFragment(int $horizon = 4): string
    {
        global $DB;

        $horizon    = max(1, min(26, $horizon));
        $categories = SprintCategory::getAll();

        // A parent row totals its own work plus its subcategories'.
        $childrenOf = [];
        foreach ($categories as $cid => $cat) {
            $pid = (int)($cat['plugin_sprint_sprintcategories_id'] ?? 0);
            if ((int)($cat['level'] ?? 0) > 0 && $pid > 0 && isset($categories[$pid])) {
                $childrenOf[$pid][] = (int)$cid;
            }
        }

        $sprints    = (new Sprint())->find(
            ['status' => Sprint::STATUS_PLANNED]
                + getEntitiesRestrictCriteria(Sprint::getTable(), '', '', true),
            ['date_start ASC'],
            $horizon
        );
        $sprintIds  = array_map(fn($s) => (int)$s['id'], $sprints);

        // One pass over the active backlog: capacity + counts per category,
        // split per proposed sprint (0 = unplanned). Unplanned rows also feed
        // the aging column — work nobody schedules is the mix problem you do
        // not see in the percentages.
        $alloc = $count = $totalByCat = $depAlloc = $slot = $oldest = [];
        $today = time();
        foreach ((new SprintItem())->find([
            'plugin_sprint_sprints_id' => 0, 'is_blocked' => 0, 'is_parked' => 0,
        ]) as $row) {
            $cid = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
            if ($cid > 0 && !isset($categories[$cid])) {
                $cid = 0;
            }
            $sid = (int)($row['proposed_sprints_id'] ?? 0);
            if ($sid > 0 && !in_array($sid, $sprintIds, true)) {
                $sid = -1; // proposed for a sprint outside the horizon
            }
            $cap = (float)($row['capacity'] ?? 0);
            $alloc[$cid][$sid] = ($alloc[$cid][$sid] ?? 0) + $cap;
            $count[$cid][$sid] = ($count[$cid][$sid] ?? 0) + 1;
            $totalByCat[$cid]  = ($totalByCat[$cid] ?? 0) + $cap;
            $slot[(int)$row['id']] = [$cid, $sid];
            if ($sid === 0 && !empty($row['date_creation'])) {
                $days = (int)floor(($today - strtotime((string)$row['date_creation'])) / DAY_TIMESTAMP);
                $oldest[$cid] = max($oldest[$cid] ?? 0, $days);
            }
        }

        // A dependency claims the helper's capacity in the same sprint, so it
        // belongs in the forecast under the parent item's category.
        foreach (SprintItemDependency::getCapacityByItem(array_keys($slot)) as $iid => $depCap) {
            [$cid, $sid]          = $slot[$iid];
            $alloc[$cid][$sid]    = ($alloc[$cid][$sid] ?? 0) + $depCap;
            $depAlloc[$cid][$sid] = ($depAlloc[$cid][$sid] ?? 0) + $depCap;
            $totalByCat[$cid]     = ($totalByCat[$cid] ?? 0) + $depCap;
        }

        // Work already in those sprints is off the backlog, yet it is capacity
        // the sprint no longer has to give.
        $assign = $assignCount = $fastAssigned = [];
        if ($sprintIds) {
            $assignSlot = $fastIds = [];
            foreach ((new SprintItem())->find([
                'plugin_sprint_sprints_id' => $sprintIds, 'is_parked' => 0,
            ]) as $row) {
                $cid = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
                if ($cid > 0 && !isset($categories[$cid])) {
                    $cid = 0;
                }
                $sid = (int)$row['plugin_sprint_sprints_id'];
                $assignSlot[(int)$row['id']] = [$cid, $sid];
                $assignCount[$cid][$sid]     = ($assignCount[$cid][$sid] ?? 0) + 1;
                if ((int)($row['is_fastlane'] ?? 0) === 1) {
                    $fastIds[] = (int)$row['id'];
                } else {
                    $assign[$cid][$sid] = ($assign[$cid][$sid] ?? 0) + (float)($row['capacity'] ?? 0);
                }
            }
            foreach (SprintFastlaneMember::getCapacityByItem($fastIds) as $iid => $cap) {
                [$cid, $sid]         = $assignSlot[$iid];
                $assign[$cid][$sid]  = ($assign[$cid][$sid] ?? 0) + $cap;
                $fastAssigned[$sid]  = ($fastAssigned[$sid] ?? 0) + $cap;
            }
            foreach (SprintItemDependency::getCapacityByItem(array_keys($assignSlot), false) as $iid => $cap) {
                [$cid, $sid]          = $assignSlot[$iid];
                $assign[$cid][$sid]   = ($assign[$cid][$sid] ?? 0) + $cap;
                $depAlloc[$cid][$sid] = ($depAlloc[$cid][$sid] ?? 0) + $cap;
            }
        }

        // Cell load = proposals + assigned; $totalByCat stays backlog-only,
        // the work-supply column measures the backlog.
        $load      = $alloc;
        $loadCount = $count;
        foreach ($assign as $cid => $bySid) {
            foreach ($bySid as $sid => $v) {
                $load[$cid][$sid] = ($load[$cid][$sid] ?? 0) + $v;
            }
        }
        foreach ($assignCount as $cid => $bySid) {
            foreach ($bySid as $sid => $v) {
                $loadCount[$cid][$sid] = ($loadCount[$cid][$sid] ?? 0) + $v;
            }
        }

        // Team capacity with availability exceptions applied, as everywhere else.
        $limits = $team = $teamRaw = $canCap = [];
        foreach ($sprintIds as $sid) {
            $limits[$sid]  = SprintCategory::getEffectiveLimitsForSprint($sid);
            $canCap[$sid]  = Sprint::canUpdate();
            $team[$sid]    = 0.0;
            $teamRaw[$sid] = 0.0;
            foreach ($DB->request([
                'FROM'  => SprintMember::getTable(),
                'WHERE' => ['plugin_sprint_sprints_id' => $sid],
            ]) as $m) {
                $base = SprintMember::normalizeCapacity($m['capacity_percent'] ?? 0);
                $teamRaw[$sid] += $base;
                $team[$sid]    += SprintAgility::effectiveCapacity($sid, (int)($m['users_id'] ?? 0), $base);
            }
        }

        // Work supply: capacity the team delivered per completed sprint (last
        // six), kept per sprint so the summary can show a best–worst band next
        // to the average: 30/70/40/65 and 50/52/48/51 both average out at 50,
        // but only one of them supports a promise about a single sprint.
        $histIds = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => Sprint::getTable(),
            'WHERE'  => ['status' => Sprint::STATUS_COMPLETED]
                + getEntitiesRestrictCriteria(Sprint::getTable(), '', '', true),
            'ORDER'  => ['date_end DESC'],
            'LIMIT'  => 6,
        ]) as $past) {
            $histIds[] = (int)$past['id'];
        }
        $histCount = count($histIds);
        $delivered = array_fill_keys($histIds, 0.0);
        $fastPerSprint = [];
        if ($histIds) {
            $doneSprint = [];
            foreach ($DB->request([
                'FROM'  => SprintItem::getTable(),
                'WHERE' => [
                    'plugin_sprint_sprints_id' => $histIds,
                    'status'                   => SprintItem::STATUS_DONE,
                    'is_fastlane'              => 0,
                ],
            ]) as $row) {
                $sid = (int)$row['plugin_sprint_sprints_id'];
                $delivered[$sid]          += (float)($row['capacity'] ?? 0);
                $doneSprint[(int)$row['id']] = $sid;
            }
            // Helper capacity spent on those items was real delivery too.
            foreach (SprintItemDependency::getCapacityByItem(array_keys($doneSprint), false) as $iid => $depCap) {
                $delivered[$doneSprint[$iid]] += $depCap;
            }
            foreach ($histIds as $pastId) {
                $fastPerSprint[$pastId] = SprintFastlaneMember::getTotalFastlaneCapacityForSprint($pastId);
            }
        }
        // Fastlane is interrupt work: it never sat on the backlog, so it stays
        // out of the delivery figures and becomes a reservation instead. The
        // sprint's own fastlane cap is that reservation; the historical
        // average only fills in for sprints without a cap.
        $fastlaneAvg = $histCount > 0 ? array_sum($fastPerSprint) / $histCount : 0.0;
        $fastReserve = [];
        foreach ($sprintIds as $sid) {
            $cap = (float)($sprints[$sid]['fastlane_capacity'] ?? 0);
            $fastReserve[$sid] = $cap > 0 ? $cap : $fastlaneAvg;
        }

        // The configured category minimums add up to a floor under the
        // delivery pace: the team commits at least that much per sprint,
        // however little it delivered historically. Taken from the first
        // upcoming sprint (falls back to the category defaults).
        $minLimits = $sprintIds
            ? ($limits[reset($sprintIds)] ?? [])
            : Config::getCategoryDefaultLimits();
        $minTotal = 0.0;
        foreach (array_keys($categories) as $cid) {
            $minTotal += (float)($minLimits[(int)$cid]['min'] ?? 0);
        }

        /**
         * Sprints of work the backlog represents at the team's pace: average
         * plus a best–worst band. Empty strings when there is nothing to say.
         */
        $supplyFor = function (float $backlogCap) use ($delivered, $histCount, $minTotal): array {
            if ($backlogCap <= 0 || $histCount === 0) {
                return ['', ''];
            }
            $values = array_values($delivered);
            $avg    = max(array_sum($values) / $histCount, $minTotal);
            if ($avg <= 0) {
                return ['', ''];
            }
            $main  = number_format($backlogCap / $avg, 1);
            $best  = max(max($values), $minTotal);
            $worst = max(min($values), $minTotal);
            if ($best <= 0) {
                return [$main, ''];
            }
            $low = number_format($backlogCap / $best, 1);
            // A sprint that delivered nothing makes the worst case open-ended.
            $band = $worst > 0
                ? $low . ' – ' . number_format($backlogCap / $worst, 1)
                : $low . '+';
            return [$main, $band];
        };

        $rows = $categories;
        if (!empty($totalByCat[0]) || !empty($load[0]) || !empty($assign[0])) {
            $rows[0] = ['name' => __('No category', 'sprint'), 'color' => SprintChart::NEUTRAL];
        }

        // Rolled-up display values; the raw arrays stay for the total row,
        // which would otherwise count subcategory work twice.
        $vLoad   = $load;
        $vCount  = $loadCount;
        $vDep    = $depAlloc;
        $vAssign = $assign;
        $vTotal  = $totalByCat;
        $vOldest = $oldest;
        foreach ($childrenOf as $pid => $kids) {
            foreach ($kids as $kid) {
                $vOldest[$pid] = max($vOldest[$pid] ?? 0, $oldest[$kid] ?? 0);
                foreach (($load[$kid] ?? []) as $s => $v) {
                    $vLoad[$pid][$s] = ($vLoad[$pid][$s] ?? 0) + $v;
                }
                foreach (($loadCount[$kid] ?? []) as $s => $v) {
                    $vCount[$pid][$s] = ($vCount[$pid][$s] ?? 0) + $v;
                }
                foreach (($depAlloc[$kid] ?? []) as $s => $v) {
                    $vDep[$pid][$s] = ($vDep[$pid][$s] ?? 0) + $v;
                }
                foreach (($assign[$kid] ?? []) as $s => $v) {
                    $vAssign[$pid][$s] = ($vAssign[$pid][$s] ?? 0) + $v;
                }
                $vTotal[$pid] = ($vTotal[$pid] ?? 0) + ($totalByCat[$kid] ?? 0);
            }
        }

        ob_start();
        echo "<div class='table-responsive sprint-matrix-wrap'>";
        echo "<table class='table table-sm mb-1 sprint-backlog-matrix'>";
        echo "<thead><tr><th class='sprint-matrix-rowhead'>" . __('Category', 'sprint') . "</th>";
        $limitsTitle = __s('Set min/max capacity % per category for this sprint', 'sprint');
        foreach ($sprints as $s) {
            $sid = (int)$s['id'];
            $sub = !empty($s['date_start']) ? substr((string)$s['date_start'], 0, 10) : '';
            echo "<th class='text-center'>" . htmlescape((string)$s['name'])
                . ($sub !== '' ? "<div class='text-muted sprint-small fw-normal'>{$sub}</div>" : '');
            if (!empty($canCap[$sid])) {
                // data-limits carries every category so the modal needs no fetch.
                $data = [];
                foreach ($categories as $cid => $cat) {
                    $l = $limits[$sid][(int)$cid] ?? null;
                    $data[] = [
                        'id'    => (int)$cid,
                        'name'  => (((int)($cat['level'] ?? 0) > 0) ? '— ' : '') . (string)$cat['name'],
                        'color' => (string)$cat['color'],
                        'min'   => (float)($l['min'] ?? 0),
                        'max'   => (float)($l['max'] ?? 0),
                    ];
                }
                echo "<div class='sprint-matrix-headaction'><button type='button' class='sprint-cap-edit sprint-limits-open' "
                    . "title='{$limitsTitle}' data-sprint-id='{$sid}' "
                    . "data-sprint-name='" . htmlescape((string)$s['name']) . "' "
                    . "data-limits='" . htmlescape((string)json_encode($data)) . "'>"
                    . "<i class='fas fa-sliders' aria-hidden='true'></i> " . __('Limits', 'sprint') . "</button></div>";
            }
            echo "</th>";
        }
        echo "<th class='text-center'>" . __('Not yet planned', 'sprint') . "</th></tr></thead><tbody>";

        $collapseTitle = __s('Show or hide the subcategories', 'sprint');
        $agingDays     = Config::getBacklogAgingDays();
        foreach ($rows as $cid => $cat) {
            $cid     = (int)$cid;
            $color   = htmlescape((string)$cat['color']);
            $child   = (int)($cat['level'] ?? 0) > 0;
            $parent  = (int)($cat['plugin_sprint_sprintcategories_id'] ?? 0);
            $hasKids = !empty($childrenOf[$cid]);
            $prefix  = $child ? "<i class='fas fa-turn-up fa-rotate-90 text-muted me-1 sprint-icon-sm' aria-hidden='true'></i>" : '';
            $attrs   = $child && $parent > 0
                ? " class='sprint-matrix-subrow' data-parent-cat='{$parent}'"
                : ($hasKids ? " data-cat-id='{$cid}'" : '');
            echo "<tr{$attrs}><td" . ($child ? " class='sprint-matrix-subcell'" : '') . ">";
            if ($hasKids) {
                echo "<button type='button' class='sprint-matrix-toggle' data-cat-id='{$cid}' "
                    . "aria-expanded='true' title='{$collapseTitle}' aria-label='{$collapseTitle}'><i class='fas fa-chevron-down' aria-hidden='true'></i></button>";
            }
            echo "{$prefix}<span class='sprint-swatch-sq' style='background:{$color};'></span>"
                . ($child
                    ? htmlescape((string)$cat['name'])
                    : "<strong>" . htmlescape((string)$cat['name']) . "</strong>")
                . ($hasKids
                    ? " <span class='text-muted sprint-small' title='" . __s('Totals include the subcategories', 'sprint') . "'>"
                        . sprintf(__('incl. %d sub', 'sprint'), count($childrenOf[$cid])) . "</span>"
                    : '')
                . "</td>";
            foreach ($sprintIds as $sid) {
                $a     = (float)($vLoad[$cid][$sid] ?? 0);
                $n     = (int)($vCount[$cid][$sid] ?? 0);
                $d     = (float)($vDep[$cid][$sid] ?? 0);
                $as    = (float)($vAssign[$cid][$sid] ?? 0);
                $min   = (float)($limits[$sid][$cid]['min'] ?? 0);
                $max   = (float)($limits[$sid][$cid]['max'] ?? 0);
                $over  = $max > 0 && $a > $max;
                $under = $min > 0 && $a < $min;
                echo "<td class='text-center sprint-matrix-cell" . ($over ? ' is-over' : '') . "'>";
                if ($a > 0) {
                    $tip = sprintf(_n('%d item', '%d items', $n, 'sprint'), $n);
                    if ($as > 0) {
                        $tip .= ' · ' . ($a - $as > 0.05
                            ? sprintf(
                                __('%1$s%% already assigned, %2$s%% still on the backlog', 'sprint'),
                                SprintMember::formatCapacity($as),
                                SprintMember::formatCapacity($a - $as)
                            )
                            : sprintf(__('%s%% already assigned', 'sprint'), SprintMember::formatCapacity($as)));
                    }
                    if ($d > 0) {
                        $tip .= ' · ' . sprintf(
                            __('including %s%% dependency capacity', 'sprint'),
                            SprintMember::formatCapacity($d)
                        );
                    }
                    echo "<div class='fw-bold" . ($over ? " text-danger" : '') . "' title='"
                        . htmlescape($tip) . "'>" . SprintMember::formatCapacity($a) . "%</div>";
                } else {
                    echo "<div class='text-muted'>–</div>";
                }
                // Bar scales to the cap, or to the floor when no cap is set.
                // Solid part = assigned, translucent part = backlog proposal.
                $scale = $max > 0 ? $max : max($min, $a);
                if (($min > 0 || $max > 0) && $scale > 0) {
                    $fill = $over ? 'var(--sprint-blocked, #dc3545)' : $color;
                    $pctA = round(min(100, 100 * min($as, $a) / $scale), 1);
                    $pctB = round(min(100 - $pctA, 100 * max(0, $a - $as) / $scale), 1);
                    echo "<div class='sprint-matrix-bar d-flex'>";
                    if ($pctA > 0) {
                        echo "<div style='width:{$pctA}%;background:{$fill};'></div>";
                    }
                    if ($pctB > 0) {
                        echo "<div class='sprint-matrix-seg-proposed' style='width:{$pctB}%;background:{$fill};'></div>";
                    }
                    if ($min > 0 && $min < $scale) {
                        $tick = round(100 * $min / $scale, 1);
                        echo "<span class='sprint-matrix-min-tick' style='left:{$tick}%;'></span>";
                    }
                    echo "</div>";
                }
                if ($as > 0) {
                    echo "<div class='text-muted sprint-small' title='"
                        . __s('Already assigned to this sprint', 'sprint') . "'>"
                        . "<i class='fas fa-lock me-1 sprint-icon-sm' aria-hidden='true'></i>"
                        . SprintMember::formatCapacity($as) . "%</div>";
                }
                if ($min > 0 || $max > 0) {
                    $parts = [];
                    if ($min > 0) {
                        $lbl = __('min', 'sprint') . ' ' . SprintMember::formatCapacity($min) . '%';
                        $parts[] = $under
                            ? "<span class='sprint-tone-warning fw-semibold' title='"
                                . __s('Less allocated than the minimum for this category', 'sprint') . "'>{$lbl}</span>"
                            : $lbl;
                    }
                    if ($max > 0) {
                        $lbl = __('max', 'sprint') . ' ' . SprintMember::formatCapacity($max) . '%';
                        $parts[] = $over ? "<span class='text-danger fw-semibold'>{$lbl}</span>" : $lbl;
                    }
                    echo "<div class='text-muted sprint-small'>" . implode(' · ', $parts) . "</div>";
                }
                echo "</td>";
            }
            $u  = (float)($vLoad[$cid][0] ?? 0) + (float)($vLoad[$cid][-1] ?? 0);
            $un = (int)($vCount[$cid][0] ?? 0) + (int)($vCount[$cid][-1] ?? 0);
            echo "<td class='text-center'>" . ($u > 0
                ? SprintMember::formatCapacity($u) . "% <span class='text-muted sprint-small'>(" . $un . ")</span>"
                : "<span class='text-muted'>–</span>");
            $age = (int)($vOldest[$cid] ?? 0);
            if ($agingDays > 0 && $age >= $agingDays) {
                $late = $age >= 2 * $agingDays;
                echo "<div class='sprint-small " . ($late ? 'sprint-tone-danger' : 'sprint-tone-warning') . "' title='"
                    . htmlescape(sprintf(
                        __('Oldest unplanned item in this category: %d days', 'sprint'),
                        $age
                    )) . "'><i class='fas fa-clock me-1' aria-hidden='true'></i>" . sprintf(__('%dd', 'sprint'), $age) . "</div>";
            }
            echo "</td>";
            echo "</tr>";
        }

        // Total row against each sprint's team capacity.
        echo "<tr class='fw-bold sprint-matrix-total'><td>" . __('Total', 'sprint') . "</td>";
        $grandTotal = array_sum($totalByCat);
        $unplTotal  = 0.0;
        foreach ($rows as $cid => $cat) {
            $unplTotal += (float)($alloc[(int)$cid][0] ?? 0) + (float)($alloc[(int)$cid][-1] ?? 0);
        }
        foreach ($sprintIds as $sid) {
            $t = $tAssigned = 0.0;
            foreach ($rows as $cid => $cat) {
                $t         += (float)($load[(int)$cid][$sid] ?? 0);
                $tAssigned += (float)($assign[(int)$cid][$sid] ?? 0);
            }
            // Overflow = more ambition than the linked members can take on. A
            // sprint without members counts too: 0% capacity with work planned
            // is exactly the signal the planner needs.
            $noTeam  = $team[$sid] <= 0;
            $reserve = max(0.0, (float)($fastReserve[$sid] ?? 0) - (float)($fastAssigned[$sid] ?? 0));
            $usable  = max(0.0, $team[$sid] - $reserve);
            $over    = $t > 0 && ($noTeam || $t > $usable);
            echo "<td class='text-center" . (($over && !$noTeam) ? ' sprint-matrix-cell is-over' : '') . "'>"
                . "<span" . (($over && !$noTeam) ? " class='text-danger'" : '') . ">" . SprintMember::formatCapacity($t) . "%</span>";
            if ($tAssigned > 0) {
                echo "<div class='text-muted sprint-small fw-normal' title='"
                    . __s('Already assigned to this sprint', 'sprint') . "'>"
                    . "<i class='fas fa-lock me-1 sprint-icon-sm' aria-hidden='true'></i>"
                    . SprintMember::formatCapacity($tAssigned) . "%</div>";
            }
            if ($over && !$noTeam) {
                echo "<div><span class='badge bg-red-lt' title='"
                    . __s('More work planned for this sprint than the linked members can take on', 'sprint') . "'>"
                    . "<i class='fas fa-triangle-exclamation me-1' aria-hidden='true'></i>+"
                    . SprintMember::formatCapacity($t - $usable) . "%</span></div>";
            } elseif ($over && $noTeam) {
                echo "<div><span class='badge bg-orange-lt' title='"
                    . __s('Work is planned for this sprint but no members are linked yet', 'sprint') . "'>"
                    . "<i class='fas fa-user-slash me-1' aria-hidden='true'></i>" . __('No members yet', 'sprint') . "</span></div>";
            }
            $away = $teamRaw[$sid] - $team[$sid];
            echo "<div class='text-muted sprint-small fw-normal' title='"
                . __s('Total team capacity of the selected sprint, with the availability exceptions of that sprint applied', 'sprint') . "'>"
                . __('Team', 'sprint') . " " . SprintMember::formatCapacity($team[$sid]) . "%";
            if ($away > 0.05) {
                echo " <span class='sprint-tone-warning' title='"
                    . __s('Capacity dropped by leave, training or other availability exceptions', 'sprint') . "'>"
                    . "<i class='fas fa-calendar-minus' aria-hidden='true'></i> -" . SprintMember::formatCapacity($away) . "%</span>";
            }
            echo "</div>";
            if ($reserve > 0.05 && !$noTeam) {
                $reserveTip = (float)($sprints[$sid]['fastlane_capacity'] ?? 0) > 0
                    ? __s('Reserved for interrupt work: the fastlane capacity limit of this sprint, minus what is already assigned here', 'sprint')
                    : __s('Reserved for interrupt work: no fastlane limit set on this sprint, so the average fastlane load of the completed sprints is used, minus what is already assigned here', 'sprint');
                echo "<div class='sprint-small fw-normal sprint-tone-orange' title='{$reserveTip}'>"
                    . "<i class='fas fa-bolt me-1' aria-hidden='true'></i>" . SprintMember::formatCapacity($reserve) . "%</div>";
            }
            if (!$noTeam) {
                $free = $usable - $t;
                echo "<div class='sprint-small fw-normal " . ($free < 0 ? 'sprint-tone-danger' : 'sprint-tone-success') . "' title='"
                    . __s('Team capacity left after the assigned work, the backlog proposals and the fastlane reservation', 'sprint') . "'>"
                    . sprintf(__('free %s%%', 'sprint'), SprintMember::formatCapacity($free)) . "</div>";
            }
            echo "</td>";
        }
        echo "<td class='text-center'>" . SprintMember::formatCapacity($unplTotal) . "%</td>";
        echo "</tr>";
        echo "</tbody></table>";
        if ($assign) {
            echo "<div class='text-muted sprint-small sprint-matrix-note'>"
                . __('Solid bar = already assigned to the sprint, faded = still a backlog proposal.', 'sprint') . "</div>";
        }
        // Work supply: how many sprints the whole backlog represents at the
        // team's delivery pace. One line for the total only — per-category
        // figures explode for categories that rarely deliver.
        [$supply, $band] = $supplyFor($grandTotal);
        if ($histCount > 0) {
            echo "<div class='text-muted sprint-small sprint-matrix-note is-last'>";
            if ($supply !== '') {
                echo "<span title='"
                    . __s('Backlog capacity divided by the capacity the team delivered per sprint, never less than the configured category minimums.', 'sprint') . "'>"
                    . htmlescape(sprintf(
                        __('Work supply: about %1$s sprints at the current pace, based on the last %2$d completed sprints.', 'sprint'),
                        $supply,
                        $histCount
                    ))
                    . ($band !== '' ? ' ' . htmlescape(sprintf(__('Best to worst of those sprints: %s.', 'sprint'), $band)) : '')
                    . "</span>";
            }
            if ($fastlaneAvg > 0.05) {
                echo ' ' . sprintf(
                    __('Those sprints spent an average of %s%% on fastlane work. That average is reserved up front on sprints without a fastlane limit of their own.', 'sprint'),
                    SprintMember::formatCapacity($fastlaneAvg)
                );
            }
            if ($minTotal > 0) {
                echo ' ' . __('Configured category minimums act as a floor under the delivery pace.', 'sprint');
            }
            echo "</div>";
        }
        echo "</div>";
        return (string)ob_get_clean();
    }

    /**
     * Members × sprints: each member's capacity split by top-level category,
     * over the upcoming planned sprints (same window as the category view).
     * The category limits of a sprint are read as a share of the team
     * capacity and held against each member's own share, so "four sprints
     * too much internal work" shows as a count. Realised work per member sits
     * on the sprint overview, not here.
     */
    public static function renderMemberMatrixFragment(int $horizon = 4): string
    {
        global $DB;

        $horizon    = max(1, min(26, $horizon));
        $categories = SprintCategory::getAll();

        // Every category rolls up to its top-level ancestor; that is the
        // level the limits live on and the level a stacked bar can still show.
        $topOf = [];
        foreach ($categories as $cid => $cat) {
            $cid = (int)$cid;
            $pid = (int)($cat['plugin_sprint_sprintcategories_id'] ?? 0);
            $topOf[$cid] = ((int)($cat['level'] ?? 0) > 0 && $pid > 0 && isset($categories[$pid]))
                ? ($topOf[$pid] ?? $pid)
                : $cid;
        }
        $topCats = array_filter($categories, fn($c) => (int)($c['level'] ?? 0) === 0);
        $entity  = getEntitiesRestrictCriteria(Sprint::getTable(), '', '', true);

        $sprints = (new Sprint())->find(
            ['status' => Sprint::STATUS_PLANNED] + $entity,
            ['date_start ASC'],
            $horizon
        );
        $sprintIds   = array_map('intval', array_keys($sprints));
        $futureIds   = $sprintIds;
        $firstFuture = $sprintIds[0] ?? 0;

        // Member capacity per sprint, availability exceptions applied.
        $avail = $team = [];
        if ($sprintIds) {
            foreach ($DB->request([
                'FROM'  => SprintMember::getTable(),
                'WHERE' => ['plugin_sprint_sprints_id' => $sprintIds],
            ]) as $m) {
                $sid  = (int)$m['plugin_sprint_sprints_id'];
                $uid  = (int)($m['users_id'] ?? 0);
                $base = SprintMember::normalizeCapacity($m['capacity_percent'] ?? 0);
                $eff  = SprintAgility::effectiveCapacity($sid, $uid, $base);
                $avail[$sid][$uid] = ($avail[$sid][$uid] ?? 0) + $eff;
                $team[$sid]        = ($team[$sid] ?? 0) + $eff;
            }
        }

        // cell[sid][uid][topCat] = assigned + proposed capacity; prop[sid][uid][topCat]
        // = the part that is still a backlog proposal. uid 0 collects
        // proposals without owner.
        $cell = $prop = [];
        $add = function (int $sid, int $uid, int $cid, float $cap, bool $proposal = false) use (&$cell, &$prop, $topOf, $categories) {
            if ($cap <= 0) {
                return;
            }
            $top = ($cid > 0 && isset($categories[$cid])) ? ($topOf[$cid] ?? $cid) : 0;
            $cell[$sid][$uid][$top] = ($cell[$sid][$uid][$top] ?? 0) + $cap;
            if ($proposal) {
                $prop[$sid][$uid][$top] = ($prop[$sid][$uid][$top] ?? 0) + $cap;
            }
        };

        if ($sprintIds) {
            // Assigned work counts exactly like SprintMember::usedCapacityBySprint()
            // (every item in the sprint, fastlane via its members, all help),
            // so a cell reconciles with the sprint's team view.
            $slot = $fastIds = [];
            foreach ((new SprintItem())->find([
                'plugin_sprint_sprints_id' => $sprintIds,
            ]) as $row) {
                $iid = (int)$row['id'];
                $sid = (int)$row['plugin_sprint_sprints_id'];
                $cid = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
                $slot[$iid] = [$sid, $cid];
                if ((int)($row['is_fastlane'] ?? 0) === 1) {
                    $fastIds[] = $iid;
                } else {
                    $add($sid, (int)($row['users_id'] ?? 0), $cid, (float)($row['capacity'] ?? 0));
                }
            }
            if ($fastIds) {
                foreach ((new SprintFastlaneMember())->find(['plugin_sprint_sprintitems_id' => $fastIds]) as $r) {
                    [$sid, $cid] = $slot[(int)$r['plugin_sprint_sprintitems_id']];
                    $add($sid, (int)$r['users_id'], $cid, (float)$r['capacity']);
                }
            }
            if ($slot && SprintItemDependency::isTableReady()) {
                foreach ((new SprintItemDependency())->find(['plugin_sprint_sprintitems_id' => array_keys($slot)]) as $r) {
                    [$sid, $cid] = $slot[(int)$r['plugin_sprint_sprintitems_id']];
                    $add($sid, (int)$r['users_id'], $cid, (float)$r['capacity']);
                }
            }
        }
        // Backlog proposals for the upcoming sprints, on the same rule as the
        // edit-dialog preview and the team cards (SprintMember::proposedBacklogCriteria()).
        if ($futureIds) {
            $pslot = [];
            foreach ((new SprintItem())->find(SprintMember::proposedBacklogCriteria($futureIds)) as $row) {
                $sid = (int)$row['proposed_sprints_id'];
                $cid = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
                $pslot[(int)$row['id']] = [$sid, $cid];
                // A fastlane row spreads its load over members only once in a sprint.
                if ((int)($row['is_fastlane'] ?? 0) === 0) {
                    $add($sid, (int)($row['users_id'] ?? 0), $cid, (float)($row['capacity'] ?? 0), true);
                }
            }
            if ($pslot && SprintItemDependency::isTableReady()) {
                foreach ((new SprintItemDependency())->find([
                    'plugin_sprint_sprintitems_id' => array_keys($pslot), 'is_resolved' => 0,
                ]) as $r) {
                    [$sid, $cid] = $pslot[(int)$r['plugin_sprint_sprintitems_id']];
                    $add($sid, (int)$r['users_id'], $cid, (float)$r['capacity'], true);
                }
            }
        }

        // Rows: every member of any shown sprint plus anyone holding work.
        $userIds = [];
        foreach ($avail as $byUser) {
            foreach ($byUser as $uid => $v) {
                $userIds[$uid] = true;
            }
        }
        foreach ($cell as $byUser) {
            foreach ($byUser as $uid => $v) {
                $userIds[$uid] = true;
            }
        }
        $hasUnowned = isset($userIds[0]);
        unset($userIds[0]);
        $names = [];
        foreach (array_keys($userIds) as $uid) {
            $names[$uid] = SprintCache::userName((int)$uid);
        }
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        // Limits as a share of the team: min 150% on a 385% team means every
        // member is expected to spend ~39% of their own capacity there.
        $limits = [];
        foreach ($sprintIds as $sid) {
            $limits[$sid] = SprintCategory::getEffectiveLimitsForSprint($sid);
        }
        $shareLimit = function (int $sid, int $top, string $key) use ($limits, $team): float {
            $lim  = (float)($limits[$sid][$top][$key] ?? 0);
            $tcap = (float)($team[$sid] ?? 0);
            return ($lim > 0 && $tcap > 0) ? $lim / $tcap : 0.0;
        };

        // Signals per member: how many of the shown sprints exceed the max or
        // fall short of the min share of a category. Sprints without a
        // capacity for that member do not count.
        $overCnt = $underCnt = [];
        foreach ($names as $uid => $n) {
            foreach ($sprintIds as $sid) {
                $cap = (float)($avail[$sid][$uid] ?? 0);
                if ($cap <= 0) {
                    continue;
                }
                foreach ($topCats as $top => $c) {
                    $top   = (int)$top;
                    $share = (float)($cell[$sid][$uid][$top] ?? 0) / $cap;
                    $maxS  = $shareLimit($sid, $top, 'max');
                    $minS  = $shareLimit($sid, $top, 'min');
                    if ($maxS > 0 && $share > $maxS + 0.005) {
                        $overCnt[$uid][$top] = ($overCnt[$uid][$top] ?? 0) + 1;
                    }
                    if ($minS > 0 && $share < $minS - 0.005) {
                        $underCnt[$uid][$top] = ($underCnt[$uid][$top] ?? 0) + 1;
                    }
                }
            }
        }

        $catName  = fn(int $top) => $top > 0 ? (string)($categories[$top]['name'] ?? '') : __('No category', 'sprint');
        $catColor = fn(int $top) => $top > 0 ? (string)($categories[$top]['color'] ?? SprintChart::NEUTRAL) : SprintChart::NEUTRAL;
        $fmt      = fn($v) => SprintMember::formatCapacity($v);
        $lblOver  = __s('Above the maximum share for this category', 'sprint');
        $lblUnder = __s('Below the minimum share for this category', 'sprint');

        /**
         * One member/team cell on the shared capacity model: assigned work
         * (solid, per category) then backlog proposals (hatched, per
         * category), against the available capacity in percentage points.
         * Overload is an overlay past the capacity tick, never extra width.
         */
        $renderCell = function (int $sid, array $byCat, array $propByCat, float $cap, bool $isTeam, string $who) use ($topCats, $catName, $catColor, $fmt, $shareLimit, $limits, $lblOver, $lblUnder, $firstFuture, $sprints): string {
            $nowCls = $sid === $firstFuture ? ' sprint-member-now' : '';
            $sum = array_sum($byCat);
            if ($sum <= 0 && $cap <= 0) {
                return "<td class='text-center sprint-matrix-cell{$nowCls}'><div class='text-muted'>–</div></td>";
            }
            $proposal = (float)array_sum($propByCat);
            $assigned = max($sum - $proposal, 0.0);
            $f     = SprintMember::capacityFigures($cap, $cap, $assigned, 0.0, 0.0, 0.0, $proposal);
            $state = $f['state_after_proposals'];
            $over  = $state === SprintCapacityMath::STATE_OVER;
            $tip   = [];
            $tip[] = $cap > 0
                ? sprintf(__('Assigned %1$s, proposed %2$s of %3$s available', 'sprint'), $fmt($assigned) . '%', $fmt($proposal) . '%', $fmt($cap) . '%')
                : sprintf(__('%s%% planned, no capacity in this sprint', 'sprint'), $fmt($sum));
            $order = array_keys($topCats);
            $order[] = 0;
            $flags = [];
            foreach ($order as $top) {
                $top = (int)$top;
                $v   = (float)($byCat[$top] ?? 0);
                $pv  = (float)($propByCat[$top] ?? 0);
                $maxS = $isTeam ? (float)($limits[$sid][$top]['max'] ?? 0) : $shareLimit($sid, $top, 'max');
                $minS = $isTeam ? (float)($limits[$sid][$top]['min'] ?? 0) : $shareLimit($sid, $top, 'min');
                $share = $isTeam ? $v : ($cap > 0 ? $v / $cap : 0.0);
                if ($v > 0) {
                    $tip[] = $catName($top) . ': ' . $fmt($v) . '%'
                        . ($pv > 0 ? ' (' . sprintf(__('of which %s%% still a backlog proposal', 'sprint'), $fmt($pv)) . ')' : '');
                }
                if ($cap > 0 || $isTeam) {
                    if ($maxS > 0 && $share > $maxS + ($isTeam ? 0.05 : 0.005)) {
                        $flags[] = "<span class='sprint-member-flag sprint-tone-danger' title='{$lblOver}: "
                            . htmlescape($catName($top)) . "'><i class='fas fa-arrow-up' aria-hidden='true'></i>" . htmlescape($catName($top)) . "</span>";
                    } elseif ($minS > 0 && $share < $minS - ($isTeam ? 0.05 : 0.005)) {
                        $flags[] = "<span class='sprint-member-flag sprint-tone-warning' title='{$lblUnder}: "
                            . htmlescape($catName($top)) . "'><i class='fas fa-arrow-down' aria-hidden='true'></i>" . htmlescape($catName($top)) . "</span>";
                    }
                }
            }
            if ($cap > 0) {
                $tip[] = SprintMember::remainingText($f, $proposal > 0);
            }
            $sprintName = (string)($sprints[$sid]['name'] ?? '');
            $desc = ($sprintName !== ''
                    ? sprintf(__('Capacity of %1$s in %2$s', 'sprint'), $who, $sprintName)
                    : sprintf(__('Capacity of %s', 'sprint'), $who))
                . ' — ' . implode(', ', $tip);

            $out = "<td class='text-center sprint-matrix-cell{$nowCls}" . ($over && $cap > 0 ? ' is-over' : '') . "' title='" . htmlescape(implode("\n", $tip)) . "'>";
            // Main figure: load against available, both in percentage points.
            $out .= "<div class='fw-bold" . ($over ? ' text-danger' : '') . "'>"
                . ($cap > 0 ? $fmt($sum) . ' / ' . $fmt($cap) . '%' : $fmt($sum) . '%') . "</div>";
            $scale = max($cap, $sum);
            if ($sum > 0 && $scale > 0) {
                $out .= "<div class='sprint-matrix-bar sprint-member-bar' role='img' aria-label='" . htmlescape($desc) . "'>";
                foreach ([false, true] as $proposed) {
                    foreach ($order as $top) {
                        $v = $proposed
                            ? (float)($propByCat[(int)$top] ?? 0)
                            : (float)($byCat[(int)$top] ?? 0) - (float)($propByCat[(int)$top] ?? 0);
                        if ($v <= 0.001) {
                            continue;
                        }
                        $w = round(100 * $v / $scale, 1);
                        $c = htmlescape($catColor((int)$top));
                        $out .= $proposed
                            ? "<div class='sprint-member-seg-proposed' style='width:{$w}%;--sprint-seg-color:{$c};'></div>"
                            : "<div style='width:{$w}%;background:{$c};'></div>";
                    }
                }
                if ($cap > 0 && $sum > $cap) {
                    $tick = round(100 * $cap / $scale, 1);
                    $ow   = round(100 - $tick, 1);
                    $out .= "<span class='sprint-member-seg-over' style='left:{$tick}%;width:{$ow}%;'></span>";
                    $out .= "<span class='sprint-matrix-min-tick is-over' style='left:{$tick}%;'></span>";
                }
                $out .= "</div>";
            }
            if ($proposal > 0.05 && $cap > 0) {
                $out .= "<div class='text-muted sprint-small'>"
                    . htmlescape(sprintf(__('%1$s assigned + %2$s proposed', 'sprint'), $fmt($assigned) . '%', $fmt($proposal) . '%'))
                    . "</div>";
            }
            if ($cap > 0 && $sum > 0) {
                $out .= "<div class='sprint-small" . ($over ? ' sprint-cap-over' : ' text-muted') . "'>"
                    . htmlescape(SprintMember::remainingText($f, $proposal > 0)) . "</div>";
                if (in_array($state, [SprintCapacityMath::STATE_ALMOST_FULL, SprintCapacityMath::STATE_FULL], true)) {
                    $out .= "<div>" . SprintMember::capacityStateBadge($state, $f) . "</div>";
                }
            }
            if ($flags) {
                $out .= "<div class='sprint-member-flags'>" . implode(' ', $flags) . "</div>";
            }
            return $out . "</td>";
        };

        ob_start();
        echo "<div class='table-responsive sprint-matrix-wrap'>";
        if (!$sprintIds) {
            echo "<div class='text-muted'>" . __('No sprints to show yet.', 'sprint') . "</div></div>";
            return (string)ob_get_clean();
        }
        echo "<table class='table table-sm mb-1 sprint-backlog-matrix sprint-member-matrix'>";
        echo "<thead><tr><th class='sprint-matrix-rowhead'>" . __('Member', 'sprint') . "</th>";
        foreach ($sprints as $sid => $s) {
            $sid = (int)$sid;
            $sub = !empty($s['date_start']) ? substr((string)$s['date_start'], 0, 10) : '';
            $cls = 'text-center' . ($sid === $firstFuture ? ' sprint-member-now' : '');
            echo "<th class='{$cls}'>" . htmlescape((string)$s['name'])
                . ($sub !== '' ? "<div class='text-muted sprint-small fw-normal'>{$sub}</div>" : '')
                . "</th>";
        }
        echo "<th class='text-center' title='" . __s('Sprints in this window where the member sits above the maximum or below the minimum share of a category', 'sprint') . "'>"
            . __('Signals', 'sprint') . "</th></tr></thead><tbody>";

        $shown = count($sprintIds);
        foreach ($names as $uid => $name) {
            $uid = (int)$uid;
            echo "<tr><td><i class='fas fa-user text-muted me-1 sprint-icon-sm' aria-hidden='true'></i>" . htmlescape($name) . "</td>";
            foreach ($sprintIds as $sid) {
                echo $renderCell($sid, $cell[$sid][$uid] ?? [], $prop[$sid][$uid] ?? [], (float)($avail[$sid][$uid] ?? 0), false, $name);
            }
            $sig = [];
            foreach ($topCats as $top => $c) {
                $top = (int)$top;
                $o   = (int)($overCnt[$uid][$top] ?? 0);
                $u   = (int)($underCnt[$uid][$top] ?? 0);
                if ($o === 0 && $u === 0) {
                    continue;
                }
                $dot = "<span class='sprint-swatch-sq is-small' style='background:" . htmlescape($catColor($top)) . ";'></span>";
                $parts = [];
                if ($o > 0) {
                    $parts[] = "<span class='sprint-tone-danger' title='" . htmlescape(sprintf(__('%1$d of %2$d sprints above the maximum share', 'sprint'), $o, $shown)) . "'>"
                        . "<i class='fas fa-arrow-up' aria-hidden='true'></i> {$o}×</span>";
                }
                if ($u > 0) {
                    $parts[] = "<span class='sprint-tone-warning' title='" . htmlescape(sprintf(__('%1$d of %2$d sprints below the minimum share', 'sprint'), $u, $shown)) . "'>"
                        . "<i class='fas fa-arrow-down' aria-hidden='true'></i> {$u}×</span>";
                }
                $sig[] = "<div class='sprint-small text-nowrap'>{$dot}" . htmlescape($catName($top)) . " " . implode(' ', $parts) . "</div>";
            }
            echo "<td class='text-start'>" . ($sig ? implode('', $sig) : "<span class='text-muted'>–</span>") . "</td>";
            echo "</tr>";
        }
        if ($hasUnowned) {
            echo "<tr><td class='text-muted'><i class='fas fa-user-slash me-1 sprint-icon-sm' aria-hidden='true'></i>" . __('No owner yet', 'sprint') . "</td>";
            foreach ($sprintIds as $sid) {
                echo $renderCell($sid, $cell[$sid][0] ?? [], $prop[$sid][0] ?? [], 0.0, false, __('No owner yet', 'sprint'));
            }
            echo "<td></td></tr>";
        }

        // Team row: the same stacked bar for the whole sprint, flags against
        // the absolute limits as in the category view.
        echo "<tr class='fw-bold sprint-matrix-total'><td>" . __('Team', 'sprint') . "</td>";
        foreach ($sprintIds as $sid) {
            $byCat = $tprop = [];
            foreach (($cell[$sid] ?? []) as $uid => $cats) {
                foreach ($cats as $top => $v) {
                    $byCat[$top] = ($byCat[$top] ?? 0) + $v;
                }
            }
            foreach (($prop[$sid] ?? []) as $uid => $cats) {
                foreach ($cats as $top => $v) {
                    $tprop[$top] = ($tprop[$top] ?? 0) + $v;
                }
            }
            echo $renderCell($sid, $byCat, $tprop, (float)($team[$sid] ?? 0), true, __('Team', 'sprint'));
        }
        echo "<td></td></tr>";
        echo "</tbody></table>";

        // Legend + reading aid.
        echo "<div class='text-muted sprint-small sprint-matrix-note sprint-matrix-key'>";
        foreach ($topCats as $top => $c) {
            echo "<span><span class='sprint-swatch-sq' style='background:" . htmlescape((string)$c['color']) . ";' aria-hidden='true'></span>"
                . htmlescape((string)$c['name']) . "</span>";
        }
        echo "<span><span class='sprint-swatch-sq is-neutral' aria-hidden='true'></span>"
            . __('No category', 'sprint') . "</span>";
        echo "<span><span class='sprint-swatch-sq is-proposed' aria-hidden='true'></span>"
            . __('Hatched: backlog proposal, not assigned yet', 'sprint') . "</span>";
        echo "<span><span class='sprint-swatch-sq is-over' aria-hidden='true'></span>"
            . __('Over capacity', 'sprint') . "</span>";
        echo "</div>";
        echo "<div class='text-muted sprint-small sprint-matrix-note is-last'>"
            . htmlescape(sprintf(
                __('Bar = assigned work per category (solid), then backlog proposals (hatched), against the member\'s available capacity. Figures are percentage points of a full sprint: assigned work matches the sprint\'s team capacity view, remaining is shown after proposals, almost full means less than %s%% remains. The arrows compare the member\'s share with the sprint\'s category limits, read as a share of the team capacity.', 'sprint'),
                SprintMember::formatCapacity(SprintCapacityMath::ALMOST_FULL_PP)
            ))
            . ' ' . sprintf(
                __('Window: the next %d planned sprints. Realised work per member is on the sprint overview.', 'sprint'),
                count($sprints)
            )
            . "</div>";
        echo "</div>";
        return (string)ob_get_clean();
    }

    /** Record a backlog flow event; survives item deletion. */
    public static function logFlow(array $itemFields, string $direction): void
    {
        global $DB;
        if (!in_array($direction, ['in', 'out'], true)
            || !$DB->tableExists('glpi_plugin_sprint_backlogflow')) {
            return;
        }
        $DB->insert('glpi_plugin_sprint_backlogflow', [
            'date'                              => date('Y-m-d'),
            'plugin_sprint_sprintcategories_id' => (int)($itemFields['plugin_sprint_sprintcategories_id'] ?? 0),
            'direction'                         => $direction,
            'capacity'                          => (float)($itemFields['capacity'] ?? 0),
        ]);
    }

    /** Inflow vs outflow card: last 12 weeks, optional category filter. */
    private static function renderFlowCard(): void
    {
        $flowUrl = Plugin::getWebDir('sprint') . '/ajax/backlogflow.php';

        $picker = "<select class='form-select form-select-sm sprint-backlog-flow-cat sprint-mw-220' aria-label='" . __s('Category', 'sprint') . "'>"
            . "<option value='0'>" . __('All categories', 'sprint') . "</option>"
            . SprintCategory::dropdownOptions()
            . "</select>";

        echo "<div class='card sprint-backlog-card sprint-backlog-flow'><div class='card-body'>";
        echo SprintUi::cardHeader(
            'fas fa-arrow-right-arrow-left',
            __('Backlog inflow vs outflow', 'sprint'),
            __('New backlog work versus work assigned or resolved, per week.', 'sprint'),
            $picker
        );
        echo "<div class='sprint-backlog-flow-body' aria-live='polite'>" . self::renderFlowChartFragment(0) . "</div>";
        echo "</div></div>";

        echo <<<HTML
<script>
(function(){
    if (typeof jQuery === 'undefined' || window.__sprintBacklogFlowBound) { return; }
    window.__sprintBacklogFlowBound = true;
    jQuery(document).on('change', '.sprint-backlog-flow-cat', function(){
        var catId = parseInt(this.value, 10) || 0;
        var \$body = jQuery(this).closest('.sprint-backlog-flow').find('.sprint-backlog-flow-body');
        \$body.addClass('sprint-is-loading');
        jQuery.ajax({
            url: "{$flowUrl}", type: 'GET', dataType: 'json', cache: false,
            data: { category_id: catId }
        }).done(function(resp){
            if (resp && resp.success) { \$body.html(resp.html); }
        }).always(function(){ \$body.removeClass('sprint-is-loading'); });
    });
})();
</script>
HTML;
    }

    /**
     * Weekly backlog flow of the last 12 weeks as one SVG chart: grouped bars
     * for the capacity that came in and went out, and the net change as a
     * line. A week without flow draws no bar. Built on SprintChart, so the
     * scale, legend, tooltip and colours match the other charts.
     */
    public static function renderFlowChartFragment(int $categoryId = 0): string
    {
        global $DB;

        $weeks = [];
        $monday = new \DateTime('monday this week');
        for ($i = 11; $i >= 0; $i--) {
            $w = (clone $monday)->modify("-{$i} weeks");
            $weeks[$w->format('Y-m-d')] = [
                'label' => $w->format('\WW'),
                'from'  => $w->format('d/m'),
                'to'    => (clone $w)->modify('+6 days')->format('d/m'),
                'in'    => 0.0, 'out' => 0.0, 'in_n' => 0, 'out_n' => 0,
            ];
        }
        $firstMonday = array_key_first($weeks);

        if ($DB->tableExists('glpi_plugin_sprint_backlogflow')) {
            $where = [['date' => ['>=', $firstMonday]]];
            if ($categoryId > 0) {
                $where['plugin_sprint_sprintcategories_id'] = $categoryId;
            }
            foreach ($DB->request(['FROM' => 'glpi_plugin_sprint_backlogflow', 'WHERE' => $where]) as $row) {
                $day = new \DateTime((string)$row['date']);
                $key = $day->modify('monday this week')->format('Y-m-d');
                $dir = (string)$row['direction'];
                if (!isset($weeks[$key]) || !in_array($dir, ['in', 'out'], true)) {
                    continue;
                }
                $weeks[$key][$dir]        += (float)$row['capacity'];
                $weeks[$key][$dir . '_n'] += 1;
            }
        }

        return self::renderFlowChart(array_values($weeks));
    }

    /**
     * The chart of renderFlowChartFragment(), split off so it renders from
     * plain data.
     *
     * @param array<int,array{label:string,from:string,to:string,in:float,out:float,in_n:int,out_n:int}> $weeks oldest first
     */
    public static function renderFlowChart(array $weeks): string
    {
        $maxUp   = 0.0;
        $maxDown = 0.0;
        $sumIn   = 0.0;
        $sumOut  = 0.0;
        $events  = 0;
        foreach ($weeks as $w) {
            $net     = $w['in'] - $w['out'];
            $maxUp   = max($maxUp, $w['in'], $w['out']);
            $maxDown = max($maxDown, -$net);
            $sumIn  += $w['in'];
            $sumOut += $w['out'];
            $events += $w['in_n'] + $w['out_n'];
        }
        if ($events === 0) {
            return SprintChart::emptyState(
                'fas fa-arrow-right-arrow-left',
                __('No backlog flow in the last 12 weeks', 'sprint'),
                __('The chart fills as soon as items are added to the backlog, assigned to a sprint or resolved. Pick another category to compare.', 'sprint')
            );
        }

        $pct      = static fn(float $v): string => SprintMember::formatCapacity($v) . '%';
        $signed   = static fn(float $v): string => ($v > 0 ? '+' : ($v < 0 ? '-' : '')) . SprintMember::formatCapacity(abs($v)) . '%';
        $num      = [SprintChart::class, 'num'];
        $colorIn  = SprintChart::seriesColor(0);
        $colorOut = SprintChart::seriesColor(1);
        $colorNet = 'var(--tblr-body-color, #1f2937)';
        $lblIn    = __('Inflow (new on backlog)', 'sprint');
        $lblOut   = __('Outflow (assigned or resolved)', 'sprint');
        $lblNet   = __('Net change', 'sprint');

        // The scale covers the bars above zero. A week in which more left than
        // came in pulls the net line below zero, so the axis continues down in
        // the same steps.
        $scale    = SprintChart::niceScale($maxUp, 4, true);
        $step     = (float)$scale['step'];
        $negSteps = $maxDown > 0 ? (int)ceil(round($maxDown / $step, 9)) : 0;

        $w     = 1000;
        $h     = 280;
        $padL  = 48;
        $padR  = 14;
        $padT  = 26;
        $count = max(1, count($weeks));
        $slot  = ($w - $padL - $padR) / $count;
        $axis  = SprintChart::axisLabels(array_map(static fn($wk) => $wk['label'] . ' - ' . $wk['from'], $weeks), $slot);
        $yEnd  = $h - $axis['height'] - 6;
        $unit  = ($yEnd - $padT) / ((float)$scale['max'] + $negSteps * $step);
        $y0    = $padT + (float)$scale['max'] * $unit;
        $yOf   = static fn(float $v): float => $y0 - $v * $unit;
        $barW  = min(26.0, max(8.0, ($slot - 14) / 2));
        $gap   = 2.0;

        $netTotal = $sumIn - $sumOut;
        $aria = sprintf(
            __('Backlog inflow versus outflow per week over the last %1$d weeks. %2$s came in and %3$s went out, a net change of %4$s.', 'sprint'),
            $count,
            $pct($sumIn),
            $pct($sumOut),
            $signed($netTotal)
        );

        $legend = SprintChart::legend([
            ['label' => $lblIn, 'color' => $colorIn, 'mark' => 'bar', 'key' => 'in', 'value' => $pct($sumIn)],
            ['label' => $lblOut, 'color' => $colorOut, 'mark' => 'bar', 'key' => 'out', 'value' => $pct($sumOut)],
            ['label' => $lblNet, 'color' => $colorNet, 'mark' => 'line', 'shape' => 0, 'key' => 'net', 'value' => $signed($netTotal)],
        ], true);

        $bar = static function (float $x, float $value, string $color, string $key, string $text) use ($yOf, $y0, $barW, $num): string {
            if ($value <= 0) {
                return '';
            }
            // Never thinner than 2 units: a small value still reads as a bar, a zero draws nothing.
            $top = min($yOf($value), $y0 - 2);
            $hPx = $y0 - $top;
            $r   = min(3.0, $hPx / 2, $barW / 2);
            $d   = 'M' . $num($x) . ',' . $num($y0)
                . ' V' . $num($top + $r)
                . ' a' . $num($r) . ',' . $num($r) . ' 0 0 1 ' . $num($r) . ',-' . $num($r)
                . ' h' . $num($barW - 2 * $r)
                . ' a' . $num($r) . ',' . $num($r) . ' 0 0 1 ' . $num($r) . ',' . $num($r)
                . ' V' . $num($y0) . ' z';
            return "<g class='sprint-chart-series' data-series='{$key}'>"
                . "<path d='{$d}' fill='" . htmlescape($color) . "'/>"
                . "<text class='sprint-chart-value' x='" . $num($x + $barW / 2) . "' y='" . $num($top - 4) . "' text-anchor='middle'>"
                . htmlescape($text) . "</text></g>";
        };

        ob_start();
        echo "<div class='sprint-flow-chart' data-chart-scope>";
        echo $legend;
        echo "<div class='sprint-chart-box'>";
        echo SprintChart::svgOpen($aria, $w, $h, 'sprint-flow-svg');
        echo SprintChart::yAxis($scale, $padL, $w - $padR, $padT, $y0, $pct);
        for ($n = 1; $n <= $negSteps; $n++) {
            $y = $yOf(-$n * $step);
            echo "<line class='sprint-chart-grid' x1='" . $num($padL) . "' y1='" . $num($y) . "' x2='" . $num($w - $padR) . "' y2='" . $num($y) . "'/>"
                . "<text class='sprint-chart-label' x='" . $num($padL - 8) . "' y='" . $num($y + 3.5) . "' text-anchor='end'>"
                . htmlescape('-' . $pct($n * $step)) . "</text>";
        }

        $points = [];
        foreach ($weeks as $i => $wk) {
            $left = $padL + $i * $slot;
            $cx   = $left + $slot / 2;
            $net  = $wk['in'] - $wk['out'];
            $points[] = [$cx, $yOf($net), $net];

            $title = sprintf(__('Week %1$s (%2$s to %3$s)', 'sprint'), ltrim($wk['label'], 'W'), $wk['from'], $wk['to']);
            $rows  = [
                [$lblIn, $pct($wk['in']) . ' · ' . sprintf(_n('%d item', '%d items', $wk['in_n'], 'sprint'), $wk['in_n']), $colorIn],
                [$lblOut, $pct($wk['out']) . ' · ' . sprintf(_n('%d item', '%d items', $wk['out_n'], 'sprint'), $wk['out_n']), $colorOut],
                [$lblNet, $signed($net), $colorNet],
            ];
            $plain = $title . ': ' . sprintf(
                __('In: %1$s%% (%2$d items) · Out: %3$s%% (%4$d items)', 'sprint'),
                SprintMember::formatCapacity($wk['in']),
                $wk['in_n'],
                SprintMember::formatCapacity($wk['out']),
                $wk['out_n']
            );

            echo "<g class='sprint-chart-slot' tabindex='0'" . SprintChart::tipAttr($title, $rows) . ">";
            echo "<title>" . htmlescape($plain) . "</title>";
            echo "<rect class='sprint-chart-hit' x='" . $num($left + 1) . "' y='" . $num($padT) . "' width='" . $num(max(0, $slot - 2))
                . "' height='" . $num($yEnd - $padT) . "' rx='4'/>";
            echo "<line class='sprint-chart-guide' x1='" . $num($cx) . "' y1='" . $num($padT) . "' x2='" . $num($cx) . "' y2='" . $num($yEnd) . "'/>";
            echo $bar($cx - $gap / 2 - $barW, $wk['in'], $colorIn, 'in', SprintMember::formatCapacity($wk['in']));
            echo $bar($cx + $gap / 2, $wk['out'], $colorOut, 'out', SprintMember::formatCapacity($wk['out']));
            echo SprintChart::axisLabel($axis, $i, $cx, $yEnd, ($wk['in_n'] + $wk['out_n']) > 0 ? '' : 'is-dim');
            echo "</g>";
        }

        // Net change on top of the bars. It ignores the pointer so the week
        // slots underneath keep their tooltip.
        $path = '';
        foreach ($points as $i => [$px, $py]) {
            $path .= ($i === 0 ? 'M' : ' L') . $num($px) . ',' . $num($py);
        }
        echo "<g class='sprint-chart-series sprint-flow-net' data-series='net' pointer-events='none'>";
        echo "<path class='sprint-chart-line' d='{$path}' stroke='" . htmlescape($colorNet) . "'/>";
        foreach ($points as [$px, $py, $net]) {
            $isZero = abs($net) < 0.005;
            echo SprintChart::marker(0, $px, $py, 3.5, $isZero
                ? "class='sprint-chart-point sprint-chart-point-zero' stroke='" . htmlescape($colorNet) . "'"
                : "class='sprint-chart-point' fill='" . htmlescape($colorNet) . "'");
            if (!$isZero) {
                echo "<text class='sprint-chart-value sprint-chart-pointlabel' x='" . $num($px) . "' y='" . $num($net < 0 ? $py + 14 : $py - 8)
                    . "' text-anchor='middle'>" . htmlescape($signed($net)) . "</text>";
            }
        }
        echo "</g>";
        echo "</svg></div></div>";
        return (string)ob_get_clean();
    }

    private static function renderBlockedSection(array $blockedItems, bool $canedit, array $typeLabels, array $tagsById = [], array $sprintNames = []): void
    {
        $count = count($blockedItems);

        echo "<div class='sprint-backlog-section sprint-backlog-blocked'>";
        echo "<div class='sprint-backlog-section-head sprint-backlog-blocked-header' role='button' tabindex='0' aria-expanded='true' aria-controls='sprint-backlog-blocked-body'>";
        echo "<i class='fas fa-chevron-down sprint-backlog-chevron sprint-backlog-blocked-chevron' aria-hidden='true'></i>";
        echo "<i class='fas fa-ban' aria-hidden='true'></i>";
        echo "<span>" . __('Blocked items', 'sprint') . "</span>";
        echo "<span class='badge bg-danger'>" . $count . "</span>";
        echo "<span class='sprint-flex-fill'></span>";
        echo "<span class='text-muted sprint-small fw-normal sprint-backlog-blocked-hint'>"
            . __('Review periodically and unblock when ready.', 'sprint') . "</span>";
        echo "</div>";

        echo "<div class='sprint-backlog-blocked-body' id='sprint-backlog-blocked-body'>";
        if ($count === 0) {
            echo "<div class='center text-muted sprint-backlog-pad'>"
                . __('No blocked items.', 'sprint') . "</div>";
        } else {
            echo "<table class='tab_cadre_fixe sprint-themed m-0'>";
            self::renderTableHeaders($canedit, false);
            foreach ($blockedItems as $row) {
                self::renderItemRow($row, $canedit, $typeLabels, $tagsById, false, $sprintNames);
            }
            echo "</table>";
        }
        echo "</div></div>";

        echo "<script>
        (function() {
            var key = 'sprint.backlog.blocked.collapsed';
            $(function() {
                var \$wrap = $('.sprint-backlog-blocked').last();
                if (!\$wrap.length) return;
                var \$body = \$wrap.find('.sprint-backlog-blocked-body');
                var \$chev = \$wrap.find('.sprint-backlog-blocked-chevron');
                var \$head = \$wrap.find('.sprint-backlog-blocked-header');
                var stored = null;
                try { stored = localStorage.getItem(key); } catch (e) {}
                if (stored === '1') {
                    \$body.hide();
                    \$chev.css('transform', 'rotate(-90deg)');
                    \$head.attr('aria-expanded', 'false');
                }
                \$head.on('click', function() {
                    var collapsed = \$body.is(':visible');
                    \$body.slideToggle(120);
                    \$chev.css('transform', collapsed ? 'rotate(-90deg)' : 'rotate(0deg)');
                    \$head.attr('aria-expanded', collapsed ? 'false' : 'true');
                    try { localStorage.setItem(key, collapsed ? '1' : '0'); } catch (e) {}
                }).on('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); \$head.trigger('click'); }
                });
            });
        })();
        </script>";
    }

    /**
     * Freshly rendered <tr> fragment for one backlog item, so the edit modal
     * can swap the row in place instead of reloading the page. Returns null
     * when the item is gone or no longer on the backlog.
     *
     * @return array{html: string, is_blocked: int}|null
     */
    public static function renderRowFragment(int $id): ?array
    {
        $si = new SprintItem();
        if ($id <= 0 || !$si->getFromDB($id)
            || (int)$si->fields['plugin_sprint_sprints_id'] !== 0) {
            return null;
        }

        $row     = $si->fields;
        $canedit = Session::haveRight(SprintItem::$rightname, UPDATE)
            || Session::haveRight(SprintItem::$rightname, Profile::RIGHT_OWN_ITEMS);

        $sprintNames = [];
        $proposedId  = (int)($row['proposed_sprints_id'] ?? 0);
        if ($proposedId > 0) {
            $sprint = new Sprint();
            $sprintNames[$proposedId] = $sprint->getFromDB($proposedId)
                ? (string)$sprint->fields['name']
                : ('#' . $proposedId);
        }

        $isBlocked = (int)($row['is_blocked'] ?? 0) === 1;
        $isParked  = (int)($row['is_parked'] ?? 0) === 1;
        $inSection = !$isBlocked && !$isParked;

        ob_start();
        // Fragment must match the table it lands in (grip/rank columns).
        self::renderItemRow(
            $row,
            $canedit,
            self::getTypeLabels(),
            SprintItem::getTagsForItems([$id]),
            $inSection,
            $sprintNames,
            0,
            !$inSection
        );
        $html = (string)ob_get_clean();

        return [
            'html'        => $html,
            'is_blocked'  => $isBlocked ? 1 : 0,
            'is_parked'   => $isParked ? 1 : 0,
            'category_id' => (int)($row['plugin_sprint_sprintcategories_id'] ?? 0),
        ];
    }

    private static function renderItemRow(array $row, bool $canedit, array $typeLabels, array $tagsById = [], bool $reorderable = false, array $sprintNames = [], int $rank = 0, bool $showCategoryPill = true): void
    {
        $typeIcons = [
            ''            => ['fas fa-clipboard-list', 'sprint-type-manual'],
            'Ticket'      => ['fas fa-ticket-alt', 'sprint-type-ticket'],
            'Change'      => ['fas fa-exchange-alt', 'sprint-type-change'],
            'Problem'     => ['fas fa-exclamation-circle', 'sprint-type-problem'],
            'ProjectTask' => ['fas fa-tasks', 'sprint-type-task'],
        ];

        $linkedDisplay = "<span class='text-muted'>-</span>";
        if (!empty($row['itemtype']) && (int)$row['items_id'] > 0) {
            $tmp = new SprintItem();
            $tmp->fields = $row;
            $linkedDisplay = $tmp->getLinkedItemDisplay();
        }

        $itemtype   = (string)($row['itemtype'] ?? '');
        $typeKey    = $itemtype === '' ? 'manual' : $itemtype;
        $typeLabel  = $typeLabels[$itemtype] ?? __('Manual', 'sprint');
        $typeIcon   = $typeIcons[$itemtype] ?? $typeIcons[''];
        $isFastlane = (int)($row['is_fastlane'] ?? 0) === 1;
        $isBlocked  = (int)($row['is_blocked'] ?? 0) === 1;
        $isAdhoc    = (int)($row['is_adhoc'] ?? 0) === 1;
        $isParked   = (int)($row['is_parked'] ?? 0) === 1;
        $categoryId = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
        $customerId  = (int)($row['plugin_sprint_sprintcustomers_id'] ?? 0);
        $showCredits = SprintCustomer::canUseCredits();
        $rowTags     = $tagsById[(int)$row['id']] ?? [];
        $flags      = self::computeRowFlags($row);

        $ownerId      = (int)($row['users_id'] ?? 0);
        $ownerName    = $ownerId > 0 ? SprintCache::userName($ownerId) : '';
        $estCapacity  = (float)($row['capacity'] ?? 0);
        $proposedId   = (int)($row['proposed_sprints_id'] ?? 0);
        $proposedName = $proposedId > 0 ? (string)($sprintNames[$proposedId] ?? ('#' . $proposedId)) : '';
        // An open assignment request may target another sprint than the proposal.
        $pendingSid   = $flags['pending_assign'] ? ($flags['pending_sprint_id'] ?: $proposedId) : 0;
        $pendingName  = '';
        if ($pendingSid > 0) {
            if (isset($sprintNames[$pendingSid])) {
                $pendingName = (string)$sprintNames[$pendingSid];
            } else {
                $ps = new Sprint();
                $pendingName = $ps->getFromDB($pendingSid) ? (string)$ps->fields['name'] : ('#' . $pendingSid);
            }
        }
        $rowName = (string)$row['name'];

        echo "<tr class='tab_bg_1 sprint-filterable-row sprint-backlog-row' "
            . "data-item-id='" . (int)$row['id'] . "' "
            . "data-item-name='" . htmlescape($row['name']) . "' "
            . "data-item-type='" . htmlescape($typeKey) . "' "
            . "data-users-id='" . $ownerId . "' "
            . "data-owner-name='" . htmlescape($ownerName) . "' "
            . "data-capacity='" . SprintMember::formatCapacity($estCapacity) . "' "
            . ($showCredits
                ? "data-customer-id='" . $customerId . "' "
                    . "data-customer-name='" . htmlescape(SprintCustomer::getNameFor($customerId)) . "' "
                    . "data-credits='" . SprintCustomer::formatCredits($row['credits'] ?? 0) . "' "
                    . "data-credit-product-id='" . (int)($row['plugin_sprint_sprintcreditproducts_id'] ?? 0) . "' "
                : '')
            . "data-proposed-sprint-id='" . $proposedId . "' "
            . "data-proposed-sprint-name='" . htmlescape($proposedName) . "' "
            . "data-pending-assign='" . ($flags['pending_assign'] ? 1 : 0) . "' "
            . "data-pending-sprint-name='" . htmlescape($pendingName) . "' "
            . "data-can-assign='" . ($flags['can_assign'] ? 1 : 0) . "' "
            . "data-is-fastlane='" . ($isFastlane ? 1 : 0) . "' "
            . "data-is-blocked='" . ($isBlocked ? 1 : 0) . "' "
            . "data-is-adhoc='" . ($isAdhoc ? 1 : 0) . "' "
            . "data-is-parked='" . ($isParked ? 1 : 0) . "' "
            . "data-category-id='{$categoryId}' "
            . "data-project-name='" . htmlescape(SprintItem::getParentProjectName($itemtype, (int)($row['items_id'] ?? 0))) . "' "
            . "data-team-rank='" . (int)$rank . "' "
            . "data-item-tags='" . htmlescape(SprintItem::tagsToBlob($rowTags)) . "'>";
        if ($reorderable && $canedit) {
            echo "<td class='sprint-backlog-grip' "
                . "title='" . __s('Drag to reorder — Ctrl/Cmd-click rows to select more and drag them together', 'sprint') . "'><i class='fas fa-grip-vertical' aria-hidden='true'></i></td>";
        }
        if ($reorderable) {
            echo "<td class='center sprint-backlog-rank-cell'>";
            // Up/down: the keyboard (and touch) alternative to dragging.
            if ($canedit) {
                echo "<button type='button' class='btn btn-link btn-sm p-0 sprint-backlog-move' data-dir='up' "
                    . "aria-label='" . htmlescape(sprintf(__('Move "%s" up', 'sprint'), $rowName)) . "' title='" . __s('Move up', 'sprint') . "'>"
                    . "<i class='fas fa-chevron-up' aria-hidden='true'></i></button>";
            }
            echo "<span class='sprint-backlog-rank text-muted sprint-small fw-bold'>"
                . ($rank > 0 ? $rank : '') . "</span>";
            if ($canedit) {
                echo "<button type='button' class='btn btn-link btn-sm p-0 sprint-backlog-move' data-dir='down' "
                    . "aria-label='" . htmlescape(sprintf(__('Move "%s" down', 'sprint'), $rowName)) . "' title='" . __s('Move down', 'sprint') . "'>"
                    . "<i class='fas fa-chevron-down' aria-hidden='true'></i></button>";
            }
            echo "</td>";
        }

        // Name cell carries the compact indicators: type icon, fastlane bolt,
        // blocked ban, tags and the planning badge.
        echo "<td>";
        echo "<i class='{$typeIcon[0]} sprint-type-icon {$typeIcon[1]} sprint-backlog-type-icon' title='" . htmlescape($typeLabel) . "' aria-hidden='true'></i>"
            . "<span class='visually-hidden'>" . htmlescape($typeLabel) . ": </span>";
        if ($isFastlane) {
            echo "<i class='fas fa-bolt sprint-tone-orange me-1' title='" . __s('Fastlane', 'sprint') . "' aria-hidden='true'></i>"
                . "<span class='visually-hidden'>" . __s('Fastlane', 'sprint') . ", </span>";
        }
        if ($isBlocked) {
            echo "<i class='fas fa-ban sprint-tone-danger me-1' title='" . __s('Blocked', 'sprint') . "' aria-hidden='true'></i>"
                . "<span class='visually-hidden'>" . __s('Blocked', 'sprint') . ", </span>";
        }
        echo "<a href='" . SprintItem::getFormURLWithID($row['id']) . "'>"
            . htmlescape($row['name']) . "</a>"
            . SprintItem::renderParentProjectSuffix($itemtype, (int)($row['items_id'] ?? 0))
            . SprintItem::renderAdhocBadge($isAdhoc)
            . ($showCategoryPill ? SprintCategory::renderPill($categoryId) : '')
            . SprintItem::renderTagPills($rowTags);
        echo self::renderPlanningBadge($row);
        // Aging: unplanned active items that sit too long get a nudge.
        $agingDays = Config::getBacklogAgingDays();
        if ($reorderable && $agingDays > 0 && $proposedId <= 0 && !empty($row['date_creation'])) {
            $age = (int)floor((time() - strtotime((string)$row['date_creation'])) / DAY_TIMESTAMP);
            if ($age >= $agingDays) {
                $late = $age >= 2 * $agingDays;
                echo " <span class='sprint-aging-badge " . ($late ? 'sprint-aging-late' : 'sprint-aging-warn') . "' title='"
                    . htmlescape(sprintf(__('Unplanned on the backlog for %d days — schedule it or park it', 'sprint'), $age)) . "'>"
                    . "<i class='fas fa-clock' aria-hidden='true'></i> " . sprintf(__('%dd', 'sprint'), $age) . "</span>";
            }
        }
        echo "</td>";
        echo "<td>" . $linkedDisplay . "</td>";

        echo "<td class='center'>";
        echo $ownerId > 0
            ? "<i class='fas fa-user text-muted me-1' aria-hidden='true'></i>" . htmlescape($ownerName)
            : "<span class='text-muted'>" . __('Unassigned', 'sprint') . "</span>";
        echo "</td>";

        echo "<td class='center sprint-backlog-sprint-cell'>";
        if ($pendingSid > 0) {
            // A request is never shown as if it were already applied.
            echo "<span class='sprint-chip sprint-chip-warning' title='"
                . htmlescape(sprintf(__('Assignment requested: Backlog → %s. The item stays on the backlog until the Scrum Master accepts.', 'sprint'), $pendingName)) . "'>"
                . "<i class='fas fa-hourglass-half me-1' aria-hidden='true'></i>" . __s('Requested — waiting for Scrum Master', 'sprint') . "</span>"
                . "<div class='sprint-small text-muted'>" . __s('Backlog', 'sprint') . " → " . htmlescape($pendingName) . "</div>";
        } elseif ($proposedId > 0) {
            echo "<span class='sprint-state-badge sprint-state-proposed' title='"
                . __s('Proposed sprint: the item stays on the backlog until it is assigned.', 'sprint') . "'>" . __s('Proposed', 'sprint') . "</span> "
                . htmlescape($proposedName);
            // Capacity chip: what remains for this owner in that sprint once
            // everything pending (incl. this row) is assigned.
            $capInfo = SprintMember::backlogCapacityPreview($proposedId, $ownerId, (int)$row['id']);
            if ($capInfo !== null && $ownerId > 0) {
                if (!$capInfo['is_member']) {
                    echo " <span class='sprint-cap-chip sprint-cap-chip-warn' title='"
                        . __s('Owner is not a member of the selected sprint', 'sprint') . "'>"
                        . "<i class='fas fa-user-slash' aria-hidden='true'></i></span>";
                } else {
                    // Shared model and threshold: assigned work, then everything
                    // proposed (other rows + this one) on top of it.
                    $f = SprintMember::capacityFigures(
                        $capInfo['figures']['set'],
                        $capInfo['figures']['available'],
                        $capInfo['figures']['regular'],
                        $capInfo['figures']['fastlane'],
                        $capInfo['figures']['helping'],
                        $capInfo['figures']['helping_open'],
                        $capInfo['pending_other'] + $estCapacity
                    );
                    $chipClass = [
                        SprintCapacityMath::STATE_OVER        => 'sprint-cap-chip-over',
                        SprintCapacityMath::STATE_FULL        => 'sprint-cap-chip-warn',
                        SprintCapacityMath::STATE_ALMOST_FULL => 'sprint-cap-chip-warn',
                    ][$f['state_after_proposals']] ?? 'sprint-cap-chip-neutral';
                    $tooltip = SprintMember::capacityDescription($f, SprintCache::userName($ownerId), $proposedName);
                    $chipText = $f['over_after_proposals'] > SprintCapacityMath::EPSILON
                        ? sprintf(__('%s%% over capacity', 'sprint'), SprintMember::formatCapacity($f['over_after_proposals']))
                        : SprintMember::formatCapacity($f['remaining_after_proposals']) . '% ' . __('free', 'sprint');
                    echo " <span class='sprint-cap-chip {$chipClass}' title='" . htmlescape($tooltip) . "'>"
                        . htmlescape($chipText) . "</span>";
                }
            }
        } else {
            echo "<span class='text-muted'>-</span>";
        }
        echo "</td>";

        echo "<td class='center'>";
        echo $estCapacity > 0
            ? SprintMember::formatCapacity($estCapacity) . '%'
            : "<span class='text-muted'>-</span>";
        echo "</td>";

        if ($showCredits) {
            echo "<td class='center sprint-cell-credits'>"
                . SprintItem::renderCreditCell($customerId, $row['credits'] ?? 0, (int)($row['plugin_sprint_sprintcreditproducts_id'] ?? 0)) . "</td>";
        }

        if ($canedit) {
            echo "<td class='center sprint-backlog-actions sprint-nowrap'>";
            echo "<button type='button' class='btn btn-sm btn-outline-secondary sprint-backlog-edit-btn me-1' "
                . "data-item-id='" . (int)$row['id'] . "' "
                . "aria-label='" . htmlescape(sprintf(__('Edit "%s"', 'sprint'), $rowName)) . "' "
                . "title='" . __s('Edit', 'sprint') . "'>"
                . SprintIcon::html(SprintIcon::EDIT) . "</button>";
            if ($flags['can_assign'] || $proposedId <= 0) {
                // Assign posts the pre-selected sprint; server-side permission
                // check stays in backlog.form.php / assigntosprint.php. The
                // sprint-backlog-assign-form class lets js/sprint.js hijack the
                // submit into the AJAX assign flow.
                echo "<form method='post' action='" . self::getFormURL() . "' "
                    . "class='sprint-backlog-assign-form d-inline' data-item-id='" . (int)$row['id'] . "'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::hidden('plugin_sprint_sprints_id', ['value' => $proposedId]);
                $assignTitle = $proposedId <= 0
                    ? __('Propose a sprint first (edit the item)', 'sprint')
                    : ($isFastlane
                        ? __('Fastlane item — anyone can assign it to a sprint', 'sprint')
                        : sprintf(__('Assign to sprint %s', 'sprint'), $proposedName));
                echo "<button type='submit' name='assign_to_sprint' value='1' "
                    . "class='btn btn-sm btn-primary sprint-backlog-assign-btn' "
                    . ($proposedId <= 0 ? "disabled " : "")
                    . "aria-label='" . htmlescape($rowName . ': ' . $assignTitle) . "' "
                    . "title='" . htmlescape($assignTitle) . "'>"
                    . "<i class='fas fa-arrow-right' aria-hidden='true'></i><span class='sprint-btn-text'> " . __s('Assign', 'sprint') . "</span></button>";
                Html::closeForm();
            } else {
                // Same spot, same intent: non-Scrum-Masters ask the Scrum
                // Master to assign it — the request lands in their approval
                // panel. Handled by ajax/requestcreate.php.
                $hasPending = $flags['pending_assign'];
                echo "<button type='button' "
                    . "class='btn btn-sm " . ($hasPending ? "btn-outline-secondary" : "btn-outline-primary") . " sprint-backlog-request-btn' "
                    . "data-item-id='" . (int)$row['id'] . "' "
                    . ($hasPending ? "disabled " : "")
                    . "aria-label='" . htmlescape($rowName . ': ' . ($hasPending
                        ? __('Assignment already requested — waiting for the Scrum Master', 'sprint')
                        : __('Ask the Scrum Master to assign this item to the selected sprint', 'sprint'))) . "' "
                    . "title='" . ($hasPending
                        ? __s('Assignment already requested — waiting for the Scrum Master', 'sprint')
                        : __s('Ask the Scrum Master to assign this item to the selected sprint', 'sprint')) . "'>"
                    . "<i class='fas fa-" . ($hasPending ? "hourglass-half" : "paper-plane") . "' aria-hidden='true'></i>"
                    . "<span class='sprint-btn-text'> " . ($hasPending ? __s('Requested', 'sprint') : __s('Request', 'sprint')) . "</span></button>";
            }
            echo "</td>";
        }
        echo "</tr>";
    }

    private static function renderFilterBar(array $typeLabels, array $owners = [], array $sprintNames = [], bool $canedit = false): void
    {
        echo "<div class='sprint-filter-bar sprint-backlog-filter' role='search' aria-label='" . __s('Filter backlog items', 'sprint') . "'>";

        echo "<div class='d-flex align-items-center gap-1 text-muted sprint-small'>"
            . "<i class='fas fa-filter' aria-hidden='true'></i><span>" . __('Filter', 'sprint') . "</span></div>";

        echo "<input type='search' class='form-control form-control-sm sf-text sprint-mw-240' "
            . " placeholder='" . __s('Search by name', 'sprint') . "' aria-label='" . __s('Search by name', 'sprint') . "'>";

        echo "<select class='form-select form-select-sm sf-type sprint-mw-200' aria-label='" . __s('Type', 'sprint') . "'>";
        echo "<option value=''>" . __('All') . " — " . __('Type', 'sprint') . "</option>";
        echo "<option value='Ticket'>" . htmlescape($typeLabels['Ticket']) . "</option>";
        echo "<option value='Change'>" . htmlescape($typeLabels['Change']) . "</option>";
        echo "<option value='Problem'>" . htmlescape($typeLabels['Problem']) . "</option>";
        echo "<option value='ProjectTask'>" . htmlescape($typeLabels['ProjectTask']) . "</option>";
        echo "<option value='manual'>" . htmlescape($typeLabels['']) . "</option>";
        echo "</select>";

        // Owner filter, handled by the shared filter JS (.sf-owner +
        // data-users-id / data-owner-name), as inside a sprint.
        if (!empty($owners)) {
            echo "<select class='form-select form-select-sm sf-owner sprint-mw-220' aria-label='" . __s('Owner', 'sprint') . "'>";
            echo "<option value=''>" . __('All owners', 'sprint') . "</option>";
            echo "<option value='__unassigned__'>" . __('Unassigned only', 'sprint') . "</option>";
            foreach ($owners as $uidOpt => $name) {
                if ((int)$uidOpt === 0) {
                    continue;
                }
                echo "<option value='" . (int)$uidOpt . "'>" . htmlescape((string)$name) . "</option>";
            }
            echo "</select>";
        }

        if (!empty($sprintNames)) {
            echo "<select class='form-select form-select-sm sf-sprint sprint-mw-220' aria-label='" . __s('Proposed sprint', 'sprint') . "'>";
            echo "<option value=''>" . __('All sprints', 'sprint') . "</option>";
            echo "<option value='__none__'>" . __('No sprint proposed', 'sprint') . "</option>";
            foreach ($sprintNames as $sidOpt => $name) {
                echo "<option value='" . (int)$sidOpt . "'>" . htmlescape((string)$name) . "</option>";
            }
            echo "</select>";
        }

        $definedTags = Config::getDefinedTags();
        if (!empty($definedTags)) {
            echo "<select class='form-select form-select-sm sf-tag sprint-mw-180' aria-label='" . __s('Tag', 'sprint') . "'>";
            echo "<option value=''>" . __('All tags', 'sprint') . "</option>";
            foreach ($definedTags as $tag) {
                echo "<option value='" . htmlescape(mb_strtolower($tag)) . "'>" . htmlescape($tag) . "</option>";
            }
            echo "</select>";
        }

        echo "<button type='button' class='btn btn-sm btn-outline-secondary sf-reset' data-sprint-action='filter-reset'>"
            . SprintIcon::html(SprintIcon::REJECT, 'me-1') . "" . __('Reset filters', 'sprint') . "</button>";

        // Personal view: how THIS user sees the backlog. "Project, then name"
        // re-orders every category client-side (natural order, so "Task 2"
        // sorts before "Task 10"; rows without a project last). The choice is
        // saved per user (UserPref) and is that user's default everywhere;
        // the shared team ranking is never touched by it.
        $sortMode = UserPref::backlogSort();
        echo "<span class='vr d-none d-md-inline-block sprint-backlog-filter-sep'></span>";
        echo "<div class='d-flex align-items-center gap-1 text-muted sprint-small'>"
            . "<i class='fas fa-eye' aria-hidden='true'></i><span>" . __('Order', 'sprint') . "</span></div>";
        echo "<select class='form-select form-select-sm sprint-backlog-sort sprint-mw-200' aria-label='" . __s('Order', 'sprint') . "' title='"
            . __s('Personal view, saved as your default: the order only changes for you — the team ranking stays as it is', 'sprint') . "'>";
        echo "<option value='team'" . ($sortMode === 'team' ? ' selected' : '') . ">" . __('Team ranking', 'sprint') . "</option>";
        echo "<option value='project'" . ($sortMode === 'project' ? ' selected' : '') . ">" . __('Project, then name', 'sprint') . "</option>";
        echo "</select>";

        echo "</div>";

        // Active filters as removable chips + "x of y items" (filled by JS).
        echo "<div class='sprint-backlog-chips' aria-live='polite'>"
            . "<span class='sprint-backlog-result text-muted sprint-small'></span>"
            . "<span class='sprint-backlog-chip-list'></span></div>";
    }

    /** item_update hook tail: purge backlog rows whose linked item just got solved. */
    public static function autoCleanupForItem(\CommonDBTM $item): void
    {
        if (!Config::isBacklogAutoCleanup()) {
            return;
        }
        $updates = (array)($item->updates ?? []);
        if (empty(array_intersect(['status', 'percent_done', 'projectstates_id'], $updates))) {
            return;
        }
        // Hook fires post-update; drop any stale cached copy first.
        SprintCache::forget($item->getType(), (int)$item->getID());
        self::purgeSolvedRows($item->getType(), (int)$item->getID());
    }

    /**
     * Purge backlog rows whose linked item is solved/closed. Vanished links
     * are kept visible.
     */
    public static function purgeSolvedRows(?string $itemtype = null, ?int $itemsId = null): int
    {
        if (!Config::isBacklogAutoCleanup()) {
            return 0;
        }
        $criteria = [
            'plugin_sprint_sprints_id' => 0,
            ['NOT' => ['itemtype' => '']],
            ['items_id' => ['>', 0]],
        ];
        if ($itemtype !== null && $itemsId !== null) {
            $criteria['itemtype'] = $itemtype;
            $criteria['items_id'] = $itemsId;
        }

        $purged = 0;
        foreach ((new SprintItem())->find($criteria) as $row) {
            $rowType = (string)$row['itemtype'];
            $rowId   = (int)$row['items_id'];
            if ($rowType === '' || $rowId <= 0 || !class_exists($rowType)) {
                continue;
            }
            if (SprintCache::getObject($rowType, $rowId) === null) {
                continue; // vanished link — keep the row visible
            }
            if (!SprintItem::linkedItemClosedFor($rowType, $rowId)) {
                continue;
            }
            $si = new SprintItem();
            if ($si->delete(['id' => (int)$row['id']], true)) {
                $purged++;
            }
        }
        return $purged;
    }

    /**
     * Create a backlog item from a Ticket/Change/ProjectTask. Returns the
     * SprintItem ID, or 0 if it could not be created. An existing backlog
     * entry for the same linked item is reused instead of duplicated.
     */
    public static function addFromLinkedItem(string $itemtype, int $itemId, array $extra = []): int
    {
        $allowed = ['Ticket', 'Change', 'Problem', 'ProjectTask'];
        if (!in_array($itemtype, $allowed, true) || $itemId <= 0) {
            return 0;
        }

        // Backlog and sprint membership are mutually exclusive.
        if (self::isLinkedItemInAnySprint($itemtype, $itemId)) {
            return 0;
        }

        // Reuse an existing backlog row for the same linked item.
        $existing = (new SprintItem())->find([
            'plugin_sprint_sprints_id' => 0,
            'itemtype'                 => $itemtype,
            'items_id'                 => $itemId,
        ]);
        if (count($existing) > 0) {
            $first = reset($existing);
            if ($extra) {
                (new SprintItem())->update(['id' => (int)$first['id']] + $extra);
            }
            return (int)$first['id'];
        }

        $linked = new $itemtype();
        if (!$linked->getFromDB($itemId)) {
            return 0;
        }

        $name     = $linked->fields['name'] ?? ($itemtype . ' #' . $itemId);
        $priority = (int)($linked->fields['priority'] ?? 3);

        $sprintItem = new SprintItem();
        $newId = $sprintItem->add($extra + [
            'plugin_sprint_sprints_id' => 0,
            'name'                     => $name,
            'itemtype'                 => $itemtype,
            'items_id'                 => $itemId,
            'status'                   => SprintItem::STATUS_TODO,
            'priority'                 => $priority,
            'users_id'                 => 0,
            'capacity'                 => 0,
            'story_points'             => 1,
        ]);

        return (int)$newId;
    }
}
