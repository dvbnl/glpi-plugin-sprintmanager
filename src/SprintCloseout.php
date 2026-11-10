<?php

namespace GlpiPlugin\Sprint;

use Session;

/**
 * Close-out screen for completing a sprint: every open item in one table with
 * a three-way choice (carry over, back to backlog, leave), handled in one
 * server call (ajax/closesprint.php) that then completes the sprint.
 *
 * The moves go through SprintItem::carryOverOrRequest() and
 * SprintItem::backToBacklog(), and the status change through Sprint::update(),
 * so the Creditfall log and Sprint::post_updateItem() keep running.
 */
class SprintCloseout
{
    public const ACTION_CARRY   = 'carry';
    public const ACTION_BACKLOG = 'backlog';
    public const ACTION_LEAVE   = 'leave';

    /** Upper bound on the rows one call handles. */
    private const MAX_ROWS = 500;

    /** Sprint items that are not done, in a stable order. */
    public static function getOpenItems(int $sprintId): array
    {
        if ($sprintId <= 0) {
            return [];
        }
        return (new SprintItem())->find(
            [
                'plugin_sprint_sprints_id' => $sprintId,
                ['NOT' => ['status' => SprintItem::STATUS_DONE]],
            ],
            ['is_fastlane DESC', 'name ASC', 'id ASC']
        );
    }

    /** Same item right as ajax/carryover.php: full update, or own items only. */
    private static function canChangeItem(array $fields): bool
    {
        if (Session::haveRight('plugin_sprint_item', UPDATE)) {
            return true;
        }
        return Session::haveRight('plugin_sprint_item', Profile::RIGHT_OWN_ITEMS)
            && (int)($fields['users_id'] ?? 0) === (int)Session::getLoginUserID();
    }

    /** Queued GLPI messages as plain text, removed from the session. */
    private static function drainMessages(): array
    {
        $out = [];
        if (isset($_SESSION['MESSAGE_AFTER_REDIRECT']) && is_array($_SESSION['MESSAGE_AFTER_REDIRECT'])) {
            foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] as $messages) {
                foreach ((array)$messages as $message) {
                    $text = trim(strip_tags((string)$message));
                    if ($text !== '') {
                        $out[] = $text;
                    }
                }
            }
            $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        }
        return array_values(array_unique($out));
    }

    /**
     * Handle the chosen moves and, when asked, complete the sprint. The
     * caller (ajax/closesprint.php) has checked CSRF and the sprint rights.
     *
     * @param array  $rows         [itemId => ['action', 'target', 'capacity', 'credits', 'reason']]
     * @param string $sharedReason used for every row without a reason of its own
     * @return array{success: bool, completed: bool, message: string, results: array,
     *               counts: array, notices: array, redirect: string}
     */
    public static function process(Sprint $sprint, array $rows, string $sharedReason, bool $complete): array
    {
        $sprintId     = (int)$sprint->getID();
        $sharedReason = trim($sharedReason);
        $openBefore   = count(self::getOpenItems($sprintId));
        $canCredits   = SprintCustomer::canUseCredits();
        $counts       = ['carried' => 0, 'requested' => 0, 'backlog' => 0, 'failed' => 0, 'left' => 0];
        $results      = [];
        $handled      = 0;

        foreach (array_slice($rows, 0, self::MAX_ROWS, true) as $itemId => $row) {
            $itemId = (int)$itemId;
            $action = is_array($row) ? (string)($row['action'] ?? '') : '';
            if ($itemId <= 0 || !in_array($action, [self::ACTION_CARRY, self::ACTION_BACKLOG], true)) {
                continue;
            }
            $handled++;
            $entry = ['id' => $itemId, 'name' => '', 'action' => $action, 'status' => 'failed', 'message' => '', 'new_id' => 0];

            $item = new SprintItem();
            if (!$item->getFromDB($itemId)
                || (int)($item->fields['plugin_sprint_sprints_id'] ?? 0) !== $sprintId
                || !$item->hasEntityAccess()) {
                $entry['message'] = __('This item is no longer part of the sprint.', 'sprint');
                $counts['failed']++;
                $results[] = $entry;
                continue;
            }
            $entry['name'] = plugin_sprint_text((string)($item->fields['name'] ?? ''));
            if (!self::canChangeItem($item->fields)) {
                $entry['message'] = __('You are not allowed to change this item.', 'sprint');
                $counts['failed']++;
                $results[] = $entry;
                continue;
            }
            if (($item->fields['status'] ?? '') === SprintItem::STATUS_DONE) {
                $entry['message'] = __('This item is already done.', 'sprint');
                $counts['failed']++;
                $results[] = $entry;
                continue;
            }

            $reason = trim((string)($row['reason'] ?? ''));
            if ($reason === '') {
                $reason = $sharedReason;
            }
            $reason = mb_substr($reason, 0, 2000);

            if ($action === self::ACTION_CARRY) {
                // Default "carry the rest": everything the item still holds.
                $capacity = isset($row['capacity']) && $row['capacity'] !== ''
                    ? (float)$row['capacity']
                    : (float)($item->fields['capacity'] ?? 0);
                $credits = isset($row['credits']) && $row['credits'] !== ''
                    ? (float)$row['credits']
                    : (float)($item->fields['credits'] ?? 0);
                if (!$canCredits) {
                    $credits = 0.0;
                }
                $outcome = SprintItem::carryOverOrRequest(
                    $itemId,
                    (int)($row['target'] ?? 0),
                    $capacity,
                    $credits,
                    $reason
                );
                $entry['message'] = strip_tags((string)$outcome['message']);
                if (!$outcome['ok']) {
                    $counts['failed']++;
                } elseif ($outcome['requested']) {
                    $entry['status'] = 'requested';
                    $counts['requested']++;
                } else {
                    $entry['status'] = 'done';
                    $entry['new_id'] = (int)$outcome['new_id'];
                    $counts['carried']++;
                }
            } else {
                $outcome = SprintItem::backToBacklog($itemId, $reason, true);
                if (!empty($outcome['ok'])) {
                    $entry['status']  = 'done';
                    $entry['message'] = __('Item moved back to backlog', 'sprint');
                    $counts['backlog']++;
                } else {
                    $entry['message'] = strip_tags((string)($outcome['message'] ?? '')) ?: __('Could not move item back to backlog', 'sprint');
                    $counts['failed']++;
                }
            }
            $results[] = $entry;
        }

        $counts['left'] = max(0, $openBefore - $handled);
        $notices        = self::drainMessages();

        // A failed move blocks the completion: the user fixes or leaves that
        // row and confirms again, nothing is closed half-way.
        $completed = false;
        $failure   = '';
        if ($counts['failed'] > 0) {
            $failure = $complete
                ? __('Some items could not be processed. The sprint is not completed yet.', 'sprint')
                : __('Some items could not be processed.', 'sprint');
        } elseif ($complete) {
            if (($sprint->fields['status'] ?? '') === Sprint::STATUS_COMPLETED) {
                $completed = true;
            } else {
                $completed = (bool)$sprint->update([
                    'id'     => $sprintId,
                    'status' => Sprint::STATUS_COMPLETED,
                ]);
                // post_updateItem() queues its own "unfinished items" warning.
                // The summary below already says what stayed, so drop that one.
                $unfinished = count(self::getOpenItems($sprintId));
                $redundant  = $unfinished > 0 ? strip_tags(sprintf(
                    _n(
                        'Sprint completed with %d unfinished item — carry it over to another sprint or send it back to the backlog.',
                        'Sprint completed with %d unfinished items — carry them over to another sprint or send them back to the backlog.',
                        $unfinished,
                        'sprint'
                    ),
                    $unfinished
                )) : '';
                foreach (self::drainMessages() as $text) {
                    if ($text !== $redundant) {
                        $notices[] = $text;
                    }
                }
                if (!$completed) {
                    $failure = __('The sprint could not be completed.', 'sprint');
                }
            }
        }

        $parts = [];
        if ($counts['carried'] > 0) {
            $parts[] = sprintf(_n('%d item carried over', '%d items carried over', $counts['carried'], 'sprint'), $counts['carried']);
        }
        if ($counts['requested'] > 0) {
            $parts[] = sprintf(
                _n(
                    '%d carry-over requested from another Scrum Master',
                    '%d carry-overs requested from another Scrum Master',
                    $counts['requested'],
                    'sprint'
                ),
                $counts['requested']
            );
        }
        if ($counts['backlog'] > 0) {
            $parts[] = sprintf(_n('%d item back to backlog', '%d items back to backlog', $counts['backlog'], 'sprint'), $counts['backlog']);
        }
        if ($counts['left'] > 0) {
            $parts[] = sprintf(_n('%d item left in the sprint', '%d items left in the sprint', $counts['left'], 'sprint'), $counts['left']);
        }
        if ($counts['failed'] > 0) {
            $parts[] = sprintf(_n('%d item failed', '%d items failed', $counts['failed'], 'sprint'), $counts['failed']);
        }

        $success = $failure === '';
        $head    = $failure !== ''
            ? $failure
            : ($completed ? __('Sprint completed.', 'sprint') : __('Open items processed.', 'sprint'));
        $message = $head . ($parts ? ' ' . implode(', ', $parts) . '.' : '');

        $redirect = '';
        if ($success) {
            // One message, shown after the redirect back to the sprint.
            $redirect = Sprint::getFormURLWithID($sprintId);
            Session::addMessageAfterRedirect(
                // Notices are GLPI messages with the tags stripped: already HTML-safe.
                implode('<br>', array_merge([htmlescape($message)], $notices)),
                false,
                $counts['requested'] > 0 || $notices ? WARNING : INFO
            );
        }

        return [
            'success'   => $success,
            'completed' => $completed,
            'message'   => $message,
            'results'   => $results,
            'counts'    => $counts,
            'notices'   => array_map(
                static fn(string $text) => html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                array_values($notices)
            ),
            'redirect'  => $redirect,
        ];
    }

    /**
     * Put the sprint that follows this one at the top of the target list, so
     * the default choice is the next sprint and not an older one still open.
     *
     * @param array<int,string> $targets sprint id => label
     * @return array<int,string>
     */
    private static function nextSprintFirst(array $targets, string $dateStart): array
    {
        if (count($targets) < 2 || $dateStart === '') {
            return $targets;
        }
        $next     = 0;
        $nextDate = '';
        foreach ((new Sprint())->find(['id' => array_keys($targets)]) as $row) {
            $date = (string)($row['date_start'] ?? '');
            if ($date > $dateStart && ($nextDate === '' || $date < $nextDate)) {
                $next     = (int)$row['id'];
                $nextDate = $date;
            }
        }
        if ($next <= 0) {
            return $targets;
        }
        return [$next => $targets[$next]] + $targets;
    }

    /** The screen itself (front/sprint.form.php?id=…&closeout=1). */
    public static function show(Sprint $sprint): void
    {
        $sprintId = (int)$sprint->getID();
        $status   = (string)($sprint->fields['status'] ?? '');
        $backUrl  = Sprint::getFormURLWithID($sprintId);
        $name     = plugin_sprint_html((string)($sprint->fields['name'] ?? ''));

        echo "<div class='sprint-closeout container-fluid'>";
        echo "<div class='sprint-closeout-head'>";
        echo "<h2 class='sprint-closeout-title'><i class='fas fa-flag-checkered me-2' aria-hidden='true'></i>"
            . sprintf(__('Complete sprint: %s', 'sprint'), $name) . "</h2>";
        echo "<a class='btn btn-outline-secondary btn-sm' href='" . htmlescape($backUrl) . "'>"
            . "<i class='fas fa-arrow-left me-1' aria-hidden='true'></i>" . __s('Back to the sprint', 'sprint') . "</a>";
        echo "</div>";

        $blocked = '';
        if (!Sprint::canManageLifecycle($sprintId)) {
            $blocked = __('Only the Scrum Master of this sprint can complete it.', 'sprint');
        } elseif (!in_array($status, [Sprint::STATUS_ACTIVE, Sprint::STATUS_COMPLETED], true)) {
            $blocked = __('Only an active sprint can be completed. Start the sprint first.', 'sprint');
        }
        if ($blocked !== '') {
            echo "<div class='alert alert-warning' role='alert'>" . htmlescape($blocked) . "</div></div>";
            return;
        }

        $alreadyCompleted = $status === Sprint::STATUS_COMPLETED;
        $items            = self::getOpenItems($sprintId);
        $targets          = Sprint::getMoveTargetOptions($sprintId);
        $targets          = self::nextSprintFirst($targets, (string)($sprint->fields['date_start'] ?? ''));
        $canCredits       = SprintCustomer::canUseCredits();
        $statuses         = SprintItem::getAllStatuses();
        $types            = SprintItem::getLinkedItemTypes();

        if ($alreadyCompleted && !$items) {
            echo "<div class='alert alert-info' role='status'>"
                . __s('This sprint is completed and has no open items.', 'sprint') . "</div></div>";
            return;
        }

        echo "<p class='sprint-closeout-intro'>" . htmlescape(
            $alreadyCompleted
                ? __('This sprint is already completed. Decide per open item what happens with it.', 'sprint')
                : __('Decide per open item what happens with it. Completing the sprint freezes its credit agreements.', 'sprint')
        ) . "</p>";

        $webDir = \Plugin::getWebDir('sprint');
        echo "<div class='sprint-closeout-form' data-sprint-id='{$sprintId}'"
            . " data-complete='" . ($alreadyCompleted ? 0 : 1) . "'"
            . " data-endpoint='" . htmlescape($webDir . '/ajax/closesprint.php') . "'"
            . " data-token-url='" . htmlescape($webDir . '/ajax/csrftoken.php') . "'>";

        echo "<div class='alert sprint-closeout-result' role='alert' tabindex='-1' hidden></div>";

        if (!$items) {
            echo "<div class='alert alert-success' role='status'>"
                . "<i class='fas fa-check-circle me-1' aria-hidden='true'></i>"
                . __s('All items are done. Nothing to carry over.', 'sprint') . "</div>";
        } else {
            // Bulk bar: one target sprint, one shared reason, three shortcuts.
            echo "<div class='sprint-closeout-bulk'>";
            echo "<label class='sprint-closeout-field'><span>" . __s('Target sprint', 'sprint') . "</span>";
            echo "<select class='form-select form-select-sm sprint-closeout-bulk-target'" . ($targets ? '' : ' disabled') . ">";
            if (!$targets) {
                echo "<option value='0'>" . __s('No planned or active sprint available', 'sprint') . "</option>";
            }
            foreach ($targets as $targetId => $label) {
                echo "<option value='" . (int)$targetId . "'>" . plugin_sprint_html((string)$label) . "</option>";
            }
            echo "</select></label>";
            echo "<button type='button' class='btn btn-outline-primary btn-sm sprint-closeout-all' data-action='" . self::ACTION_CARRY . "'"
                . ($targets ? '' : ' disabled') . "><i class='fas fa-forward me-1' aria-hidden='true'></i>"
                . __s('All to this sprint', 'sprint') . "</button>";
            echo "<button type='button' class='btn btn-outline-primary btn-sm sprint-closeout-all' data-action='" . self::ACTION_BACKLOG . "'>"
                . "<i class='fas fa-inbox me-1' aria-hidden='true'></i>" . __s('All back to backlog', 'sprint') . "</button>";
            echo "<button type='button' class='btn btn-outline-secondary btn-sm sprint-closeout-all' data-action='" . self::ACTION_LEAVE . "'>"
                . __s('Leave all in the sprint', 'sprint') . "</button>";
            echo "<label class='sprint-closeout-field sprint-closeout-field-grow'><span>" . __s('Reason for all items (optional)', 'sprint') . "</span>";
            echo "<input type='text' class='form-control form-control-sm sprint-closeout-shared-reason' maxlength='2000'"
                . " placeholder='" . __s('Shown in the retrospective and on the Creditfall page', 'sprint') . "'></label>";
            echo "</div>";

            echo "<div class='table-responsive sprint-closeout-scroll'>";
            echo "<table class='table table-vcenter sprint-closeout-table'>";
            echo "<thead><tr>";
            echo "<th scope='col'>" . __s('Item', 'sprint') . "</th>";
            echo "<th scope='col'>" . __s('Status') . "</th>";
            echo "<th scope='col'>" . __s('Owner', 'sprint') . "</th>";
            echo "<th scope='col'>" . __s('What happens', 'sprint') . "</th>";
            echo "<th scope='col'>" . __s('Carry over', 'sprint') . "</th>";
            echo "<th scope='col'>" . __s('Reason', 'sprint') . "</th>";
            echo "</tr></thead><tbody>";

            foreach ($items as $row) {
                $itemId     = (int)$row['id'];
                $isManual   = (string)($row['itemtype'] ?? '') === '' || (int)($row['items_id'] ?? 0) <= 0;
                $isFastlane = (int)($row['is_fastlane'] ?? 0) === 1;
                $capacity   = $isFastlane ? 0.0 : (float)($row['capacity'] ?? 0);
                $credits    = $canCredits ? (float)($row['credits'] ?? 0) : 0.0;
                $itemStatus = (string)($row['status'] ?? '');
                $statusCls  = 'sprint-status-' . str_replace('_', '-', $itemStatus);
                $typeLabel  = $types[(string)($row['itemtype'] ?? '')] ?? (string)$row['itemtype'];
                $ownerId    = (int)($row['users_id'] ?? 0);
                $itemName   = plugin_sprint_html((string)($row['name'] ?? ''));
                $group      = 'sprint_closeout_action_' . $itemId;

                echo "<tr class='sprint-closeout-row' data-item-id='{$itemId}' data-manual='" . ($isManual ? 1 : 0) . "'>";

                echo "<td><div class='sprint-closeout-name'>" . ($itemName !== '' ? $itemName : '#' . $itemId) . "</div>";
                echo "<div class='sprint-closeout-meta'>" . htmlescape((string)$typeLabel);
                if ($isFastlane) {
                    echo " · " . __s('Fastlane', 'sprint');
                }
                if ($capacity > 0) {
                    echo " · " . htmlescape(SprintMember::formatCapacity($capacity)) . "%";
                }
                if ($credits > 0) {
                    echo " · " . htmlescape(sprintf(__('%s credits', 'sprint'), SprintCustomer::formatCredits($credits)));
                }
                echo "</div><div class='sprint-closeout-outcome' aria-live='polite' hidden></div></td>";

                echo "<td><span class='badge sprint-badge sprint-badge-sm " . htmlescape($statusCls) . "'>"
                    . htmlescape((string)($statuses[$itemStatus] ?? $itemStatus)) . "</span></td>";

                echo "<td>" . ($ownerId > 0 ? htmlescape(SprintCache::userName($ownerId)) : "<span class='text-muted'>—</span>") . "</td>";

                // Three-way choice. Manual items have no backlog row to return to.
                echo "<td><div class='sprint-closeout-choice' role='radiogroup' aria-label='" . __s('What happens', 'sprint') . "'>";
                $choices = [
                    self::ACTION_CARRY   => ['fas fa-forward', __('Carry over', 'sprint'), !$targets],
                    self::ACTION_BACKLOG => ['fas fa-inbox', __('Back to backlog', 'sprint'), $isManual],
                    self::ACTION_LEAVE   => ['', __('Leave', 'sprint'), false],
                ];
                foreach ($choices as $value => [$icon, $label, $disabled]) {
                    if ($value === self::ACTION_BACKLOG && $isManual) {
                        continue;
                    }
                    echo "<label class='sprint-closeout-option'>"
                        . "<input type='radio' class='sprint-closeout-action' name='" . $group . "' value='" . $value . "'"
                        . ($value === self::ACTION_LEAVE ? ' checked' : '') . ($disabled ? ' disabled' : '') . ">"
                        . "<span>" . ($icon !== '' ? "<i class='" . $icon . " me-1' aria-hidden='true'></i>" : '')
                        . htmlescape($label) . "</span></label>";
                }
                echo "</div>";
                if ($isManual) {
                    echo "<div class='sprint-closeout-meta'>" . __s('Manual items cannot go back to the backlog.', 'sprint') . "</div>";
                }
                echo "</td>";

                echo "<td><div class='sprint-closeout-carry' hidden>";
                echo "<select class='form-select form-select-sm sprint-closeout-target' aria-label='" . __s('Target sprint', 'sprint') . "'>";
                foreach ($targets as $targetId => $label) {
                    echo "<option value='" . (int)$targetId . "'>" . plugin_sprint_html((string)$label) . "</option>";
                }
                echo "</select>";
                if ($capacity > 0) {
                    echo "<label class='sprint-closeout-amount'><span>" . __s('Capacity (%)', 'sprint') . "</span>"
                        . "<input type='number' class='form-control form-control-sm sprint-closeout-capacity' min='0' step='0.5'"
                        . " max='" . htmlescape(SprintMember::formatCapacity($capacity)) . "'"
                        . " value='" . htmlescape(SprintMember::formatCapacity($capacity)) . "'></label>";
                }
                if ($credits > 0) {
                    echo "<label class='sprint-closeout-amount'><span>" . __s('Credits', 'sprint') . "</span>"
                        . "<input type='number' class='form-control form-control-sm sprint-closeout-credits' min='0' step='0.01'"
                        . " max='" . htmlescape(SprintCustomer::formatCredits($credits)) . "'"
                        . " value='" . htmlescape(SprintCustomer::formatCredits($credits)) . "'></label>";
                }
                echo "</div><span class='text-muted sprint-closeout-nocarry'>—</span></td>";

                echo "<td><input type='text' class='form-control form-control-sm sprint-closeout-reason' maxlength='2000'"
                    . " aria-label='" . __s('Reason', 'sprint') . "' placeholder='" . __s('Optional', 'sprint') . "'></td>";

                echo "</tr>";
            }
            echo "</tbody></table></div>";
        }

        echo "<div class='sprint-closeout-foot'>";
        echo "<span class='sprint-closeout-tally' aria-live='polite'></span>";
        echo "<a class='btn btn-outline-secondary' href='" . htmlescape($backUrl) . "'>" . __s('Cancel') . "</a>";
        echo "<button type='button' class='btn btn-primary sprint-closeout-submit'>"
            . "<i class='fas " . ($alreadyCompleted ? 'fa-check' : 'fa-flag-checkered') . " me-1' aria-hidden='true'></i>"
            . ($alreadyCompleted ? __s('Process open items', 'sprint') : __s('Complete sprint', 'sprint')) . "</button>";
        echo "</div>";

        echo "</div></div>";

        self::renderScript($alreadyCompleted);
    }

    /** Delegated handlers for the close-out screen, bound once per page. */
    private static function renderScript(bool $alreadyCompleted): void
    {
        $flags      = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
        $labelsJson = json_encode([
            'tally'        => __('Carry over: %1$d. Back to backlog: %2$d. Leave in the sprint: %3$d.', 'sprint'),
            'title'        => $alreadyCompleted ? __('Process open items', 'sprint') : __('Complete sprint', 'sprint'),
            'confirmEnd'   => $alreadyCompleted
                ? __('The moves are carried out right away.', 'sprint')
                : __('After that the sprint is completed and its credit agreements are frozen.', 'sprint'),
            'ok'           => $alreadyCompleted ? __('Process open items', 'sprint') : __('Complete sprint', 'sprint'),
            'working'      => __('Saving…', 'sprint'),
            'network'      => __('Could not reach the server. Try again.', 'sprint'),
            'failed'       => __('Request failed', 'sprint'),
            'noTarget'     => __('Please select a sprint', 'sprint'),
            'done'         => __('Done', 'sprint'),
            'requested'    => __('Requested', 'sprint'),
            'failedLabel'  => __('Failed', 'sprint'),
        ], $flags);

        echo <<<HTML
<script>
(function(){
    if (window.__sprintCloseoutBound) { return; }
    window.__sprintCloseoutBound = true;

    var L  = {$labelsJson};
    var jq = window.jQuery;

    function toast(msg, level) {
        if (!msg) { return; }
        if (window.sprintUi && typeof window.sprintUi.toast === 'function') { window.sprintUi.toast(msg, level); return; }
        if (typeof window.sprintNotify === 'function') { window.sprintNotify(msg, level); return; }
        var fn = level === 'error' ? window.glpi_toast_error : (level === 'warning' ? window.glpi_toast_warning : window.glpi_toast_info);
        if (typeof fn === 'function') { fn(msg); }
    }

    function rows(form) {
        return Array.prototype.slice.call(form.querySelectorAll('.sprint-closeout-row'));
    }
    function actionOf(row) {
        if (row.getAttribute('data-processed') === '1') { return 'leave'; }
        var checked = row.querySelector('.sprint-closeout-action:checked');
        return checked ? checked.value : 'leave';
    }
    function setAction(row, action) {
        if (row.getAttribute('data-processed') === '1') { return; }
        var input = row.querySelector('.sprint-closeout-action[value="' + action + '"]');
        if (!input || input.disabled) { return; }
        input.checked = true;
        syncRow(row);
    }
    function syncRow(row) {
        var carry = actionOf(row) === 'carry';
        var box   = row.querySelector('.sprint-closeout-carry');
        var none  = row.querySelector('.sprint-closeout-nocarry');
        if (box) { box.hidden = !carry; }
        if (none) { none.hidden = carry; }
        row.setAttribute('data-action', actionOf(row));
    }
    function tally(form) {
        var n = { carry: 0, backlog: 0, leave: 0 };
        rows(form).forEach(function(row) { n[actionOf(row)]++; });
        var out = form.querySelector('.sprint-closeout-tally');
        var text = L.tally.replace('%1\$d', n.carry).replace('%2\$d', n.backlog).replace('%3\$d', n.leave);
        if (out) { out.textContent = rows(form).length ? text : ''; }
        return text;
    }
    function clampInput(input) {
        var max = parseFloat(input.getAttribute('max'));
        var v   = parseFloat(input.value);
        if (isNaN(v) || v < 0) { v = 0; }
        if (!isNaN(max) && v > max) { v = max; }
        input.value = String(v);
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('.sprint-closeout-form'), function(form) {
            rows(form).forEach(syncRow);
            tally(form);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    document.addEventListener('change', function(e) {
        var t = e.target;
        if (!t || !t.closest) { return; }
        var form = t.closest('.sprint-closeout-form');
        if (!form) { return; }
        if (t.classList.contains('sprint-closeout-action')) {
            syncRow(t.closest('.sprint-closeout-row'));
            tally(form);
        } else if (t.classList.contains('sprint-closeout-capacity') || t.classList.contains('sprint-closeout-credits')) {
            clampInput(t);
        }
    });

    document.addEventListener('click', function(e) {
        var t = e.target;
        if (!t || !t.closest) { return; }
        var all = t.closest('.sprint-closeout-all');
        if (all) {
            e.preventDefault();
            var form   = all.closest('.sprint-closeout-form');
            var action = all.getAttribute('data-action');
            var bulk   = form.querySelector('.sprint-closeout-bulk-target');
            rows(form).forEach(function(row) {
                if (action === 'carry') {
                    var select = row.querySelector('.sprint-closeout-target');
                    if (select && bulk && bulk.value) { select.value = bulk.value; }
                }
                setAction(row, action);
            });
            tally(form);
            return;
        }
        var submit = t.closest('.sprint-closeout-submit');
        if (submit && !submit.disabled) {
            e.preventDefault();
            confirmAndSend(submit.closest('.sprint-closeout-form'), submit);
        }
    });

    function collect(form) {
        var items = {};
        var missing = false;
        rows(form).forEach(function(row) {
            var action = actionOf(row);
            if (action === 'leave') { return; }
            var entry  = { action: action, reason: (row.querySelector('.sprint-closeout-reason') || {}).value || '' };
            if (action === 'carry') {
                var target = row.querySelector('.sprint-closeout-target');
                entry.target = target ? (parseInt(target.value, 10) || 0) : 0;
                if (entry.target <= 0) { missing = true; }
                var cap = row.querySelector('.sprint-closeout-capacity');
                var cr  = row.querySelector('.sprint-closeout-credits');
                entry.capacity = cap ? cap.value : '0';
                entry.credits  = cr ? cr.value : '0';
            }
            items[row.getAttribute('data-item-id')] = entry;
        });
        return missing ? null : items;
    }

    function confirmAndSend(form, btn) {
        var items = collect(form);
        if (items === null) { toast(L.noTarget, 'error'); return; }
        var msg = tally(form) + "\\n\\n" + L.confirmEnd;
        var go  = function() { send(form, btn, items); };
        if (typeof window.sprintConfirm === 'function') {
            window.sprintConfirm(msg, go, { title: L.title, okLabel: L.ok });
        } else if (window.confirm(L.title + "\\n\\n" + msg)) {
            go();
        }
    }

    function busy(btn, on) {
        if (window.sprintUi && typeof window.sprintUi.busy === 'function') {
            window.sprintUi.busy(jq ? jq(btn) : btn, on, L.working);
            return;
        }
        btn.disabled = on;
    }

    function showResult(form, resp) {
        var box = form.querySelector('.sprint-closeout-result');
        if (box) {
            var lines = [resp.message || L.failed].concat(resp.notices || []);
            box.className = 'alert sprint-closeout-result ' + (resp.success ? 'alert-success' : 'alert-danger');
            box.style.whiteSpace = 'pre-line';
            box.textContent = lines.join("\\n");
            box.hidden = false;
            box.focus();
        }
        (resp.results || []).forEach(function(r) {
            var row = form.querySelector('.sprint-closeout-row[data-item-id="' + parseInt(r.id, 10) + '"]');
            if (!row) { return; }
            var out = row.querySelector('.sprint-closeout-outcome');
            if (out) {
                var label = r.status === 'done' ? L.done : (r.status === 'requested' ? L.requested : L.failedLabel);
                out.className = 'sprint-closeout-outcome sprint-closeout-outcome-' + r.status;
                out.textContent = label + (r.message ? ': ' + r.message : '');
                out.hidden = false;
            }
            // A handled row is not sent again on a second attempt.
            if (r.status !== 'failed') {
                row.setAttribute('data-processed', '1');
                Array.prototype.forEach.call(row.querySelectorAll('input, select'), function(el) { el.disabled = true; });
                syncRow(row);
            }
        });
        tally(form);
    }

    function send(form, btn, items) {
        if (!jq) { toast(L.failed, 'error'); return; }
        busy(btn, true);
        var fail = function(msg) {
            busy(btn, false);
            toast(msg, 'error');
        };
        jq.ajax({ url: form.getAttribute('data-token-url'), type: 'GET', dataType: 'json', cache: false })
            .done(function(tok) {
                if (!tok || !tok.token) { fail(L.network); return; }
                jq.ajax({
                    url: form.getAttribute('data-endpoint'),
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        _glpi_csrf_token: tok.token,
                        sprint_id: form.getAttribute('data-sprint-id'),
                        complete: form.getAttribute('data-complete'),
                        reason: (form.querySelector('.sprint-closeout-shared-reason') || {}).value || '',
                        items: items
                    }
                }).done(function(resp) {
                    resp = resp || {};
                    if (resp.success && resp.redirect) {
                        // The summary travels as a GLPI message to the sprint page.
                        window.location.href = resp.redirect;
                        return;
                    }
                    busy(btn, false);
                    showResult(form, resp);
                    toast(resp.message || L.failed, resp.success ? 'success' : 'error');
                }).fail(function() { fail(L.network); });
            })
            .fail(function() { fail(L.network); });
    }
})();
</script>
HTML;
    }
}
