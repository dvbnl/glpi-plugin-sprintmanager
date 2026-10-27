<?php

namespace GlpiPlugin\Sprint;

use CommonGLPI;
use Html;
use Plugin;
use Session;

class SprintAgility extends CommonGLPI
{
    public static $rightname = 'plugin_sprint_sprint';

    public const IMPROVEMENTS_TABLE = 'glpi_plugin_sprint_sprintimprovements';
    public const FOLLOWUPS_TABLE    = 'glpi_plugin_sprint_sprintimprovementfollowups';
    public const VOTES_TABLE        = 'glpi_plugin_sprint_sprintimprovementvotes';

    /** How many top-voted retro inputs become actions once voting closes. */
    public const RETRO_TOP_ACTIONS = 3;

    public static function getTypeName($nb = 0): string
    {
        return __('Agility', 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-magic';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        return $item instanceof Sprint
            ? self::createTabEntry(self::getTypeName(), 0, $item::getType(), self::getIcon())
            : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Sprint) {
            return false;
        }
        self::showForSprint($item);
        return true;
    }

    /** @return string[] */
    public static function checklist(string $raw): array
    {
        $decoded = json_decode($raw, true);
        $values = is_array($decoded) ? $decoded : preg_split('/[\r\n]+/', $raw);
        $out = [];
        foreach ((array)$values as $value) {
            $value = trim((string)$value);
            if ($value !== '' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }
        return $out;
    }

    /** @return array<string,int> */
    public static function getWipLimits(array $sprint): array
    {
        $limits = json_decode((string)($sprint['wip_limits'] ?? ''), true);
        if (!is_array($limits)) {
            $limits = [];
        }
        $out = [];
        foreach (SprintItem::getAllStatuses() as $status => $label) {
            $out[$status] = max(0, (int)($limits[$status] ?? 0));
        }
        return $out;
    }

    /**
     * @return array{ok:bool,message:string,overridden:bool} `overridden` is
     *   true when the Definition of Done was incomplete but the current user
     *   is the sprint's Scrum Master and may move the item regardless; the
     *   message then lists the skipped checks for a warning instead of an error.
     *   Pass $allowScrumMasterOverride = false for automated moves (linked-status
     *   sync) so the DoD stays strict regardless of who triggered them.
     */
    public static function validateTransition(SprintItem $item, string $newStatus, bool $allowScrumMasterOverride = true): array
    {
        $sprintId = (int)($item->fields['plugin_sprint_sprints_id'] ?? 0);
        if ($sprintId <= 0 || $newStatus === (string)($item->fields['status'] ?? '')) {
            return ['ok' => true, 'message' => '', 'overridden' => false];
        }
        $sprint = new Sprint();
        if (!$sprint->getFromDB($sprintId)) {
            return ['ok' => true, 'message' => '', 'overridden' => false];
        }

        // Limits apply per owner; fastlane items neither count nor get blocked.
        $limits = self::getWipLimits($sprint->fields);
        $limit = (int)($limits[$newStatus] ?? 0);
        $isFastlane = (int)($item->fields['is_fastlane'] ?? 0) === 1;
        if (!$isFastlane && (int)($sprint->fields['wip_hard'] ?? 0) === 1 && $limit > 0) {
            $used = countElementsInTable(SprintItem::getTable(), [
                'plugin_sprint_sprints_id' => $sprintId,
                'status' => $newStatus,
                'users_id' => (int)($item->fields['users_id'] ?? 0),
                'is_fastlane' => 0,
                ['NOT' => ['id' => (int)$item->getID()]],
            ]);
            if ($used >= $limit) {
                return ['ok' => false, 'message' => sprintf(
                    __('WIP limit reached for this column (%d per person). Finish work before starting more.', 'sprint'),
                    $limit
                ), 'overridden' => false];
            }
        }

        if (in_array($newStatus, [SprintItem::STATUS_REVIEW, SprintItem::STATUS_DONE], true)) {
            $required = Config::getDefinitionDone();
            $checked  = self::checklist((string)($item->fields['done_checks'] ?? ''));
            $missing  = array_values(array_diff($required, $checked));
            if ($missing) {
                // The Scrum Master may overrule the DoD: an item should never
                // stay locked because a check cannot (or need not) be ticked.
                if ($allowScrumMasterOverride && SprintItem::currentUserIsScrumMasterOf($sprintId)) {
                    return ['ok' => true, 'overridden' => true, 'message' => sprintf(
                        __('Definition of Done overruled by the Scrum Master — skipped: %s', 'sprint'),
                        implode(', ', $missing)
                    )];
                }
                return ['ok' => false, 'message' => sprintf(
                    __('Definition of Done incomplete: %s', 'sprint'),
                    implode(', ', $missing)
                ), 'overridden' => false];
            }
        }
        return ['ok' => true, 'message' => '', 'overridden' => false];
    }

    public static function readiness(array $item): array
    {
        $required = Config::getDefinitionReady();
        $checked  = self::checklist((string)($item['ready_checks'] ?? ''));
        $missing  = array_values(array_diff($required, $checked));
        if ((int)($item['users_id'] ?? 0) <= 0) $missing[] = __('Owner', 'sprint');
        if ((float)($item['capacity'] ?? 0) <= 0) $missing[] = __('Capacity', 'sprint');
        return ['ready' => count($missing) === 0, 'missing' => array_values(array_unique($missing))];
    }

    public static function syncLinkedStatus($linked): void
    {
        global $DB;
        $type = $linked->getType();
        if (!in_array($type, ['Ticket', 'Change', 'Problem', 'ProjectTask'], true)) return;

        $closed = SprintItem::linkedItemClosedFor($type, (int)$linked->getID());
        foreach ($DB->request(['FROM' => SprintItem::getTable(), 'WHERE' => ['itemtype' => $type, 'items_id' => (int)$linked->getID()]]) as $row) {
            $sprint = new Sprint();
            if (!$sprint->getFromDB((int)$row['plugin_sprint_sprints_id']) || (int)($sprint->fields['sync_linked_status'] ?? 0) !== 1) continue;
            $rules = json_decode((string)($sprint->fields['linked_status_rules'] ?? ''), true);
            $target = (string)($rules[$type][$closed ? 'closed' : 'open'] ?? ($closed ? SprintItem::STATUS_DONE : ''));
            if ($target === '' || !isset(SprintItem::getAllStatuses()[$target]) || $target === $row['status']) continue;
            $item = new SprintItem(); $item->fields = $row;
            $policy = self::validateTransition($item, $target, false);
            if ($policy['ok']) {
                SprintItem::applyAutomatedStatus((int)$row['id'], $target);
            }
        }
    }

    public static function signal(int $sprintId, int $userId, string $type, string $message, string $url = ''): void
    {
        global $DB;
        if ($userId <= 0 || !$DB->tableExists('glpi_plugin_sprint_sprintsignals')) return;
        $DB->insert('glpi_plugin_sprint_sprintsignals', [
            'plugin_sprint_sprints_id' => $sprintId,
            'users_id' => $userId,
            'signal_type' => substr($type, 0, 40),
            'message' => $message,
            'url' => mb_substr($url, 0, 1000),
            'is_read' => 0,
            'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    /** Mark approval signals read for sprints with no pending requests left ($sprintId = 0: all). */
    public static function resolveApprovalSignals(int $sprintId = 0): void
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_sprint_sprintsignals') || !SprintRequest::ensureTable()) return;
        $where = ['signal_type' => ['approval', 'stale_approval'], 'is_read' => 0];
        if ($sprintId > 0) $where['plugin_sprint_sprints_id'] = $sprintId;
        $sprintIds = [];
        foreach ($DB->request(['SELECT' => 'plugin_sprint_sprints_id', 'DISTINCT' => true, 'FROM' => 'glpi_plugin_sprint_sprintsignals', 'WHERE' => $where]) as $row) {
            $sprintIds[] = (int)$row['plugin_sprint_sprints_id'];
        }
        foreach ($sprintIds as $sid) {
            $pending = countElementsInTable(SprintRequest::getTable(), [
                'plugin_sprint_sprints_id' => $sid, 'status' => SprintRequest::STATUS_PENDING,
            ]);
            if ($pending === 0) {
                $DB->update('glpi_plugin_sprint_sprintsignals', ['is_read' => 1], [
                    'plugin_sprint_sprints_id' => $sid, 'signal_type' => ['approval', 'stale_approval'], 'is_read' => 0,
                ]);
            }
        }
    }

    private static function signalOnce(int $sprintId, int $userId, string $type, string $message, string $url = ''): bool
    {
        global $DB;
        $since = date('Y-m-d H:i:s', time() - DAY_TIMESTAMP);
        $exists = iterator_count($DB->request(['FROM' => 'glpi_plugin_sprint_sprintsignals', 'WHERE' => [
            'plugin_sprint_sprints_id' => $sprintId, 'users_id' => $userId,
            'signal_type' => $type, 'message' => $message, ['date_creation' => ['>=', $since]],
        ]]));
        if ($exists) return false;
        self::signal($sprintId, $userId, $type, $message, $url);
        return true;
    }

    public static function cronInfo(string $name): array
    {
        return ['description' => __('Create SprintManager reminders and escalation signals', 'sprint')];
    }

    public static function cronSprintSignals(\CronTask $task): int
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_sprint_sprintsignals')) return 0;
        $created = 0; $now = date('Y-m-d H:i:s'); $soon = date('Y-m-d H:i:s', time() + DAY_TIMESTAMP);
        foreach ($DB->request(['FROM' => SprintMeeting::getTable(), 'WHERE' => [
            ['date_meeting' => ['>=', $now]], ['date_meeting' => ['<=', $soon]],
        ]]) as $meeting) {
            $sprintId = (int)$meeting['plugin_sprint_sprints_id'];
            $recipients = array_filter(array_map('intval', array_keys(SprintMember::getSprintMemberOptions($sprintId))));
            $recipients[] = (int)$meeting['users_id'];
            foreach (array_unique($recipients) as $recipient) {
                $created += self::signalOnce(
                    $sprintId, $recipient, 'meeting',
                    sprintf(__('Meeting “%s” starts within 24 hours.', 'sprint'), $meeting['name']),
                    SprintMeeting::getFormURLWithID((int)$meeting['id'])
                ) ? 1 : 0;
            }
        }
        $cutoff = date('Y-m-d H:i:s', time() - 2 * DAY_TIMESTAMP);
        foreach ($DB->request(['FROM' => SprintRequest::getTable(), 'WHERE' => ['status' => SprintRequest::STATUS_PENDING, ['date_creation' => ['<=', $cutoff]]]]) as $request) {
            $sprint = new Sprint();
            if ($sprint->getFromDB((int)$request['plugin_sprint_sprints_id'])) {
                $created += self::signalOnce((int)$sprint->getID(), (int)$sprint->fields['users_id'], 'stale_approval', __('An approval request has been waiting for more than two days.', 'sprint'), Sprint::getFormURLWithID((int)$sprint->getID())) ? 1 : 0;
            }
        }
        $tomorrow = date('Y-m-d', time() + DAY_TIMESTAMP);
        foreach ($DB->request(['FROM' => 'glpi_plugin_sprint_sprintimprovements', 'WHERE' => ['status' => 'open', ['due_date' => ['<=', $tomorrow]], ['NOT' => ['users_id' => 0]]] + self::ownActionCriteria()]) as $action) {
            $created += self::signalOnce((int)$action['plugin_sprint_sprints_id'], (int)$action['users_id'], 'retro_action', sprintf(__('Improvement action due: %s', 'sprint'), $action['description']), Sprint::getFormURLWithID((int)$action['plugin_sprint_sprints_id'])) ? 1 : 0;
        }
        foreach ($DB->request(['FROM' => Sprint::getTable(), 'WHERE' => ['status' => Sprint::STATUS_ACTIVE]]) as $activeSprint) {
            $sprintId = (int)$activeSprint['id'];
            $usedBySprint = SprintMember::usedCapacityBySprint($sprintId);
            foreach ((new SprintMember())->find(['plugin_sprint_sprints_id' => $sprintId]) as $member) {
                $uid = (int)$member['users_id'];
                $available = self::effectiveCapacity($sprintId, $uid, (float)$member['capacity_percent']);
                $used = (float)($usedBySprint[$uid]['total'] ?? 0.0);
                if ($used > $available) {
                    $created += self::signalOnce($sprintId, $uid, 'capacity', sprintf(__('Capacity overloaded: %s%% used of %s%% available.', 'sprint'), SprintMember::formatCapacity($used), SprintMember::formatCapacity($available)), Sprint::getFormURLWithID($sprintId)) ? 1 : 0;
                }
            }
        }
        $task->addVolume($created);
        return $created > 0 ? 1 : 0;
    }

    private static function rows(string $table, array $criteria, array $order = []): array
    {
        global $DB;
        if (!$DB->tableExists($table)) return [];
        $query = ['FROM' => $table, 'WHERE' => $criteria];
        if ($order) $query['ORDER'] = $order;
        return iterator_to_array($DB->request($query));
    }

    public static function effectiveCapacity(int $sprintId, int $userId, float $base): float
    {
        static $factorCache = [];
        $key = $sprintId . ':' . $userId;
        if (isset($factorCache[$key])) return SprintMember::normalizeCapacity($base * $factorCache[$key]);
        $sprint = new Sprint();
        if ($sprintId <= 0 || $userId <= 0 || !$sprint->getFromDB($sprintId)) return $base;
        $start = new \DateTime(substr((string)($sprint->fields['date_start'] ?? ''), 0, 10) ?: 'today');
        $end = new \DateTime(substr((string)($sprint->fields['date_end'] ?? ''), 0, 10) ?: 'today');
        if ($end < $start) return $base;
        $days = [];
        for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
            if ((int)$d->format('N') <= 5) $days[$d->format('Y-m-d')] = 100.0;
        }
        if (!$days) return $base;
        foreach (self::rows('glpi_plugin_sprint_sprintavailabilities', ['plugin_sprint_sprints_id' => $sprintId, 'users_id' => $userId]) as $row) {
            foreach ($days as $date => $pct) {
                if ($date >= $row['date_start'] && $date <= $row['date_end']) $days[$date] = (float)$row['availability_percent'];
            }
        }
        $factorCache[$key] = (array_sum($days) / count($days)) / 100;
        return SprintMember::normalizeCapacity($base * $factorCache[$key]);
    }

    private static function forecast(int $sprintId, array $items): array
    {
        global $DB;
        $total = $done = 0;
        foreach ($items as $row) {
            $points = max(0, (int)($row['story_points'] ?? 0));
            $total += $points;
            if (($row['status'] ?? '') === SprintItem::STATUS_DONE) $done += $points;
        }
        $sprint = new Sprint();
        $sprint->getFromDB($sprintId);
        // A finished sprint has an outcome, not a forecast.
        if (in_array((string)($sprint->fields['status'] ?? ''), [Sprint::STATUS_COMPLETED, Sprint::STATUS_CANCELLED], true)) {
            $pct = $total > 0 ? (int)round(100 * $done / $total) : 100;
            return ['total' => $total, 'done' => $done, 'expected' => $done, 'safe' => $done, 'confidence' => max(0, min(100, $pct)), 'samples' => 0];
        }
        $start = strtotime((string)($sprint->fields['date_start'] ?? '')) ?: time();
        $end = strtotime((string)($sprint->fields['date_end'] ?? '')) ?: time();
        $elapsed = max(1, min($end, time()) - $start);
        $duration = max(1, $end - $start);
        $pace = $done / $elapsed;
        $paceExpected = min($total, (int)round($done + $pace * max(0, $end - time())));

        // The lower 15th percentile is the safe commitment.
        $history = [];
        $criteria = ['status' => Sprint::STATUS_COMPLETED, 'entities_id' => (int)($sprint->fields['entities_id'] ?? 0)];
        $pastIds = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Sprint::getTable(), 'WHERE' => $criteria, 'ORDER' => ['date_end DESC'], 'LIMIT' => 30]) as $past) if ((int)$past['id'] !== $sprintId) $pastIds[] = (int)$past['id'];
        $pointsBySprint = array_fill_keys($pastIds, 0);
        if ($pastIds) foreach ($DB->request(['SELECT' => ['plugin_sprint_sprints_id', 'story_points'], 'FROM' => SprintItem::getTable(), 'WHERE' => ['plugin_sprint_sprints_id' => $pastIds, 'status' => SprintItem::STATUS_DONE]]) as $row) {
            $sid = (int)$row['plugin_sprint_sprints_id'];
            $pointsBySprint[$sid] += max(0, (int)$row['story_points']);
        }
        $history = array_values($pointsBySprint);
        sort($history);
        if ($history) {
            $p50 = $history[(int)floor((count($history) - 1) * .50)];
            $safe = $history[(int)floor((count($history) - 1) * .15)];
            $expected = max($done, min($total, (int)round(($p50 + $paceExpected) / 2)));
            $hits = count(array_filter($history, fn($v) => $v >= $total));
            $confidence = (int)round(100 * $hits / count($history));
        } else {
            $expected = $paceExpected;
            $safe = $expected;
            $ratio = $total > 0 ? min(1, $expected / $total) : 1;
            $confidence = (int)round(100 * $ratio * (0.65 + 0.35 * min(1, $elapsed / $duration)));
        }
        return ['total' => $total, 'done' => $done, 'expected' => $expected, 'safe' => min($total, (int)$safe), 'confidence' => max(0, min(100, $confidence)), 'samples' => count($history)];
    }

    public static function showForSprint(Sprint $sprint): void
    {
        global $DB;
        $id = (int)$sprint->getID();
        $canEdit = Sprint::canUpdate() && Config::isCurrentUserScrumMaster($id);
        Sprint::renderHeaderBar($sprint);

        $items = iterator_to_array($DB->request(['FROM' => SprintItem::getTable(), 'WHERE' => ['plugin_sprint_sprints_id' => $id]]));
        $forecast = self::forecast($id, $items);
        $limits = self::getWipLimits($sprint->fields);
        $form = Plugin::getWebDir('sprint') . '/front/sprintagility.form.php';

        $isCompleted   = in_array((string)($sprint->fields['status'] ?? ''), [Sprint::STATUS_COMPLETED, Sprint::STATUS_CANCELLED], true);
        $doneItems     = count(array_filter($items, fn($r) => ($r['status'] ?? '') === SprintItem::STATUS_DONE));
        $openActions   = count(self::rows('glpi_plugin_sprint_sprintimprovements', ['plugin_sprint_sprints_id' => $id, 'status' => 'open'] + self::ownActionCriteria()))
            + count(self::getCarriedOpenActions($sprint->fields));
        $cards = $isCompleted
            ? [
                [__('Delivered', 'sprint'), $forecast['done'] . ' / ' . $forecast['total'] . ' SP', 'fas fa-chart-line'],
                [__('Sprint result', 'sprint'), $forecast['confidence'] . '%', 'fas fa-flag-checkered'],
                [__('Items completed', 'sprint'), $doneItems . ' / ' . count($items), 'fas fa-clipboard-check'],
                [__('Open improvements', 'sprint'), $openActions, 'fas fa-lightbulb'],
            ]
            : [
                [__('Forecast', 'sprint'), $forecast['expected'] . ' / ' . $forecast['total'] . ' SP', 'fas fa-chart-line'],
                [__('Completion confidence', 'sprint'), $forecast['confidence'] . '%', 'fas fa-cloud-sun'],
                [__('Ready to start (DoR)', 'sprint'), count(array_filter($items, fn($r) => self::readiness($r)['ready'])) . ' / ' . count($items), 'fas fa-clipboard-check'],
                [__('Open improvements', 'sprint'), $openActions, 'fas fa-lightbulb'],
            ];
        echo "<div class='sprint-stat-cards'>";
        foreach ($cards as [$label, $value, $icon]) {
            echo "<div class='sprint-stat-card'><div class='sprint-stat-icon'><i class='{$icon}'></i></div><div class='sprint-stat-text'><div class='sprint-stat-value'>" . htmlescape((string)$value) . "</div><div class='sprint-stat-label'>" . htmlescape($label) . "</div></div></div>";
        }
        echo '</div>';

        self::lifecycleHeading(__('Before the sprint', 'sprint'), __('Set the conditions for a realistic and controlled sprint.', 'sprint'));
        self::renderEpics($id, $items, $form, $canEdit);
        self::renderPolicies($sprint, $limits, $form, $canEdit);

        self::lifecycleHeading(__('Sprint planning', 'sprint'), __('Test the plan and divide the work across the team.', 'sprint'));
        self::renderScenarioPlanner($id, $forecast, $canEdit);
        self::renderPlanningSuggestions($sprint, $items, $form, $canEdit);

        self::lifecycleHeading(__('During the sprint', 'sprint'), __('Monitor risks, actions and quality while work is in progress.', 'sprint'));
        self::renderMemberPace($sprint, $items);
        self::renderSlaRisks($items, $sprint);
        self::renderSignals($id, $form);

        self::lifecycleHeading(__('Review and retrospective', 'sprint'), __('Turn lessons from the sprint into owned improvement actions.', 'sprint'));
        // Read-only overview — input/voting happen in the guided retro meeting.
        self::renderImprovements($id, $form, $canEdit, false);

        self::renderAjaxSaveScript();
    }

    /** Current user facilitates the given meeting, and it belongs to the sprint. */
    private static function isCurrentUserMeetingFacilitator(int $sprintId, int $meetingId): bool
    {
        if ($sprintId <= 0 || $meetingId <= 0) {
            return false;
        }
        return countElementsInTable(SprintMeeting::getTable(), [
            'id'                       => $meetingId,
            'plugin_sprint_sprints_id' => $sprintId,
            'users_id'                 => (int)Session::getLoginUserID(),
        ]) > 0;
    }

    /** Sprint member (or Scrum Master) — may contribute retro input. */
    public static function isCurrentUserMember(int $sprintId): bool
    {
        $uid = (int)Session::getLoginUserID();
        if ($uid <= 0 || $sprintId <= 0) {
            return false;
        }
        return Config::isCurrentUserScrumMaster($sprintId)
            || countElementsInTable(SprintMember::getTable(), [
                'plugin_sprint_sprints_id' => $sprintId,
                'users_id'                 => $uid,
            ]) > 0;
    }

    /** AJAX saves: vote/toggle/add/signal_read patch the DOM in place (fresh CSRF token per submit), the rest reloads the tab. */
    private static function renderAjaxSaveScript(): void
    {
        $savedMsg = addslashes(__('Saved', 'sprint'));
        $errMsg   = addslashes(__('Could not save agility settings', 'sprint'));
        $tokenUrl = Plugin::getWebDir('sprint') . '/ajax/csrftoken.php';
        echo <<<HTML
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }

    function setEnabled(\$form, on) {
        \$form.find('button').prop('disabled', !on);
        if (\$form.attr('id')) {
            jQuery("button[form='" + \$form.attr('id') + "']").prop('disabled', !on);
        }
    }

    function reloadWholeTab() {
        if (typeof window.reloadTab === 'function') { window.reloadTab(); }
        else { window.location.reload(); }
    }

    // New 0-vote row lands before older 0-vote rows, after voted ones.
    function insertImprovementRow(\$form, newId) {
        var \$table = \$form.closest('.sprint-collapsible-body').find('table tbody');
        if (!\$table.length) { reloadWholeTab(); return; }
        var lbl = function(k){ return String(\$form.data('lbl-' + k) || ''); };
        var category = \$form.find("select[name='category']").val();
        var owner = \$form.find("select[name='users_id'] option:selected").text();
        if (\$form.find("input[name='is_anonymous']").is(':checked') && category !== 'action') {
            owner = lbl('anon');
        }
        var \$tr = jQuery('<tr>').attr('data-improvement-id', newId);
        jQuery('<span>').addClass('badge bg-blue-lt')
            .text(\$form.find("select[name='category'] option:selected").text())
            .appendTo(jQuery('<td>').appendTo(\$tr));
        jQuery('<td>').text(\$form.find("input[name='description']").val()).appendTo(\$tr);
        jQuery('<td>').text(owner).appendTo(\$tr);
        jQuery('<td>').text(\$form.find("input[name='due_date']").val() || '').appendTo(\$tr);
        var \$votes = jQuery('<td>').append(jQuery('<span>').addClass('sprint-imp-votes').text('0')).appendTo(\$tr);
        \$votes.append(' ').append(miniForm(\$form, newId, 'improvement_vote')
            .append(jQuery("<button class='btn btn-sm btn-link sprint-imp-vote text-muted'>")
                .attr('title', lbl('vote')).append("<i class='far fa-thumbs-up'></i>")));
        var \$status = jQuery('<td>').append(
            jQuery('<span>').addClass('sprint-imp-status')
                .attr({'data-status': 'open', 'data-lbl-open': lbl('open'), 'data-lbl-done': lbl('done')})
                .text(lbl('open'))
        ).appendTo(\$tr);
        if (parseInt(\$form.data('can-toggle'), 10) === 1) {
            \$status.append(' ').append(miniForm(\$form, newId, 'improvement_toggle')
                .append(jQuery("<button class='btn btn-sm btn-outline-success'>")
                    .attr('title', lbl('toggle')).append("<i class='fas fa-check'></i>")));
        }
        var \$anchor = null;
        \$table.find('tr').each(function(){
            if (!\$anchor && (parseInt(jQuery(this).find('.sprint-imp-votes').text(), 10) || 0) <= 0) {
                \$anchor = jQuery(this);
            }
        });
        if (\$anchor) { \$anchor.before(\$tr); } else { \$table.append(\$tr); }
    }

    function miniForm(\$src, id, action) {
        var \$f = jQuery("<form method='post' class='d-inline'>").attr('action', \$src.attr('action'));
        jQuery('<input type="hidden" name="sprint_id">').val(\$src.find("input[name='sprint_id']").val()).appendTo(\$f);
        jQuery('<input type="hidden" name="id">').val(id).appendTo(\$f);
        jQuery('<input type="hidden" name="action">').val(action).appendTo(\$f);
        return \$f;
    }

    jQuery(document).off('submit.sprintAgility').on('submit.sprintAgility', "form[action*='sprintagility.form.php']", function(e){
        e.preventDefault();
        var \$form  = jQuery(this);
        var action = String(\$form.find("input[name='action']").val() || '');
        setEnabled(\$form, false);

        jQuery.ajax({ url: "{$tokenUrl}", type: 'GET', dataType: 'json', cache: false })
        .then(function(tok){
            var data = \$form.serializeArray().filter(function(p){ return p.name !== '_glpi_csrf_token'; });
            data.push({ name: '_glpi_csrf_token', value: tok && tok.token ? tok.token : '' });
            data.push({ name: '_ajax', value: 1 });
            return jQuery.ajax({ url: \$form.attr('action'), type: 'POST', dataType: 'json', data: jQuery.param(data) });
        })
        .done(function(resp){
            if (!resp || !resp.success) {
                if (window.glpi_toast_error) { window.glpi_toast_error((resp && resp.message) || '{$errMsg}'); }
                setEnabled(\$form, true);
                return;
            }
            switch (action) {
                case 'improvement_vote': {
                    var \$btn   = \$form.find('button');
                    var voted  = \$btn.find('i').hasClass('fas');
                    var \$count = \$form.closest('td').find('.sprint-imp-votes');
                    \$count.text(Math.max(0, (parseInt(\$count.text(), 10) || 0) + (voted ? -1 : 1)));
                    \$btn.toggleClass('text-muted', voted);
                    \$btn.find('i').toggleClass('fas', !voted).toggleClass('far', voted);
                    setEnabled(\$form, true);
                    break;
                }
                case 'improvement_toggle': {
                    var \$s = \$form.closest('td').find('.sprint-imp-status');
                    var toDone = \$s.attr('data-status') !== 'done';
                    \$s.attr('data-status', toDone ? 'done' : 'open').text(\$s.attr(toDone ? 'data-lbl-done' : 'data-lbl-open'));
                    setEnabled(\$form, true);
                    break;
                }
                case 'improvement': {
                    if (!resp.id) { reloadWholeTab(); break; }
                    insertImprovementRow(\$form, resp.id);
                    \$form.find("input[name='description'], input[name='due_date']").val('');
                    \$form.find("input[name='is_anonymous']").prop('checked', false);
                    setEnabled(\$form, true);
                    break;
                }
                case 'signal_read': {
                    var \$card = \$form.closest('.sprint-collapsible');
                    \$form.closest('.sprint-signal-row').remove();
                    var left = \$card.find('.sprint-signal-row').length;
                    if (left === 0) { \$card.remove(); }
                    else {
                        var \$title = \$card.find('.sprint-collapsible-header span').first();
                        \$title.text(\$title.text().replace(/\(\d+\)/, '(' + left + ')'));
                    }
                    break;
                }
                default: {
                    if (window.glpi_toast_info) { window.glpi_toast_info('{$savedMsg}'); }
                    reloadWholeTab();
                }
            }
        })
        .fail(function(){
            if (window.glpi_toast_error) { window.glpi_toast_error('{$errMsg}'); }
            setEnabled(\$form, true);
        });
    });
})();
</script>
HTML;
    }

    private static function lifecycleHeading(string $title, string $description): void
    {
        echo "<div class='mt-4 mb-2'><h2 class='h3 mb-0'>" . htmlescape($title);
        if ($description !== '') {
            echo " <i class='fas fa-circle-info text-muted ms-1' style='font-size:0.6em;vertical-align:middle;' title='" . htmlescape($description) . "'></i>";
        }
        echo '</h2></div>';
    }

    /** Collapsible card, open by default; the user's toggle persists per card. */
    private static function beginCard(string $title, string $icon, string $description = '', bool $startOpen = true): void
    {
        // Key must survive dynamic counts in titles ("My Sprint actions (3)").
        $key = 'agility-' . substr(md5($icon . preg_replace('/\d+/', '', $title)), 0, 8);
        $cls = 'sprint-collapsible' . ($startOpen ? '' : ' sprint-collapsed');
        echo "<div class='{$cls}' data-sprint-collapse-key='" . htmlescape($key) . "'>";
        echo "<div class='sprint-collapsible-header'>";
        echo "<i class='fas fa-chevron-down sprint-collapsible-chevron'></i>";
        echo "<i class='{$icon}'></i><span>" . htmlescape($title) . "</span>";
        if ($description !== '') {
            echo "<i class='fas fa-circle-info text-muted' title='" . htmlescape($description) . "'></i>";
        }
        echo "</div><div class='sprint-collapsible-body'>";
    }
    private static function endCard(): void { echo '</div></div>'; }

    private static function renderScenarioPlanner(int $sprintId, array $forecast, bool $canEdit): void
    {
        self::beginCard(
            __('Sprint planning scenario', 'sprint'),
            'fas fa-shuffle',
            __('Adjust the planned scope and expected availability to see how they affect the chance of completing the sprint. This is a planning aid and does not change sprint data.', 'sprint')
        );

        // Slider default: team's effective capacity relative to its maximum.
        $baseSum = $effSum = 0.0;
        foreach ((new SprintMember())->find(['plugin_sprint_sprints_id' => $sprintId]) as $member) {
            $base     = SprintMember::normalizeCapacity($member['capacity_percent']);
            $baseSum += $base;
            $effSum  += self::effectiveCapacity($sprintId, (int)$member['users_id'], $base);
        }
        $availDefault = $baseSum > 0 ? (int)round(100 * $effSum / $baseSum) : 100;
        $availDefault = max(10, min(120, $availDefault));

        $disabled = $canEdit ? '' : ' disabled';
        $total = max(0, (int)$forecast['total']);
        echo "<div class='row align-items-end g-3'><div class='col-md-5'><label class='form-label'>" . __('Committed scope (story points)', 'sprint') . "</label><input id='sa-scope' type='range' min='0' max='" . max(1, $total * 2) . "' value='{$total}' class='form-range'{$disabled}><div><strong id='sa-scope-label'>{$total}</strong> SP</div></div>";
        echo "<div class='col-md-4'><label class='form-label'>" . __('Expected team availability', 'sprint') . "</label><input id='sa-availability' type='range' min='10' max='120' value='{$availDefault}' class='form-range'{$disabled}><div><strong id='sa-availability-label'>{$availDefault}</strong>%"
            . " <span class='text-muted sprint-small'>" . __('(from member capacity and availability exceptions)', 'sprint') . "</span></div></div>";
        echo "<div class='col-md-3' style='min-width:0;'><div class='alert alert-info mb-0' style='display:block;min-width:0;overflow-wrap:anywhere;'><div class='sprint-small'>" . __('Scenario confidence', 'sprint') . "</div><div><strong id='sa-confidence'>" . (int)$forecast['confidence'] . "%</strong></div><div class='sprint-small'>" . sprintf(__('85%% safe: %d SP · %d historical sprints', 'sprint'), (int)$forecast['safe'], (int)$forecast['samples']) . "</div></div></div></div>";
        $baseline = max(1, (int)$forecast['expected']);
        echo "<script>(function(){var s=document.getElementById('sa-scope'),a=document.getElementById('sa-availability'),o=document.getElementById('sa-confidence');if(!s||!a)return;function u(){document.getElementById('sa-scope-label').textContent=s.value;document.getElementById('sa-availability-label').textContent=a.value;var capacity={$baseline}*(a.value/{$availDefault}),scope=Math.max(1,s.value),p=Math.max(0,Math.min(99,Math.round(100*capacity/scope)));o.textContent=p+'%';}s.addEventListener('input',u);a.addEventListener('input',u);u();})();</script>";
        self::endCard();
    }

    private static function renderPlanningSuggestions(Sprint $sprint, array $items, string $form, bool $edit): void
    {
        global $DB;
        $unassigned = array_filter($items, fn($row) => (int)($row['users_id'] ?? 0) <= 0 && ($row['status'] ?? '') !== SprintItem::STATUS_DONE);
        if (!$unassigned) return;
        $members = (new SprintMember())->find(['plugin_sprint_sprints_id' => (int)$sprint->getID()]);
        if (!$members) return;
        $loads = [];
        $usedBySprint = SprintMember::usedCapacityBySprint((int)$sprint->getID());
        foreach ($members as $member) {
            $uid = (int)$member['users_id'];
            $capacity = self::effectiveCapacity((int)$sprint->getID(), $uid, (float)$member['capacity_percent']);
            $used = (float)($usedBySprint[$uid]['total'] ?? 0.0);
            $loads[$uid] = ['name' => SprintCache::userName($uid), 'free' => max(0, $capacity - $used), 'role' => $member['role']];
        }
        // Tag familiarity per member, counted in SQL over their whole history
        // rather than loading every item they ever owned and its tags.
        $familiarity = [];
        if ($DB->tableExists('glpi_plugin_sprint_sprintitemtags')) {
            $tags  = 'glpi_plugin_sprint_sprintitemtags';
            $table = SprintItem::getTable();
            foreach ($DB->request([
                'SELECT'     => ["{$table}.users_id", "{$tags}.tag", 'COUNT' => "{$tags}.id AS n"],
                'FROM'       => $tags,
                'INNER JOIN' => [$table => ['ON' => [$tags => 'plugin_sprint_sprintitems_id', $table => 'id']]],
                'WHERE'      => ["{$table}.users_id" => array_keys($loads)],
                'GROUPBY'    => ["{$table}.users_id", "{$tags}.tag"],
            ]) as $row) {
                $uid = (int)$row['users_id']; $key = mb_strtolower((string)$row['tag']);
                $familiarity[$uid][$key] = ($familiarity[$uid][$key] ?? 0) + (int)$row['n'];
            }
        }
        $itemTags = SprintItem::getTagsForItems(array_map(fn($r) => (int)$r['id'], $unassigned));
        self::beginCard(
            __('Smart owner suggestions', 'sprint'),
            'fas fa-user-plus',
            __('Suggestions combine remaining effective capacity with experience from similarly tagged work. Review the suggestion before assigning the item.', 'sprint')
        );
        echo "<div class='table-responsive'><table class='table table-sm'><thead><tr><th>" . __('Item', 'sprint') . "</th><th>" . __('Suggested owner', 'sprint') . "</th><th>" . __('Reason', 'sprint') . '</th><th></th></tr></thead><tbody>';
        foreach ($unassigned as $row) {
            $tags = array_map('mb_strtolower', $itemTags[(int)$row['id']] ?? []); $ranked = $loads;
            foreach ($ranked as $uid => &$candidateRow) {
                $matches = array_sum(array_map(fn($tag) => (int)($familiarity[$uid][$tag] ?? 0), $tags));
                $candidateRow['matches'] = $matches; $candidateRow['score'] = $candidateRow['free'] + 5 * $matches;
            } unset($candidateRow);
            uasort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);
            $suggested = array_key_first($ranked); if (!$suggested) break; $candidate = $ranked[$suggested];
            $reason = sprintf(__('%s%% effective capacity free · role: %s', 'sprint'), SprintMember::formatCapacity($candidate['free']), htmlescape($candidate['role']));
            if ($candidate['matches'] > 0) $reason .= ' · ' . sprintf(_n('%d matching tagged item', '%d matching tagged items', $candidate['matches'], 'sprint'), $candidate['matches']);
            echo '<tr><td>' . htmlescape($row['name']) . '</td><td>' . htmlescape($candidate['name']) . '</td><td>' . $reason . '</td><td>';
            if ($edit) { echo "<form method='post' action='" . htmlescape($form) . "'>" . Html::hidden('sprint_id', ['value' => $sprint->getID()]) . Html::hidden('item_id', ['value' => $row['id']]) . Html::hidden('users_id', ['value' => $suggested]) . Html::hidden('action', ['value' => 'assign_suggestion']) . "<button class='btn btn-sm btn-outline-primary'>" . __('Assign', 'sprint') . '</button>'; Html::closeForm(); }
            echo '</td></tr>'; $loads[$suggested]['free'] = max(0, $candidate['free'] - (float)($row['capacity'] ?? 0)); uasort($loads, fn($a, $b) => $b['free'] <=> $a['free']);
        }
        echo '</tbody></table></div>'; self::endCard();
    }

    /** Fraction of the sprint's working days that have passed (0..1). */
    private static function elapsedWorkingFraction(array $sprintFields): float
    {
        $start = new \DateTime(substr((string)($sprintFields['date_start'] ?? ''), 0, 10) ?: 'today');
        $end   = new \DateTime(substr((string)($sprintFields['date_end'] ?? ''), 0, 10) ?: 'today');
        if ($end < $start) return 0.0;
        $today = new \DateTime('today');
        $total = $elapsed = 0;
        for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
            if ((int)$d->format('N') > 5) continue;
            $total++;
            if ($d <= $today) $elapsed++;
        }
        return $total > 0 ? min(1.0, $elapsed / $total) : 0.0;
    }

    private static function renderMemberPace(Sprint $sprint, array $items): void
    {
        $members = (new SprintMember())->find(['plugin_sprint_sprints_id' => (int)$sprint->getID()]);
        if (!$members || !$items) return;

        $expected = (int)round(100 * self::elapsedWorkingFraction($sprint->fields));
        $perUser = [];
        foreach ($items as $row) {
            $uid = (int)($row['users_id'] ?? 0);
            if ($uid <= 0) continue;
            $bucket = &$perUser[$uid];
            $bucket ??= ['planned' => 0, 'done' => 0, 'open' => 0, 'blocked' => 0];
            $points = max(0, (int)($row['story_points'] ?? 0));
            $bucket['planned'] += $points;
            if (($row['status'] ?? '') === SprintItem::STATUS_DONE) {
                $bucket['done'] += $points;
            } else {
                $bucket['open']++;
                if (($row['status'] ?? '') === SprintItem::STATUS_BLOCKED || (int)($row['is_blocked'] ?? 0) === 1) {
                    $bucket['blocked']++;
                }
            }
            unset($bucket);
        }

        $rows = [];
        foreach ($members as $member) {
            $uid = (int)$member['users_id'];
            $stats = $perUser[$uid] ?? ['planned' => 0, 'done' => 0, 'open' => 0, 'blocked' => 0];
            $progress = $stats['planned'] > 0 ? (int)round(100 * $stats['done'] / $stats['planned']) : null;
            $rows[] = [
                'name'     => SprintCache::userName($uid),
                'stats'    => $stats,
                'progress' => $progress,
                'delta'    => $progress === null ? null : $progress - $expected,
            ];
        }
        // Members most behind schedule float to the top.
        usort($rows, fn($a, $b) => ($a['delta'] ?? PHP_INT_MAX) <=> ($b['delta'] ?? PHP_INT_MAX));

        self::beginCard(
            __('Team pace', 'sprint'),
            'fas fa-person-running',
            sprintf(
                __('Completed story points per member against the elapsed sprint time (%d%%). A member is behind when their progress trails the elapsed time by more than 10 percentage points.', 'sprint'),
                $expected
            )
        );
        echo "<div class='table-responsive'><table class='table table-sm align-middle'><thead><tr><th>" . __('Member', 'sprint')
            . "</th><th style='min-width:180px;'>" . __('Progress', 'sprint') . "</th><th>" . __('Points', 'sprint')
            . "</th><th>" . __('Open items', 'sprint') . "</th><th>" . __('Pace', 'sprint') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $stats = $row['stats'];
            echo '<tr><td>' . htmlescape($row['name'])
                . ($stats['blocked'] > 0
                    ? " <i class='fas fa-triangle-exclamation text-danger' title='" . htmlescape(sprintf(_n('%d blocked item', '%d blocked items', $stats['blocked'], 'sprint'), $stats['blocked'])) . "'></i>"
                    : '')
                . '</td><td>';
            if ($row['progress'] === null) {
                echo "<span class='text-muted'>" . __('No sprint items', 'sprint') . '</span>';
            } else {
                echo "<div class='progress progress-sm position-relative'>"
                    . "<div class='progress-bar bg-blue' style='width:" . min(100, $row['progress']) . "%'></div>"
                    . "<span class='position-absolute top-0 bottom-0 border-start border-dark' style='left:" . $expected . "%' title='"
                    . htmlescape(sprintf(__('Elapsed sprint time: %d%%', 'sprint'), $expected)) . "'></span></div>";
            }
            echo '</td><td>' . (int)$stats['done'] . ' / ' . (int)$stats['planned'] . '</td><td>' . (int)$stats['open'] . '</td><td>';
            if ($row['delta'] === null) {
                echo "<span class='badge bg-secondary-lt'>—</span>";
            } elseif ($row['delta'] >= 10) {
                echo "<span class='badge bg-success'>" . __('Ahead of schedule', 'sprint') . '</span>';
            } elseif ($row['delta'] > -10) {
                echo "<span class='badge bg-blue-lt'>" . __('On schedule', 'sprint') . '</span>';
            } else {
                echo "<span class='badge bg-danger'>" . sprintf(__('Behind (%d%%)', 'sprint'), abs($row['delta'])) . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        self::endCard();
    }

    private static function renderSlaRisks(array $items, Sprint $sprint): void
    {
        $risks = [];
        $sprintEnd = strtotime((string)($sprint->fields['date_end'] ?? '')) ?: PHP_INT_MAX;
        foreach ($items as $row) {
            if (($row['status'] ?? '') === SprintItem::STATUS_DONE) continue;
            $score = max(0, (int)($row['priority'] ?? 0) - 2) * 5; $reasons = []; $deadline = ''; $overdue = false;
            if (($row['status'] ?? '') === SprintItem::STATUS_BLOCKED || (int)($row['is_blocked'] ?? 0) === 1) { $score += 35; $reasons[] = __('blocked', 'sprint'); }
            if (($row['status'] ?? '') === SprintItem::STATUS_DEPENDENCY) { $score += 20; $reasons[] = __('dependency', 'sprint'); }
            $age = !empty($row['date_creation']) ? (int)floor((time() - strtotime($row['date_creation'])) / DAY_TIMESTAMP) : 0;
            if ($age >= 14) { $score += 10; $reasons[] = sprintf(__('%d days old', 'sprint'), $age); }
            $linked = (!empty($row['itemtype']) && !empty($row['items_id'])) ? SprintCache::getObject((string)$row['itemtype'], (int)$row['items_id']) : null;
            if ($linked !== null) {
                foreach (['time_to_resolve', 'time_to_own', 'due_date', 'date_end'] as $field) {
                    if (!empty($linked->fields[$field])) { $deadline = (string)$linked->fields[$field]; break; }
                }
            }
            if ($deadline !== '' && (($ts = strtotime($deadline)) !== false) && $ts <= $sprintEnd) {
                $overdue = $ts < time(); $score += $overdue ? 45 : 25; $reasons[] = $overdue ? __('SLA overdue', 'sprint') : __('SLA due within sprint', 'sprint');
            }
            if ($score >= 20) $risks[] = ['name' => $row['name'], 'deadline' => $deadline, 'overdue' => $overdue, 'score' => min(100, $score), 'reasons' => $reasons];
        }
        if (!$risks) return;
        usort($risks, fn($a, $b) => $b['score'] <=> $a['score']);
        self::beginCard(
            sprintf(__('Risk radar (%d)', 'sprint'), count($risks)),
            'fas fa-stopwatch',
            __('Items receive a higher risk score when they are blocked, waiting on a dependency, getting old or approaching a linked deadline. Start with the highest score.', 'sprint')
        );
        foreach ($risks as $risk) echo "<div class='alert " . ($risk['score'] >= 60 ? 'alert-danger' : 'alert-warning') . " py-2 mb-2'><span class='badge bg-dark me-2'>" . (int)$risk['score'] . "</span><strong>" . htmlescape($risk['name']) . '</strong> · ' . htmlescape(implode(', ', $risk['reasons'])) . ($risk['deadline'] ? ' · ' . htmlescape($risk['deadline']) : '') . '</div>';
        self::endCard();
    }

    /**
     * Improvement actions have one persistent identity (1.3.1): an open
     * action with carry_to_next stays followable from later sprints of the
     * same series instead of being copied into them. Returns how many such
     * actions the sprint inherits; nothing is inserted, so creating or
     * completing sprints repeatedly can never duplicate an action.
     */
    public static function carryImprovementsToSprint(Sprint $sprint): int
    {
        if ((int)$sprint->getID() <= 0) return 0;
        return count(self::getCarriedOpenActions($sprint->fields));
    }

    public static function carryImprovementsForward(Sprint $completed): int
    {
        global $DB;
        $next = null;
        foreach ($DB->request(['FROM' => Sprint::getTable(), 'WHERE' => [
            'entities_id' => (int)($completed->fields['entities_id'] ?? 0),
            'status' => [Sprint::STATUS_PLANNED, Sprint::STATUS_ACTIVE],
            ['date_start' => ['>=', (string)($completed->fields['date_end'] ?? '')]],
        ], 'ORDER' => ['date_start ASC'], 'LIMIT' => 1]) as $row) { $next = $row; }
        if (!$next) return 0;
        $target = new Sprint();
        return $target->getFromDB((int)$next['id']) ? self::carryImprovementsToSprint($target) : 0;
    }

    /** Whether the 1.3.1 identity columns exist (they do once migrated). */
    public static function hasActionIdentity(): bool
    {
        global $DB;
        static $has = null;
        return $has ??= ($DB->tableExists(self::IMPROVEMENTS_TABLE)
            && $DB->fieldExists(self::IMPROVEMENTS_TABLE, 'origin_improvements_id'));
    }

    /** Criteria that skip legacy carry-over copies (they point at their original). */
    private static function ownActionCriteria(): array
    {
        return self::hasActionIdentity() ? ['origin_improvements_id' => 0] : [];
    }

    /** Sprint $a ran before sprint $b (start date, ids as tie-breaker for undated sprints). */
    private static function sprintPrecedes(array $a, array $b): bool
    {
        $sa = (string)($a['date_start'] ?? ''); $sb = (string)($b['date_start'] ?? '');
        return ($sa !== '' && $sb !== '') ? $sa < $sb : (int)$a['id'] < (int)$b['id'];
    }

    /**
     * Two sprints run at the same time (parallel teams). A shared boundary
     * day — one sprint ending the day the next starts — is not parallel.
     */
    public static function periodsOverlap(array $a, array $b): bool
    {
        $as = strtotime((string)($a['date_start'] ?? '')); $ae = strtotime((string)($a['date_end'] ?? ''));
        $bs = strtotime((string)($b['date_start'] ?? '')); $be = strtotime((string)($b['date_end'] ?? ''));
        if (!$as || !$ae || !$bs || !$be) return false;
        return min($ae, $be) - max($as, $bs) > DAY_TIMESTAMP;
    }

    /** @return array<int,int[]> sprint id => team (members + Scrum Master) */
    private static function sprintTeams(array $sprintRows): array
    {
        global $DB;
        $teams = [];
        foreach ($sprintRows as $row) {
            $teams[(int)$row['id']] = (int)($row['users_id'] ?? 0) > 0 ? [(int)$row['users_id']] : [];
        }
        if ($teams) {
            foreach ($DB->request(['SELECT' => ['plugin_sprint_sprints_id', 'users_id'], 'FROM' => SprintMember::getTable(), 'WHERE' => ['plugin_sprint_sprints_id' => array_keys($teams)]]) as $m) {
                $teams[(int)$m['plugin_sprint_sprints_id']][] = (int)$m['users_id'];
            }
        }
        return array_map(fn($u) => array_values(array_unique(array_filter($u))), $teams);
    }

    /** Sprints of the same entity that started before $sprint, newest first. */
    private static function earlierEntitySprints(array $sprint, array $extra = [], int $limit = 60): array
    {
        global $DB;
        $id = (int)($sprint['id'] ?? 0);
        $where = ['entities_id' => (int)($sprint['entities_id'] ?? 0), ['NOT' => ['id' => $id]]] + $extra;
        $start = (string)($sprint['date_start'] ?? '');
        $where[] = $start !== '' ? ['date_start' => ['<', $start]] : ['id' => ['<', $id]];
        $out = [];
        foreach ($DB->request(['FROM' => Sprint::getTable(), 'WHERE' => $where, 'ORDER' => ['date_start DESC', 'id DESC'], 'LIMIT' => $limit]) as $row) {
            $out[(int)$row['id']] = $row;
        }
        return $out;
    }

    /**
     * Earlier sprints of the same sprint series, newest first.
     *
     * A series is the line of sprints one team runs: same entity, same
     * template (0 = sprints created without a template) and — when the sprint
     * has a team — at least one shared member or the same Scrum Master. So
     * parallel teams in one entity stay apart as soon as they use their own
     * template or consist of different people.
     *
     * @return array<int,array> sprint rows keyed by id
     */
    public static function getEarlierSeriesSprints(array $sprint, int $limit = 20): array
    {
        if ((int)($sprint['id'] ?? 0) <= 0) return [];
        $candidates = self::earlierEntitySprints($sprint, [
            'plugin_sprint_sprinttemplates_id' => (int)($sprint['plugin_sprint_sprinttemplates_id'] ?? 0),
        ]);
        if (!$candidates) return [];
        $team = self::sprintTeams([$sprint])[(int)$sprint['id']] ?? [];
        if ($team) {
            $teams = self::sprintTeams($candidates);
            foreach (array_keys($candidates) as $cid) {
                if (!array_intersect($team, $teams[$cid] ?? [])) unset($candidates[$cid]);
            }
        }
        return array_slice($candidates, 0, $limit, true);
    }

    /**
     * Predecessor of $sprint in its series. A facilitator's explicit choice
     * ($chosenId, any earlier sprint of the entity) wins. `ambiguous` flags
     * another series sprint running in parallel with the predecessor, so the
     * facilitator gets a visible choice instead of a silent guess.
     *
     * @return array{predecessor:?array,ambiguous:bool,manual:bool,choices:array<int,string>}
     */
    public static function resolvePredecessor(array $sprint, int $chosenId = 0): array
    {
        $choiceRows = self::earlierEntitySprints($sprint, [], 25);
        $choices = [];
        foreach ($choiceRows as $cid => $row) {
            $choices[$cid] = (string)$row['name'] . (!empty($row['date_start']) ? ' (' . Html::convDate($row['date_start']) . ')' : '');
        }
        $out = ['predecessor' => null, 'ambiguous' => false, 'manual' => false, 'choices' => $choices];
        if ($chosenId > 0 && isset($choiceRows[$chosenId])) {
            $out['predecessor'] = $choiceRows[$chosenId];
            $out['manual'] = true;
            return $out;
        }
        $series = self::getEarlierSeriesSprints($sprint);
        $pred = $series ? reset($series) : null;
        if ($pred) {
            $out['predecessor'] = $pred;
            foreach ($series as $cid => $row) {
                if ($cid !== (int)$pred['id'] && self::periodsOverlap($pred, $row)) {
                    $out['ambiguous'] = true;
                    break;
                }
            }
        }
        return $out;
    }

    /** Moment of the predecessor's retrospective (its latest one), else its end date. */
    private static function previousRetroMoment(array $pred): string
    {
        $moment = '';
        foreach (self::rows(SprintMeeting::getTable(), [
            'plugin_sprint_sprints_id' => (int)$pred['id'], 'meeting_type' => SprintMeeting::TYPE_RETROSPECTIVE,
        ], ['date_meeting DESC']) as $m) {
            $moment = (string)($m['started_at'] ?: $m['date_meeting']);
            break;
        }
        return $moment !== '' ? $moment : (string)($pred['date_end'] ?? '');
    }

    /**
     * Open carry_to_next actions of earlier sprints of the series — what the
     * old carry-over used to copy in. Keyed by action id.
     */
    public static function getCarriedOpenActions(array $sprint): array
    {
        $earlier = self::getEarlierSeriesSprints($sprint);
        if (!$earlier) return [];
        $out = [];
        foreach (self::rows(self::IMPROVEMENTS_TABLE, [
            'plugin_sprint_sprints_id' => array_keys($earlier), 'category' => 'action',
            'status' => 'open', 'carry_to_next' => 1,
        ] + self::ownActionCriteria(), ['date_creation ASC']) as $row) {
            $row['source_sprint_name'] = (string)($earlier[(int)$row['plugin_sprint_sprints_id']]['name'] ?? '');
            $out[(int)$row['id']] = $row;
        }
        return $out;
    }

    /** @return array<string,string> */
    public static function helpedOptions(): array
    {
        return [
            ''       => __('Not evaluated yet', 'sprint'),
            'yes'    => __('Yes, it helped', 'sprint'),
            'partly' => __('Partly', 'sprint'),
            'no'     => __('No, it did not help', 'sprint'),
        ];
    }

    /**
     * Follow-up of earlier retrospective actions for a retrospective meeting.
     *
     * `previous`: every action agreed in the predecessor sprint, open and
     * done, so completed changes can be evaluated. `older`: carry_to_next
     * actions from older sprints of the series that were still open at the
     * previous retrospective (open now, or completed since). Every action
     * appears once, under its own identity; actions of the current sprint
     * never do (they are this retrospective's new actions).
     */
    public static function getRetroFollowUp(array $meeting): array
    {
        global $DB;
        $out = ['predecessor' => null, 'ambiguous' => false, 'manual' => false, 'choices' => [], 'previous' => [], 'older' => [], 'ids' => []];
        $sprintId  = (int)($meeting['plugin_sprint_sprints_id'] ?? 0);
        $meetingId = (int)($meeting['id'] ?? 0);
        $sprint = new Sprint();
        if ($sprintId <= 0 || !$sprint->getFromDB($sprintId) || !$DB->tableExists(self::IMPROVEMENTS_TABLE)) return $out;

        $notes  = json_decode((string)($meeting['phase_notes'] ?? ''), true);
        $chosen = is_array($notes) ? (int)($notes[SprintMeeting::PREV_SPRINT_NOTE_KEY] ?? 0) : 0;
        $out = array_merge($out, self::resolvePredecessor($sprint->fields, $chosen));
        $pred = $out['predecessor'];
        if ($pred === null || !SprintItem::sprintEntityAccessible((int)$pred['id'])) {
            $out['predecessor'] = null;
            return $out;
        }
        $predId = (int)$pred['id'];
        $older  = self::getEarlierSeriesSprints($pred);
        $since  = self::previousRetroMoment($pred);
        $own    = ['category' => 'action'] + self::ownActionCriteria();

        $actions = [];
        foreach (self::rows(self::IMPROVEMENTS_TABLE, $own + ['plugin_sprint_sprints_id' => $predId], ['date_creation ASC', 'id ASC']) as $row) {
            $actions[(int)$row['id']] = $row + ['group' => 'previous'];
        }
        $stillRelevant = fn(array $row): bool => $row['status'] === 'open' || ($since !== '' && (string)$row['date_mod'] >= $since);
        if ($older) {
            foreach (self::rows(self::IMPROVEMENTS_TABLE, $own + ['plugin_sprint_sprints_id' => array_keys($older), 'carry_to_next' => 1], ['date_creation ASC', 'id ASC']) as $row) {
                if ($stillRelevant($row)) $actions[(int)$row['id']] ??= $row + ['group' => 'older'];
            }
        }
        // Legacy carry-over copies (linked by the 1.3.1 migration) stand for
        // their original: follow the original, once.
        if (self::hasActionIdentity()) {
            $originIds = [];
            foreach (self::rows(self::IMPROVEMENTS_TABLE, [
                'plugin_sprint_sprints_id' => array_merge([$predId, $sprintId], array_keys($older)),
                'origin_improvements_id' => ['>', 0],
            ]) as $copy) {
                $originIds[] = (int)$copy['origin_improvements_id'];
            }
            if ($originIds) {
                foreach (self::rows(self::IMPROVEMENTS_TABLE, ['id' => array_values(array_unique($originIds))] + $own) as $row) {
                    if ($stillRelevant($row)) $actions[(int)$row['id']] ??= $row + ['group' => 'older'];
                }
            }
        }
        if (!$actions) return $out;

        // Source sprints / meetings, entity-checked.
        $sprintIds = array_values(array_unique(array_map(fn($r) => (int)$r['plugin_sprint_sprints_id'], $actions)));
        $sprints = [];
        foreach (self::rows(Sprint::getTable(), ['id' => $sprintIds]) as $s) $sprints[(int)$s['id']] = $s;
        $followups = [];
        foreach (self::rows(self::FOLLOWUPS_TABLE, ['plugin_sprint_sprintimprovements_id' => array_keys($actions)], ['date_creation ASC', 'id ASC']) as $fu) {
            $followups[(int)$fu['plugin_sprint_sprintimprovements_id']][] = $fu;
        }
        $meetingIds = [];
        foreach ($actions as $row) $meetingIds[] = (int)($row['plugin_sprint_sprintmeetings_id'] ?? 0);
        foreach ($followups as $list) foreach ($list as $fu) $meetingIds[] = (int)$fu['plugin_sprint_sprintmeetings_id'];
        $meetings = [];
        $meetingIds = array_values(array_filter(array_unique($meetingIds)));
        if ($meetingIds) foreach (self::rows(SprintMeeting::getTable(), ['id' => $meetingIds]) as $m) $meetings[(int)$m['id']] = $m;
        $helped = self::helpedOptions();

        foreach ($actions as $id => $row) {
            $src = $sprints[(int)$row['plugin_sprint_sprints_id']] ?? null;
            if ($src === null || !Session::haveAccessToEntity((int)$src['entities_id'], (bool)($src['is_recursive'] ?? false))) continue;
            $srcMeeting = $meetings[(int)($row['plugin_sprint_sprintmeetings_id'] ?? 0)] ?? null;
            $lastUpdate = (string)$row['date_mod'];
            $history = []; $current = null;
            foreach ($followups[$id] ?? [] as $fu) {
                if ((string)$fu['date_mod'] > $lastUpdate) $lastUpdate = (string)$fu['date_mod'];
                if ((int)$fu['plugin_sprint_sprintmeetings_id'] === $meetingId) { $current = $fu; continue; }
                $fuMeeting = $meetings[(int)$fu['plugin_sprint_sprintmeetings_id']] ?? null;
                $history[] = [
                    'meeting_name' => $fuMeeting ? (string)$fuMeeting['name'] : '',
                    'meeting_url'  => $fuMeeting ? SprintMeeting::getFormURLWithID((int)$fuMeeting['id']) : '',
                    'date'         => Html::convDateTime((string)($fu['date_mod'] ?: $fu['date_creation'])),
                    'author'       => (int)$fu['users_id'] > 0 ? SprintCache::userName((int)$fu['users_id']) : '',
                    'status'       => (string)$fu['status'],
                    'progress_note' => (string)$fu['progress_note'],
                    'evaluation'   => (string)$fu['evaluation'],
                    'helped_label' => (string)$fu['helped'] !== '' ? ($helped[$fu['helped']] ?? '') : '',
                ];
            }
            $out[$row['group']][] = [
                'id'                  => $id,
                'description'         => (string)$row['description'],
                'status'              => (string)$row['status'],
                'users_id'            => (int)$row['users_id'],
                'owner_name'          => (int)$row['users_id'] > 0 ? SprintCache::userName((int)$row['users_id']) : '',
                'due_date'            => !empty($row['due_date']) ? Html::convDate((string)$row['due_date']) : '',
                'is_overdue'          => $row['status'] !== 'done' && !empty($row['due_date']) && (string)$row['due_date'] < date('Y-m-d'),
                'last_update'         => $lastUpdate !== '' ? Html::convDateTime($lastUpdate) : '',
                'source_sprint_name'  => (string)$src['name'],
                'source_sprint_url'   => Sprint::getFormURLWithID((int)$src['id']),
                'source_meeting_name' => $srcMeeting ? (string)$srcMeeting['name'] : '',
                'source_meeting_url'  => $srcMeeting ? SprintMeeting::getFormURLWithID((int)$srcMeeting['id']) : '',
                'history'             => $history,
                'progress_note'       => (string)($current['progress_note'] ?? ''),
                'evaluation'          => (string)($current['evaluation'] ?? ''),
                'helped'              => (string)($current['helped'] ?? ''),
            ];
            $out['ids'][] = $id;
        }
        return $out;
    }

    /**
     * One-off migration (1.3.1): link the copies the old carry-over inserted
     * to their original. A copy is recognised by the rules that created it:
     * a carry_to_next action in a later sprint of the same entity with the
     * same owner, due date and text as an action of an earlier *completed*
     * sprint, created no earlier than it. Text alone never links; when the
     * nearest earlier sprint holds more than one match the row is left alone.
     * Copies stay (their sprint's history is untouched); the original takes
     * over the most recently changed status of its chain.
     */
    public static function linkLegacyCarryCopies(): int
    {
        global $DB;
        $table = self::IMPROVEMENTS_TABLE;
        if (!$DB->tableExists($table) || !$DB->fieldExists($table, 'origin_improvements_id')) return 0;
        $sprints = [];
        foreach ($DB->request(['SELECT' => ['id', 'entities_id', 'status', 'date_start', 'date_end'], 'FROM' => Sprint::getTable()]) as $s) {
            $sprints[(int)$s['id']] = $s;
        }
        $groups = []; $byId = [];
        foreach ($DB->request(['FROM' => $table, 'WHERE' => ['category' => 'action', 'carry_to_next' => 1, 'origin_improvements_id' => 0], 'ORDER' => ['date_creation ASC', 'id ASC']]) as $row) {
            $sid = (int)$row['plugin_sprint_sprints_id'];
            if (!isset($sprints[$sid])) continue;
            $key = $sprints[$sid]['entities_id'] . '|' . (int)$row['users_id'] . '|' . (string)$row['due_date'] . '|' . mb_strtolower(trim((string)$row['description']));
            $groups[$key][] = $row;
            $byId[(int)$row['id']] = $row;
        }
        $linked = 0; $rootOf = []; $chains = [];
        foreach ($groups as $rows) {
            if (count($rows) < 2) continue;
            foreach ($rows as $i => $copy) {
                $copySprint = $sprints[(int)$copy['plugin_sprint_sprints_id']];
                $best = []; $bestEnd = null;
                foreach ($rows as $j => $orig) {
                    $origSprint = $sprints[(int)$orig['plugin_sprint_sprints_id']];
                    if ($j === $i || (int)$origSprint['id'] === (int)$copySprint['id']
                        || $origSprint['status'] !== Sprint::STATUS_COMPLETED
                        || (string)$orig['date_creation'] > (string)$copy['date_creation']
                        || !self::sprintPrecedes($origSprint, $copySprint)) {
                        continue;
                    }
                    $end = (string)$origSprint['date_end'];
                    if ($bestEnd === null || $end > $bestEnd) { $best = [$orig]; $bestEnd = $end; }
                    elseif ($end === $bestEnd) { $best[] = $orig; }
                }
                if (count($best) !== 1) continue;
                $root = $rootOf[(int)$best[0]['id']] ?? (int)$best[0]['id'];
                $rootOf[(int)$copy['id']] = $root;
                $chains[$root][] = $copy;
                $DB->update($table, ['origin_improvements_id' => $root], ['id' => (int)$copy['id']]);
                $linked++;
            }
        }
        foreach ($chains as $rootId => $copies) {
            $latest = $byId[$rootId];
            foreach ($copies as $copy) {
                if ((string)$copy['date_mod'] > (string)$latest['date_mod']) $latest = $copy;
            }
            if ($latest['status'] !== $byId[$rootId]['status']) {
                $DB->update($table, ['status' => $latest['status']], ['id' => $rootId]);
            }
        }
        return $linked;
    }

    /** An action row by id; a legacy copy resolves to its original. */
    private static function actionRow(int $id): ?array
    {
        $rows = self::rows(self::IMPROVEMENTS_TABLE, ['id' => $id]);
        $row = $rows ? reset($rows) : null;
        if ($row && (int)($row['origin_improvements_id'] ?? 0) > 0) {
            $rows = self::rows(self::IMPROVEMENTS_TABLE, ['id' => (int)$row['origin_improvements_id']]);
            $row = $rows ? reset($rows) : null;
        }
        return $row ?: null;
    }

    /**
     * Status toggle and follow-up notes for an action, possibly of an earlier
     * sprint. The action must belong to $sprintId, be part of the follow-up
     * of the given retrospective of $sprintId, or be carried into $sprintId;
     * its own sprint's entity must be visible. Scrum Master and facilitator
     * may act on every followable action, the action owner (a member of
     * $sprintId) on their own action during the retrospective.
     */
    private static function handleActionFollowUp(string $action, array $input, int $sprintId): bool
    {
        global $DB;
        $row = self::actionRow((int)($input['id'] ?? 0));
        if ($row === null || ($row['category'] ?? '') === '') return false;
        $actionId  = (int)$row['id'];
        $meetingId = (int)($input['meeting_id'] ?? 0);
        $uid       = (int)Session::getLoginUserID();
        $sprint = new Sprint();
        if (!$sprint->getFromDB($sprintId)) return false;

        $meeting = null; $inFollowUp = false;
        if ($meetingId > 0) {
            $m = new SprintMeeting();
            if ($m->getFromDB($meetingId) && (int)$m->fields['plugin_sprint_sprints_id'] === $sprintId
                && (string)$m->fields['meeting_type'] === SprintMeeting::TYPE_RETROSPECTIVE) {
                $meeting = $m->fields;
                $inFollowUp = in_array($actionId, self::getRetroFollowUp($meeting)['ids'], true);
            }
        }
        $ownSprint = (int)$row['plugin_sprint_sprints_id'] === $sprintId;
        $carried   = !$ownSprint && !$inFollowUp && isset(self::getCarriedOpenActions($sprint->fields)[$actionId]);
        if (!$ownSprint && !$inFollowUp && !$carried) return false;
        if (!$ownSprint && !SprintItem::sprintEntityAccessible((int)$row['plugin_sprint_sprints_id'])) return false;

        // Same driver rule as the guided meeting (ajax/meetingphase.php).
        $isScrumMaster = SprintItem::currentUserIsScrumMasterOf($sprintId) && Sprint::canUpdate();
        $isMember      = self::isCurrentUserMember($sprintId);
        $isFacilitator = $isMember && self::isCurrentUserMeetingFacilitator($sprintId, $meetingId);
        $isOwner       = $isMember && $uid > 0 && (int)$row['users_id'] === $uid;
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        if ($action === 'improvement_toggle') {
            if (!$isScrumMaster && !$isFacilitator && !($inFollowUp && $isOwner)) return false;
            $status = $row['status'] === 'done' ? 'open' : 'done';
            if (!$DB->update(self::IMPROVEMENTS_TABLE, ['status' => $status, 'date_mod' => $now], ['id' => $actionId])) return false;
            if (self::hasActionIdentity()) {
                // Keep legacy copies in step so older sprint views agree.
                $DB->update(self::IMPROVEMENTS_TABLE, ['status' => $status], ['origin_improvements_id' => $actionId]);
            }
            return true;
        }

        // improvement_followup: one note per action per retrospective.
        if ($meeting === null || !$inFollowUp || (!$isScrumMaster && !$isFacilitator && !$isOwner)) return false;
        if (!$DB->tableExists(self::FOLLOWUPS_TABLE)) return false;
        $helped = (string)($input['helped'] ?? '');
        $fields = [
            'plugin_sprint_sprints_id' => $sprintId,
            'users_id'      => $uid,
            'status'        => (string)$row['status'],
            'progress_note' => mb_substr(trim((string)($input['progress_note'] ?? '')), 0, 5000),
            'evaluation'    => mb_substr(trim((string)($input['evaluation'] ?? '')), 0, 5000),
            'helped'        => array_key_exists($helped, self::helpedOptions()) ? $helped : '',
            'date_mod'      => $now,
        ];
        $key = ['plugin_sprint_sprintimprovements_id' => $actionId, 'plugin_sprint_sprintmeetings_id' => $meetingId];
        if (self::rows(self::FOLLOWUPS_TABLE, $key)) {
            return (bool)$DB->update(self::FOLLOWUPS_TABLE, $fields, $key);
        }
        return (bool)$DB->insert(self::FOLLOWUPS_TABLE, $key + $fields + ['date_creation' => $now]);
    }

    private static function renderPolicies(Sprint $sprint, array $limits, string $form, bool $edit): void
    {
        self::beginCard(
            __('Work in Progress (WIP) limits', 'sprint'),
            'fas fa-sliders',
            __('Work in Progress is work that has started but is not finished. Limits apply per person: each team member may have at most this many items in a column. Set limits to reduce multitasking, expose bottlenecks and help the team finish work before starting more.', 'sprint')
        );
        echo "<form method='post' action='" . htmlescape($form) . "'>" . Html::hidden('sprint_id', ['value' => $sprint->getID()]) . Html::hidden('action', ['value' => 'policy']);
        echo "<div class='row g-3 mt-1'>";
        foreach ([SprintItem::STATUS_IN_PROGRESS, SprintItem::STATUS_REVIEW, SprintItem::STATUS_DEPENDENCY] as $status) {
            echo "<div class='col-md-2'><label class='form-label'>" . htmlescape(SprintItem::getAllStatuses()[$status]) . "</label><input type='number' min='0' class='form-control' name='wip[" . htmlescape($status) . "]' value='" . (int)$limits[$status] . "'" . ($edit ? '' : ' readonly') . "></div>";
        }
        $hard = (int)($sprint->fields['wip_hard'] ?? 0) === 1 ? ' checked' : '';
        $sync = (int)($sprint->fields['sync_linked_status'] ?? 0) === 1 ? ' checked' : '';
        echo "<div class='col-md-3 pt-4'><label class='form-check'><input class='form-check-input' type='checkbox' name='wip_hard' value='1'{$hard}" . ($edit ? '' : ' disabled') . "><span class='form-check-label'>" . __('Enforce WIP limits', 'sprint') . "</span></label></div></div>";
        echo "<hr class='my-4'><h4 class='mb-1'>" . __('Linked status automation', 'sprint') . "</h4>";
        echo "<p class='text-muted mb-3'>" . __('Choose how a sprint item should react when its linked GLPI object is solved, closed or reopened.', 'sprint') . "</p>";
        echo "<label class='form-check mb-3'><input class='form-check-input' type='checkbox' name='sync_linked_status' value='1'{$sync}" . ($edit ? '' : ' disabled') . "><span class='form-check-label'>" . __('Synchronize linked item statuses', 'sprint') . "</span></label>";
        $rules = json_decode((string)($sprint->fields['linked_status_rules'] ?? ''), true); $statusOptions = SprintItem::getAllStatuses();
        echo "<div class='table-responsive mt-3'><table class='table table-sm'><thead><tr><th>" . __('Linked type', 'sprint') . "</th><th>" . __('When solved/closed', 'sprint') . "</th><th>" . __('When reopened', 'sprint') . "</th></tr></thead><tbody>";
        $typeLabels = SprintItem::getLinkedItemTypes();
        foreach (['Ticket', 'Change', 'Problem', 'ProjectTask'] as $type) {
            echo '<tr><td>' . htmlescape($typeLabels[$type] ?? $type) . "</td><td><select class='form-select form-select-sm' name='sync_rules[{$type}][closed]'><option value=''>" . __('Do nothing', 'sprint') . '</option>';
            foreach ($statusOptions as $key => $label) echo "<option value='" . htmlescape($key) . "'" . (($rules[$type]['closed'] ?? SprintItem::STATUS_DONE) === $key ? ' selected' : '') . '>' . htmlescape($label) . '</option>';
            echo "</select></td><td><select class='form-select form-select-sm' name='sync_rules[{$type}][open]'><option value=''>" . __('Do nothing', 'sprint') . '</option>';
            foreach ($statusOptions as $key => $label) echo "<option value='" . htmlescape($key) . "'" . (($rules[$type]['open'] ?? '') === $key ? ' selected' : '') . '>' . htmlescape($label) . '</option>';
            echo '</select></td></tr>';
        }
        echo '</tbody></table></div>';
        if ($edit) echo "<div class='mt-3'><button class='btn btn-primary'><i class='fas fa-save me-1'></i>" . __('Save') . '</button></div>';
        Html::closeForm(); self::endCard();
    }

    private static function renderImprovements(int $id, string $form, bool $edit, bool $contribute = false): void
    {
        $rows = self::rows('glpi_plugin_sprint_sprintimprovements', ['plugin_sprint_sprints_id' => $id] + self::ownActionCriteria(), ['votes DESC', 'date_creation DESC']);
        // Open actions of earlier sprints of the series stay followable here
        // under their own identity (no copies).
        $sprint = new Sprint();
        $carried = $sprint->getFromDB($id) ? self::getCarriedOpenActions($sprint->fields) : [];

        // Current user's votes, to render the thumbs-up as active/inactive.
        $votedIds = [];
        if ($rows) {
            foreach (self::rows('glpi_plugin_sprint_sprintimprovementvotes', [
                'users_id' => (int)Session::getLoginUserID(),
                'plugin_sprint_sprintimprovements_id' => array_map(fn($r) => (int)$r['id'], $rows),
            ]) as $vote) {
                $votedIds[(int)$vote['plugin_sprint_sprintimprovements_id']] = true;
            }
        }

        self::beginCard(
            __('Retrospective & improvement actions', 'sprint'),
            'fas fa-lightbulb',
            __('Overview of retrospective input and improvement actions. Adding input, voting and defining actions happen in the guided retrospective meeting on the Meetings tab.', 'sprint')
        );

        if ($contribute) {
            echo "<form method='post' action='" . htmlescape($form) . "' class='row g-2 mb-3 sprint-imp-add-form' data-can-toggle='" . ($edit ? 1 : 0) . "' data-lbl-anon='" . htmlescape(__('Anonymous', 'sprint')) . "' data-lbl-open='" . htmlescape(__('Open', 'sprint')) . "' data-lbl-done='" . htmlescape(__('Done', 'sprint')) . "' data-lbl-vote='" . htmlescape(__('Vote', 'sprint')) . "' data-lbl-toggle='" . htmlescape(__('Toggle done', 'sprint')) . "'>" . Html::hidden('sprint_id', ['value' => $id]) . Html::hidden('action', ['value' => 'improvement']);
            echo "<div class='col-md-2'><select class='form-select' name='category'>";
            foreach (self::improvementCategories() as $value => $label) {
                echo "<option value='" . htmlescape($value) . "'" . ($value === 'action' ? ' selected' : '') . '>' . htmlescape($label) . '</option>';
            }
            echo "</select></div><div class='col-md-4'><input required class='form-control' name='description' placeholder='" . htmlescape(__('Improvement or action', 'sprint')) . "'></div><div class='col-md-2'><input type='date' class='form-control' name='due_date'></div><div class='col-md-2'><select class='form-select' name='users_id'>" . self::memberOptions($id) . "</select></div><div class='col-md-1 pt-2'><label title='" . htmlescape(__('Anonymous', 'sprint')) . "'><input type='checkbox' name='is_anonymous' value='1'> <i class='fas fa-user-secret'></i></label></div><div class='col-md-1'><button class='btn btn-primary'><i class='fas fa-plus'></i></button></div>";
            Html::closeForm();
        }
        echo "<div class='table-responsive'><table class='table table-sm'><thead><tr><th>" . __('Type', 'sprint') . "</th><th>" . __('Improvement', 'sprint') . "</th><th>" . __('Owner', 'sprint') . "</th><th>" . __('Due date', 'sprint') . "</th><th>" . __('Votes', 'sprint') . "</th><th>" . __('Status') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $owner = (int)($row['is_anonymous'] ?? 0) === 1 && $row['category'] !== 'action' ? __('Anonymous', 'sprint') : SprintCache::userName((int)$row['users_id']);
            echo "<tr data-improvement-id='" . (int)$row['id'] . "'><td><span class=\"badge bg-blue-lt\">" . htmlescape(self::improvementCategories()[$row['category']] ?? $row['category']) . '</span></td><td>' . nl2br(htmlescape($row['description'])) . '</td><td>' . htmlescape($owner) . '</td><td>' . htmlescape((string)$row['due_date']) . "</td><td><span class='sprint-imp-votes'>" . (int)$row['votes'] . '</span>';
            if ($contribute) {
                $hasVoted  = isset($votedIds[(int)$row['id']]);
                $voteTitle = $hasVoted ? __('Remove my vote', 'sprint') : __('Vote', 'sprint');
                echo " <form method='post' action='" . htmlescape($form) . "' class='d-inline'>" . Html::hidden('sprint_id', ['value' => $id]) . Html::hidden('id', ['value' => $row['id']]) . Html::hidden('action', ['value' => 'improvement_vote'])
                    . "<button class='btn btn-sm btn-link sprint-imp-vote" . ($hasVoted ? '' : ' text-muted') . "' title='" . htmlescape($voteTitle) . "'><i class='" . ($hasVoted ? 'fas' : 'far') . " fa-thumbs-up'></i></button>";
                Html::closeForm();
            }
            echo "</td><td><span class='sprint-imp-status' data-status='" . htmlescape($row['status']) . "' data-lbl-open='" . htmlescape(__('Open', 'sprint')) . "' data-lbl-done='" . htmlescape(__('Done', 'sprint')) . "'>" . htmlescape($row['status'] === 'done' ? __('Done', 'sprint') : __('Open', 'sprint')) . '</span>';
            if ($edit) {
                echo " <form method='post' action='" . htmlescape($form) . "' class='d-inline'>" . Html::hidden('sprint_id', ['value' => $id]) . Html::hidden('id', ['value' => $row['id']]) . Html::hidden('action', ['value' => 'improvement_toggle']) . "<button class='btn btn-sm btn-outline-success' title='" . __('Toggle done', 'sprint') . "'><i class='fas fa-check'></i></button>"; Html::closeForm();
            }
            echo '</td></tr>';
        }
        foreach ($carried as $row) {
            echo "<tr data-improvement-id='" . (int)$row['id'] . "'><td><span class=\"badge bg-blue-lt\">" . htmlescape(self::improvementCategories()['action']) . '</span></td><td>' . nl2br(htmlescape($row['description']))
                . "<div class='text-muted sprint-small'><i class='fas fa-share me-1'></i>" . htmlescape(sprintf(__('Still open from %s', 'sprint'), $row['source_sprint_name'])) . '</div></td><td>'
                . htmlescape((int)$row['users_id'] > 0 ? SprintCache::userName((int)$row['users_id']) : '') . '</td><td>' . htmlescape((string)$row['due_date']) . "</td><td><span class='text-muted'>—</span></td>";
            echo "<td><span class='sprint-imp-status' data-status='open' data-lbl-open='" . htmlescape(__('Open', 'sprint')) . "' data-lbl-done='" . htmlescape(__('Done', 'sprint')) . "'>" . htmlescape(__('Open', 'sprint')) . '</span>';
            if ($edit) {
                echo " <form method='post' action='" . htmlescape($form) . "' class='d-inline'>" . Html::hidden('sprint_id', ['value' => $id]) . Html::hidden('id', ['value' => $row['id']]) . Html::hidden('action', ['value' => 'improvement_toggle']) . "<button class='btn btn-sm btn-outline-success' title='" . __('Toggle done', 'sprint') . "'><i class='fas fa-check'></i></button>"; Html::closeForm();
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>'; self::endCard();
    }

    /**
     * Availability exceptions (leave, training, ...) per member. Lives on the
     * Sprint Members tab next to the capacity settings; the POST handler stays
     * in front/sprintagility.form.php and lands back on that tab.
     */
    public static function renderAvailability(int $id, bool $edit): void
    {
        $form = Plugin::getWebDir('sprint') . '/front/sprintagility.form.php';
        $rows = self::rows('glpi_plugin_sprint_sprintavailabilities', ['plugin_sprint_sprints_id' => $id], ['users_id ASC', 'date_start ASC']);
        self::beginCard(
            __('Availability exceptions', 'sprint'),
            'fas fa-calendar-minus',
            __('Record leave, training and other temporary changes to a member’s availability. Forecasts and owner suggestions use the adjusted capacity for the affected working days.', 'sprint')
        );
        if ($edit) {
            $pctHelp = __('The percentage the member IS still available during this period (0% = fully absent)', 'sprint');
            echo "<form method='post' action='" . htmlescape($form) . "' class='row g-2 mb-1'>" . Html::hidden('sprint_id', ['value' => $id]) . Html::hidden('action', ['value' => 'availability']) . Html::hidden('_tab', ['value' => 'members']);
            echo "<div class='col-md-3'><select class='form-select' name='users_id'>" . self::memberOptions($id) . "</select></div><div class='col-md-2'><input required type='date' class='form-control' name='date_start'></div><div class='col-md-2'><input required type='date' class='form-control' name='date_end'></div><div class='col-md-2'><input type='number' min='0' max='100' step='.5' value='0' class='form-control' name='availability_percent' title='" . htmlescape($pctHelp) . "'></div><div class='col-md-2'><input class='form-control' name='comment' placeholder='" . htmlescape(__('Reason', 'sprint')) . "'></div><div class='col-md-1'><button class='btn btn-primary'><i class='fas fa-plus'></i></button></div>";
            Html::closeForm();
            echo "<div class='text-muted sprint-small mb-3'><i class='fas fa-circle-info me-1'></i>" . htmlescape($pctHelp) . '</div>';
        }
        if (!$rows) {
            echo "<div class='text-muted'>" . __('No availability exceptions', 'sprint') . '</div>';
            self::endCard();
            return;
        }
        echo "<div class='table-responsive'><table class='table table-sm mb-0'><thead><tr>";
        echo '<th>' . __('Member', 'sprint') . '</th><th>' . __('Period', 'sprint') . '</th><th title="' . htmlescape(__('The percentage the member IS still available during this period (0% = fully absent)', 'sprint')) . '">' . __('Availability %', 'sprint') . '</th><th>' . __('Reason', 'sprint') . '</th>';
        if ($edit) { echo "<th class='w-1'></th>"; }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $period = $row['date_start'] === $row['date_end']
                ? $row['date_start']
                : $row['date_start'] . ' – ' . $row['date_end'];
            $pct = SprintMember::formatCapacity($row['availability_percent']);
            echo '<tr><td><i class="fas fa-user-clock me-1"></i>' . htmlescape(SprintCache::userName((int)$row['users_id'])) . '</td>';
            echo '<td>' . htmlescape($period) . '</td>';
            echo "<td><span class='badge " . ((float)$row['availability_percent'] <= 0 ? 'bg-red-lt' : 'bg-yellow-lt') . "'>" . $pct . '%</span></td>';
            echo '<td>' . htmlescape((string)$row['comment']) . '</td>';
            if ($edit) {
                echo '<td>';
                echo "<form method='post' action='" . htmlescape($form) . "' class='d-inline'>" . Html::hidden('sprint_id', ['value' => $id]) . Html::hidden('id', ['value' => $row['id']]) . Html::hidden('action', ['value' => 'availability_delete']) . Html::hidden('_tab', ['value' => 'members']) . "<button class='btn btn-sm btn-link p-0 text-danger' title='" . __('Delete') . "'><i class='fas fa-times'></i></button>";
                Html::closeForm();
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        self::endCard();
    }

    private static function renderEpics(int $id, array $items, string $form, bool $edit): void
    {
        global $DB;
        $sprint = new Sprint(); $sprint->getFromDB($id);
        $rows = self::rows('glpi_plugin_sprint_sprintepics', ['entities_id' => (int)($sprint->fields['entities_id'] ?? 0)], ['name ASC']);
        self::beginCard(
            __('Epics & release themes', 'sprint'),
            'fas fa-layer-group',
            __('Group related sprint items under a larger outcome or release theme. Progress is calculated from completed items and total story points across all linked sprints.', 'sprint')
        );
        if ($edit) {
            echo "<form method='post' action='" . htmlescape($form) . "' class='row g-2 mb-3'>" . Html::hidden('sprint_id', ['value' => $id]) . Html::hidden('action', ['value' => 'epic']);
            echo "<div class='col-md-6'><input required class='form-control' name='name' placeholder='" . htmlescape(__('Epic or theme name', 'sprint')) . "'></div><div class='col-md-2'><input type='color' class='form-control form-control-color' name='color' value='#0d6efd'></div><div class='col-md-3'><input type='date' class='form-control' name='target_date'></div><div class='col-md-1'><button class='btn btn-primary'><i class='fas fa-plus'></i></button></div>";
            Html::closeForm();
        }
        foreach ($rows as $epic) {
            $epicItems = iterator_to_array($DB->request(['FROM' => SprintItem::getTable(), 'WHERE' => ['plugin_sprint_sprintepics_id' => (int)$epic['id']]]));
            $done = count(array_filter($epicItems, fn($r) => ($r['status'] ?? '') === SprintItem::STATUS_DONE));
            $pct = count($epicItems) ? (int)round(100 * $done / count($epicItems)) : 0;
            $points = array_sum(array_map(fn($r) => (int)($r['story_points'] ?? 0), $epicItems));
            echo "<div class='mb-2'><div class='d-flex justify-content-between'><strong><span style='color:" . htmlescape($epic['color']) . "'>●</span> " . htmlescape($epic['name']) . (!empty($epic['target_date']) ? " <small class='text-muted'>· " . htmlescape(substr($epic['target_date'], 0, 10)) . '</small>' : '') . "</strong><span>{$done}/" . count($epicItems) . " · {$points} SP · {$pct}%</span></div><div class='progress progress-sm'><div class='progress-bar' style='width:{$pct}%;background:" . htmlescape($epic['color']) . "'></div></div></div>";
            if ($edit && count($epicItems) === 0) { echo "<form method='post' action='" . htmlescape($form) . "' class='mb-2'>" . Html::hidden('sprint_id', ['value' => $id]) . Html::hidden('id', ['value' => $epic['id']]) . Html::hidden('action', ['value' => 'epic_delete']) . "<button class='btn btn-sm btn-link text-danger p-0'><i class='fas fa-trash me-1'></i>" . __('Delete empty epic', 'sprint') . '</button>'; Html::closeForm(); }
        }
        self::endCard();
    }

    private static function renderSignals(int $sprintId, string $form): void
    {
        global $DB;
        self::resolveApprovalSignals($sprintId);
        $rows = self::rows('glpi_plugin_sprint_sprintsignals', ['plugin_sprint_sprints_id' => $sprintId, 'users_id' => (int)Session::getLoginUserID(), 'is_read' => 0], ['date_creation DESC']);
        if (!$rows) return;
        self::beginCard(
            sprintf(__('My Sprint actions (%d)', 'sprint'), count($rows)),
            'fas fa-bell',
            __('These signals highlight approvals, meetings, capacity issues and improvement actions that need your attention. Open the linked item or mark the signal as read.', 'sprint'),
            true
        );
        foreach (array_slice($rows, 0, 10) as $row) {
            $message = htmlescape($row['message']);
            if (!empty($row['url'])) $message = "<a href='" . htmlescape($row['url']) . "'>{$message}</a>";
            echo "<div class='alert alert-info py-2 mb-2 d-flex justify-content-between sprint-signal-row'><span><i class='fas fa-circle-info me-2'></i>{$message}</span><form method='post' action='" . htmlescape($form) . "'>" . Html::hidden('sprint_id', ['value' => $sprintId]) . Html::hidden('id', ['value' => $row['id']]) . Html::hidden('action', ['value' => 'signal_read']) . "<button class='btn btn-sm btn-link' title='" . __('Mark as read', 'sprint') . "'><i class='fas fa-check'></i></button>"; Html::closeForm(); echo '</div>';
        }
        self::endCard();
    }


    /**
     * Improvement rows for a sprint, votes-first, each with a `voted_by_me`
     * flag for the current user. Used by the guided retrospective rail.
     */
    public static function getImprovementsForSprint(int $sprintId): array
    {
        // Legacy carry-over copies are followed up through their original.
        $rows = self::rows('glpi_plugin_sprint_sprintimprovements', ['plugin_sprint_sprints_id' => $sprintId] + self::ownActionCriteria(), ['votes DESC', 'date_creation DESC']);
        $votedIds = [];
        if ($rows) {
            foreach (self::rows('glpi_plugin_sprint_sprintimprovementvotes', [
                'users_id' => (int)Session::getLoginUserID(),
                'plugin_sprint_sprintimprovements_id' => array_map(fn($r) => (int)$r['id'], $rows),
            ]) as $vote) {
                $votedIds[(int)$vote['plugin_sprint_sprintimprovements_id']] = true;
            }
        }
        $uid = (int)Session::getLoginUserID();
        foreach ($rows as &$row) {
            $row['voted_by_me'] = isset($votedIds[(int)$row['id']]);
            // Nobody votes on their own input.
            $row['is_mine']     = $row['category'] !== 'action' && $uid > 0 && (int)$row['users_id'] === $uid;
            $row['owner_name']  = ((int)($row['is_anonymous'] ?? 0) === 1 && $row['category'] !== 'action')
                ? __('Anonymous', 'sprint')
                : (((int)$row['users_id'] > 0) ? SprintCache::userName((int)$row['users_id']) : '');
        }
        unset($row);
        return array_values($rows);
    }

    /** Whether the 1.3.2 retro flow column (top-3 actions, carried input) exists. */
    public static function hasRetroFlow(): bool
    {
        global $DB;
        static $has = null;
        return $has ??= ($DB->tableExists(self::IMPROVEMENTS_TABLE)
            && $DB->fieldExists(self::IMPROVEMENTS_TABLE, 'source_improvements_id'));
    }

    /** Ids of the retro input rows (everything but actions) of a sprint. */
    private static function inputIds(int $sprintId): array
    {
        $ids = [];
        foreach (self::rows(self::IMPROVEMENTS_TABLE, [
            'plugin_sprint_sprints_id' => $sprintId, ['NOT' => ['category' => 'action']],
        ] + self::ownActionCriteria()) as $row) {
            $ids[] = (int)$row['id'];
        }
        return $ids;
    }

    /** Dot-votes one member has spent on a sprint's retro input. */
    public static function votesUsed(int $sprintId, int $uid): int
    {
        $ids = self::inputIds($sprintId);
        if (!$ids || $uid <= 0) return 0;
        return countElementsInTable(self::VOTES_TABLE, ['users_id' => $uid, 'plugin_sprint_sprintimprovements_id' => $ids]);
    }

    /** Whether voting closed for this sprint: its top themes became actions. */
    public static function inputsHandled(int $sprintId): bool
    {
        return self::hasRetroFlow()
            && countElementsInTable(self::IMPROVEMENTS_TABLE, ['plugin_sprint_sprints_id' => $sprintId, 'status' => 'promoted']) > 0;
    }

    /**
     * Voting closed: the top-voted input (at most RETRO_TOP_ACTIONS, votes
     * needed) becomes an action without owner yet, to be assigned in the
     * "Decide actions" phase; the input row is marked promoted. The rest
     * stays open and returns in the next retrospective. Runs once per sprint.
     *
     * @return int number of actions created
     */
    public static function promoteTopVotedInputs(int $sprintId, int $meetingId): int
    {
        global $DB;
        if ($sprintId <= 0 || !self::hasRetroFlow() || self::inputsHandled($sprintId)) return 0;
        $rows = self::rows(self::IMPROVEMENTS_TABLE, [
            'plugin_sprint_sprints_id' => $sprintId, ['NOT' => ['category' => 'action']],
            'status' => 'open', 'votes' => ['>', 0],
        ] + self::ownActionCriteria(), ['votes DESC', 'date_creation ASC', 'id ASC']);
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $n = 0;
        foreach (array_slice($rows, 0, self::RETRO_TOP_ACTIONS) as $row) {
            $ok = $DB->insert(self::IMPROVEMENTS_TABLE, [
                'plugin_sprint_sprints_id' => $sprintId, 'category' => 'action',
                'description' => (string)$row['description'], 'users_id' => 0, 'due_date' => null,
                'status' => 'open', 'votes' => 0, 'carry_to_next' => 1, 'is_anonymous' => 0,
                'plugin_sprint_sprintmeetings_id' => $meetingId, 'source_improvements_id' => (int)$row['id'],
                'date_creation' => $now, 'date_mod' => $now,
            ]);
            if (!$ok) continue;
            $DB->update(self::IMPROVEMENTS_TABLE, ['status' => 'promoted', 'date_mod' => $now], ['id' => (int)$row['id']]);
            $n++;
        }
        return $n;
    }

    /**
     * Input of an earlier retrospective that never became an action and was
     * not carried over yet: offered in the next retrospective, one click
     * adds it to that retrospective's list.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getUnhandledInputs(int $sprintId): array
    {
        if ($sprintId <= 0 || !self::hasRetroFlow() || !SprintItem::sprintEntityAccessible($sprintId)) return [];
        $out = [];
        foreach (self::rows(self::IMPROVEMENTS_TABLE, [
            'plugin_sprint_sprints_id' => $sprintId, ['NOT' => ['category' => 'action']], 'status' => 'open',
        ] + self::ownActionCriteria(), ['votes DESC', 'date_creation ASC', 'id ASC']) as $row) {
            $row['owner_name'] = ((int)($row['is_anonymous'] ?? 0) === 1)
                ? __('Anonymous', 'sprint')
                : (((int)$row['users_id'] > 0) ? SprintCache::userName((int)$row['users_id']) : '');
            $out[] = $row;
        }
        return $out;
    }

    /** Sprints whose unhandled retro input may be carried into $sprint's retrospective. */
    private static function carrySourceSprintIds(array $sprint): array
    {
        $ids = array_keys(self::getEarlierSeriesSprints($sprint));
        foreach (array_keys(self::resolvePredecessor($sprint)['choices']) as $cid) $ids[] = (int)$cid;
        return array_values(array_unique($ids));
    }

    /** @return array<string,string> */
    public static function improvementCategories(): array
    {
        return [
            'start'    => __('Start', 'sprint'),
            'stop'     => __('Stop', 'sprint'),
            'continue' => __('Continue', 'sprint'),
            'action'   => __('Action', 'sprint'),
        ];
    }


    private static function memberOptions(int $sprintId): string
    {
        $html = "<option value='0'>—</option>";
        foreach (SprintMember::getSprintMemberOptions($sprintId) as $id => $name) if ((int)$id > 0) $html .= "<option value='" . (int)$id . "'>" . htmlescape($name) . '</option>';
        return $html;
    }

    /**
     * @return bool|int|array true/false, the new row id for 'improvement', or a
     *                        JSON-ready array (success, message, extras)
     */
    public static function handle(array $input)
    {
        global $DB;
        $sprintId = (int)($input['sprint_id'] ?? 0);
        if ((string)($input['action'] ?? '') === 'signal_read') {
            return $DB->update('glpi_plugin_sprint_sprintsignals', ['is_read' => 1], [
                'id' => (int)($input['id'] ?? 0), 'users_id' => (int)Session::getLoginUserID(),
            ]);
        }
        if ($sprintId <= 0 || !SprintItem::sprintEntityAccessible($sprintId)) return false;
        $action = (string)($input['action'] ?? '');
        // Status toggles and follow-up notes may target actions of earlier
        // sprints of the series (retrospective follow-up); see there.
        if (in_array($action, ['improvement_toggle', 'improvement_followup'], true)) {
            return self::handleActionFollowUp($action, $input, $sprintId);
        }
        $isScrumMaster = Config::isCurrentUserScrumMaster($sprintId) && Sprint::canUpdate();
        // Retro input and voting are open to every sprint member; the rest
        // stays Scrum-Master-only.
        if (in_array($action, ['improvement', 'improvement_vote', 'improvement_carry'], true)) {
            if (!$isScrumMaster && !self::isCurrentUserMember($sprintId)) return false;
        } elseif ($action === 'improvement_assign') {
            // Same driver rule as the guided meeting: Scrum Master or facilitator.
            if (!$isScrumMaster && !(self::isCurrentUserMember($sprintId)
                && self::isCurrentUserMeetingFacilitator($sprintId, (int)($input['meeting_id'] ?? 0)))) return false;
        } elseif (!$isScrumMaster) {
            return false;
        }
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        switch ($action) {
            case 'policy':
                $limits = [];
                foreach ((array)($input['wip'] ?? []) as $key => $value) if (isset(SprintItem::getAllStatuses()[$key])) $limits[$key] = max(0, (int)$value);
                $policySprint = new Sprint();
                if (!$policySprint->getFromDB($sprintId)) return false;
                return $policySprint->update([
                    'id' => $sprintId,
                    'wip_limits' => json_encode($limits), 'wip_hard' => isset($input['wip_hard']) ? 1 : 0,
                    'sync_linked_status' => isset($input['sync_linked_status']) ? 1 : 0,
                    'linked_status_rules' => json_encode((array)($input['sync_rules'] ?? [])), 'date_mod' => $now,
                ]);
            case 'assign_suggestion':
                $member = countElementsInTable(SprintMember::getTable(), ['plugin_sprint_sprints_id' => $sprintId, 'users_id' => (int)($input['users_id'] ?? 0)]);
                if (!$member) return false;
                return $DB->update(SprintItem::getTable(), ['users_id' => (int)$input['users_id'], 'date_mod' => $now], ['id' => (int)($input['item_id'] ?? 0), 'plugin_sprint_sprints_id' => $sprintId]);
            case 'improvement':
                $description = trim((string)($input['description'] ?? ''));
                if ($description === '') return false;
                $category = substr((string)($input['category'] ?? 'action'), 0, 32);
                // Retro input has no owner picker: the contributor is the owner.
                $owner = (int)($input['users_id'] ?? 0);
                if ($owner <= 0 && $category !== 'action') $owner = (int)Session::getLoginUserID();
                $fields = [
                    'plugin_sprint_sprints_id' => $sprintId, 'category' => $category,
                    'description' => $description, 'users_id' => $owner,
                    'due_date' => ($input['due_date'] ?? '') ?: null, 'status' => 'open', 'votes' => 0, 'carry_to_next' => 1,
                    'is_anonymous' => isset($input['is_anonymous']) ? 1 : 0,
                    'date_creation' => $now, 'date_mod' => $now,
                ];
                // Remember the retrospective the action was agreed in.
                $meetingId = (int)($input['meeting_id'] ?? 0);
                if ($meetingId > 0 && self::hasActionIdentity() && countElementsInTable(SprintMeeting::getTable(), [
                    'id' => $meetingId, 'plugin_sprint_sprints_id' => $sprintId,
                ]) > 0) {
                    $fields['plugin_sprint_sprintmeetings_id'] = $meetingId;
                }
                if (!$DB->insert('glpi_plugin_sprint_sprintimprovements', $fields)) return false;
                return (int)$DB->insertId();
            case 'availability':
                $start = (string)($input['date_start'] ?? ''); $end = (string)($input['date_end'] ?? '');
                if ($start === '' || $end === '' || $end < $start) return false;
                return $DB->insert('glpi_plugin_sprint_sprintavailabilities', [
                    'plugin_sprint_sprints_id' => $sprintId, 'users_id' => (int)($input['users_id'] ?? 0),
                    'date_start' => $start, 'date_end' => $end,
                    'availability_percent' => SprintMember::normalizeCapacity($input['availability_percent'] ?? 0),
                    'comment' => substr(trim((string)($input['comment'] ?? '')), 0, 255), 'date_creation' => $now,
                ]);
            case 'availability_delete':
                return $DB->delete('glpi_plugin_sprint_sprintavailabilities', ['id' => (int)($input['id'] ?? 0), 'plugin_sprint_sprints_id' => $sprintId]);
            case 'improvement_vote':
                // Toggle: one vote per user per input, second click removes it.
                // Nobody votes on their own input, everyone has a fixed number
                // of votes, and voting closes once the top themes are actions.
                $id = (int)($input['id'] ?? 0);
                $rows = self::rows(self::IMPROVEMENTS_TABLE, ['id' => $id, 'plugin_sprint_sprints_id' => $sprintId]);
                $row = $rows ? reset($rows) : null;
                if (!$row || $row['category'] === 'action') return false;
                $uid   = (int)Session::getLoginUserID();
                $limit = Config::getRetroVotesPerMember();
                if ($row['status'] !== 'open' || self::inputsHandled($sprintId)) {
                    return ['success' => false, 'message' => __('Voting is closed: the top themes are actions now.', 'sprint')];
                }
                $existing = self::rows(self::VOTES_TABLE, [
                    'plugin_sprint_sprintimprovements_id' => $id, 'users_id' => $uid,
                ]);
                if ($existing) {
                    $DB->delete(self::VOTES_TABLE, ['id' => (int)reset($existing)['id']]);
                    $DB->update(self::IMPROVEMENTS_TABLE, ['votes' => max(0, (int)$row['votes'] - 1), 'date_mod' => $now], ['id' => $id]);
                    return ['success' => true, 'voted' => false, 'votes_left' => max(0, $limit - self::votesUsed($sprintId, $uid))];
                }
                if ((int)$row['users_id'] === $uid) {
                    return ['success' => false, 'message' => __('You cannot vote on your own input.', 'sprint')];
                }
                if (self::votesUsed($sprintId, $uid) >= $limit) {
                    return ['success' => false, 'message' => sprintf(
                        _n('Your %d vote is used. Remove it first to vote on something else.', 'All %d of your votes are used. Remove one first to vote on something else.', $limit, 'sprint'),
                        $limit
                    )];
                }
                $DB->insert(self::VOTES_TABLE, [
                    'plugin_sprint_sprintimprovements_id' => $id, 'users_id' => $uid, 'date_creation' => $now,
                ]);
                $DB->update(self::IMPROVEMENTS_TABLE, ['votes' => (int)$row['votes'] + 1, 'date_mod' => $now], ['id' => $id]);
                return ['success' => true, 'voted' => true, 'votes_left' => max(0, $limit - self::votesUsed($sprintId, $uid))];
            case 'improvement_carry':
                // Unhandled input of an earlier retrospective joins this one:
                // a copy in this sprint, the original marked carried.
                if (!self::hasRetroFlow()) return false;
                $id  = (int)($input['id'] ?? 0);
                $src = self::rows(self::IMPROVEMENTS_TABLE, ['id' => $id, 'status' => 'open', ['NOT' => ['category' => 'action']]]);
                $src = $src ? reset($src) : null;
                $carrySprint = new Sprint();
                if (!$src || !$carrySprint->getFromDB($sprintId)) return false;
                $srcSprintId = (int)$src['plugin_sprint_sprints_id'];
                if (!in_array($srcSprintId, self::carrySourceSprintIds($carrySprint->fields), true)
                    || !SprintItem::sprintEntityAccessible($srcSprintId)) return false;
                $copy = [
                    'plugin_sprint_sprints_id' => $sprintId, 'category' => (string)$src['category'],
                    'description' => (string)$src['description'], 'users_id' => (int)$src['users_id'],
                    'due_date' => null, 'status' => 'open', 'votes' => 0, 'carry_to_next' => 1,
                    'is_anonymous' => (int)($src['is_anonymous'] ?? 0), 'source_improvements_id' => $id,
                    'date_creation' => $now, 'date_mod' => $now,
                ];
                $meetingId = (int)($input['meeting_id'] ?? 0);
                if ($meetingId > 0 && countElementsInTable(SprintMeeting::getTable(), ['id' => $meetingId, 'plugin_sprint_sprints_id' => $sprintId]) > 0) {
                    $copy['plugin_sprint_sprintmeetings_id'] = $meetingId;
                }
                if (!$DB->insert(self::IMPROVEMENTS_TABLE, $copy)) return false;
                $newId = (int)$DB->insertId();
                $DB->update(self::IMPROVEMENTS_TABLE, ['status' => 'carried', 'date_mod' => $now], ['id' => $id]);
                $copyUid = (int)Session::getLoginUserID();
                return ['success' => true, 'id' => $newId, 'row' => [
                    'id' => $newId, 'category' => (string)$src['category'], 'description' => plugin_sprint_text((string)$src['description']),
                    'owner_name' => (int)($src['is_anonymous'] ?? 0) === 1 ? __('Anonymous', 'sprint')
                        : (((int)$src['users_id'] > 0) ? SprintCache::userName((int)$src['users_id']) : ''),
                    'votes' => 0, 'status' => 'open', 'is_mine' => (int)$src['users_id'] === $copyUid,
                ]];
            case 'improvement_assign':
                // Owner and due date of an action made from a top-voted theme.
                $id  = (int)($input['id'] ?? 0);
                $act = self::rows(self::IMPROVEMENTS_TABLE, ['id' => $id, 'plugin_sprint_sprints_id' => $sprintId, 'category' => 'action']);
                if (!$act) return false;
                $owner = (int)($input['users_id'] ?? 0);
                if ($owner > 0 && !countElementsInTable(SprintMember::getTable(), ['plugin_sprint_sprints_id' => $sprintId, 'users_id' => $owner])) return false;
                $due = (string)($input['due_date'] ?? '');
                $ok = $DB->update(self::IMPROVEMENTS_TABLE, [
                    'users_id' => $owner, 'due_date' => preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $due) ? $due : null, 'date_mod' => $now,
                ], ['id' => $id]);
                return $ok ? ['success' => true, 'owner_name' => $owner > 0 ? SprintCache::userName($owner) : ''] : false;
            case 'epic':
                $name = trim((string)($input['name'] ?? ''));
                if ($name === '') return false;
                $sprint = new Sprint(); $sprint->getFromDB($sprintId);
                return $DB->insert('glpi_plugin_sprint_sprintepics', [
                    'plugin_sprint_sprints_id' => $sprintId, 'entities_id' => (int)($sprint->fields['entities_id'] ?? 0), 'name' => $name,
                    'color' => preg_match('/^#[0-9a-f]{6}$/i', (string)($input['color'] ?? '')) ? $input['color'] : '#0d6efd',
                    'target_date' => ($input['target_date'] ?? '') ?: null, 'date_creation' => $now, 'date_mod' => $now,
                ]);
            case 'epic_delete':
                $epicId = (int)($input['id'] ?? 0);
                if (countElementsInTable(SprintItem::getTable(), ['plugin_sprint_sprintepics_id' => $epicId]) > 0) return false;
                $currentSprint = new Sprint(); $currentSprint->getFromDB($sprintId);
                return $DB->delete('glpi_plugin_sprint_sprintepics', ['id' => $epicId, 'entities_id' => (int)($currentSprint->fields['entities_id'] ?? 0)]);
        }
        return false;
    }
}
