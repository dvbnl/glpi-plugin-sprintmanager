<?php

namespace GlpiPlugin\Sprint;

use CommonGLPI;
use Html;
use Plugin;
use Session;

class SprintAgility extends CommonGLPI
{
    public static $rightname = 'plugin_sprint_sprint';

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

    /** @return array{ok:bool,message:string} */
    public static function validateTransition(SprintItem $item, string $newStatus): array
    {
        $sprintId = (int)($item->fields['plugin_sprint_sprints_id'] ?? 0);
        if ($sprintId <= 0 || $newStatus === (string)($item->fields['status'] ?? '')) {
            return ['ok' => true, 'message' => ''];
        }
        $sprint = new Sprint();
        if (!$sprint->getFromDB($sprintId)) {
            return ['ok' => true, 'message' => ''];
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
                )];
            }
        }

        if (in_array($newStatus, [SprintItem::STATUS_REVIEW, SprintItem::STATUS_DONE], true)) {
            $required = Config::getDefinitionDone();
            $checked  = self::checklist((string)($item->fields['done_checks'] ?? ''));
            $missing  = array_values(array_diff($required, $checked));
            if ($missing) {
                return ['ok' => false, 'message' => sprintf(
                    __('Definition of Done incomplete: %s', 'sprint'),
                    implode(', ', $missing)
                )];
            }
        }
        return ['ok' => true, 'message' => ''];
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
            $policy = self::validateTransition($item, $target);
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
        foreach ($DB->request(['FROM' => 'glpi_plugin_sprint_sprintimprovements', 'WHERE' => ['status' => 'open', ['due_date' => ['<=', $tomorrow]], ['NOT' => ['users_id' => 0]]]]) as $action) {
            $created += self::signalOnce((int)$action['plugin_sprint_sprints_id'], (int)$action['users_id'], 'retro_action', sprintf(__('Improvement action due: %s', 'sprint'), $action['description']), Sprint::getFormURLWithID((int)$action['plugin_sprint_sprints_id'])) ? 1 : 0;
        }
        foreach ($DB->request(['FROM' => Sprint::getTable(), 'WHERE' => ['status' => Sprint::STATUS_ACTIVE]]) as $activeSprint) {
            $sprintId = (int)$activeSprint['id'];
            foreach ((new SprintMember())->find(['plugin_sprint_sprints_id' => $sprintId]) as $member) {
                $uid = (int)$member['users_id'];
                $available = self::effectiveCapacity($sprintId, $uid, (float)$member['capacity_percent']);
                $used = SprintMember::getUsedCapacityForUser($sprintId, $uid);
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
        $openActions   = count(self::rows('glpi_plugin_sprint_sprintimprovements', ['plugin_sprint_sprints_id' => $id, 'status' => 'open']));
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
            . " <span class='text-muted small'>" . __('(from member capacity and availability exceptions)', 'sprint') . "</span></div></div>";
        echo "<div class='col-md-3' style='min-width:0;'><div class='alert alert-info mb-0' style='display:block;min-width:0;overflow-wrap:anywhere;'><div class='small'>" . __('Scenario confidence', 'sprint') . "</div><div><strong id='sa-confidence'>" . (int)$forecast['confidence'] . "%</strong></div><div class='small'>" . sprintf(__('85%% safe: %d SP · %d historical sprints', 'sprint'), (int)$forecast['safe'], (int)$forecast['samples']) . "</div></div></div></div>";
        $baseline = max(1, (int)$forecast['expected']);
        echo "<script>(function(){var s=document.getElementById('sa-scope'),a=document.getElementById('sa-availability'),o=document.getElementById('sa-confidence');if(!s||!a)return;function u(){document.getElementById('sa-scope-label').textContent=s.value;document.getElementById('sa-availability-label').textContent=a.value;var capacity={$baseline}*(a.value/{$availDefault}),scope=Math.max(1,s.value),p=Math.max(0,Math.min(99,Math.round(100*capacity/scope)));o.textContent=p+'%';}s.addEventListener('input',u);a.addEventListener('input',u);u();})();</script>";
        self::endCard();
    }

    private static function renderPlanningSuggestions(Sprint $sprint, array $items, string $form, bool $edit): void
    {
        $unassigned = array_filter($items, fn($row) => (int)($row['users_id'] ?? 0) <= 0 && ($row['status'] ?? '') !== SprintItem::STATUS_DONE);
        if (!$unassigned) return;
        $members = (new SprintMember())->find(['plugin_sprint_sprints_id' => (int)$sprint->getID()]);
        if (!$members) return;
        $loads = [];
        foreach ($members as $member) {
            $uid = (int)$member['users_id'];
            $capacity = self::effectiveCapacity((int)$sprint->getID(), $uid, (float)$member['capacity_percent']);
            $used = SprintMember::getUsedCapacityForUser((int)$sprint->getID(), $uid);
            $loads[$uid] = ['name' => SprintCache::userName($uid), 'free' => max(0, $capacity - $used), 'role' => $member['role']];
        }
        $familiarity = [];
        $history = (new SprintItem())->find(['users_id' => array_keys($loads)]);
        $historyTags = SprintItem::getTagsForItems(array_map(fn($r) => (int)$r['id'], $history));
        foreach ($history as $row) foreach ($historyTags[(int)$row['id']] ?? [] as $tag) {
            $key = mb_strtolower($tag); $uid = (int)$row['users_id'];
            $familiarity[$uid][$key] = ($familiarity[$uid][$key] ?? 0) + 1;
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

    public static function carryImprovementsToSprint(Sprint $sprint): int
    {
        global $DB;
        $id = (int)$sprint->getID();
        if ($id <= 0 || !$DB->tableExists('glpi_plugin_sprint_sprintimprovements')) return 0;
        $previous = null;
        foreach ($DB->request(['FROM' => Sprint::getTable(), 'WHERE' => [
            'entities_id' => (int)($sprint->fields['entities_id'] ?? 0), 'status' => Sprint::STATUS_COMPLETED,
            ['NOT' => ['id' => $id]],
        ], 'ORDER' => ['date_end DESC'], 'LIMIT' => 1]) as $row) { $previous = $row; }
        if (!$previous) return 0;
        $count = 0; $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        foreach (self::rows('glpi_plugin_sprint_sprintimprovements', ['plugin_sprint_sprints_id' => (int)$previous['id'], 'status' => 'open', 'carry_to_next' => 1]) as $row) {
            if (countElementsInTable('glpi_plugin_sprint_sprintimprovements', ['plugin_sprint_sprints_id' => $id, 'description' => (string)$row['description'], 'status' => 'open']) > 0) continue;
            $DB->insert('glpi_plugin_sprint_sprintimprovements', [
                'plugin_sprint_sprints_id' => $id, 'category' => 'action',
                'description' => (string)$row['description'], 'users_id' => (int)$row['users_id'],
                'due_date' => $row['due_date'] ?: null, 'status' => 'open', 'votes' => (int)$row['votes'],
                'carry_to_next' => 1, 'date_creation' => $now, 'date_mod' => $now,
            ]); $count++;
        }
        return $count;
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
        $rows = self::rows('glpi_plugin_sprint_sprintimprovements', ['plugin_sprint_sprints_id' => $id], ['votes DESC', 'date_creation DESC']);

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
            echo "<div class='text-muted small mb-3'><i class='fas fa-circle-info me-1'></i>" . htmlescape($pctHelp) . '</div>';
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

    public static function renderHomeWidget(): void
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_sprint_sprintsignals')) return;
        self::resolveApprovalSignals();
        $rows = self::rows('glpi_plugin_sprint_sprintsignals', ['users_id' => (int)Session::getLoginUserID(), 'is_read' => 0], ['date_creation DESC']);
        if (!$rows) return;
        echo "<div class='card mb-3 sprint-home-actions'><div class='card-header'><h3 class='card-title'><i class='fas fa-running me-2'></i>" . sprintf(__('My Sprint actions (%d)', 'sprint'), count($rows)) . "</h3></div><div class='list-group list-group-flush'>";
        foreach (array_slice($rows, 0, 5) as $row) {
            $url = $row['url'] ?: Sprint::getFormURLWithID((int)$row['plugin_sprint_sprints_id']);
            echo "<a class='list-group-item list-group-item-action' href='" . htmlescape($url) . "'><span class='badge bg-blue-lt me-2'>" . htmlescape(self::signalTypeLabel((string)$row['signal_type'])) . '</span>' . htmlescape($row['message']) . '</a>';
        }
        echo '</div></div>';
    }

    /**
     * Improvement rows for a sprint, votes-first, each with a `voted_by_me`
     * flag for the current user. Used by the guided retrospective rail.
     */
    public static function getImprovementsForSprint(int $sprintId): array
    {
        $rows = self::rows('glpi_plugin_sprint_sprintimprovements', ['plugin_sprint_sprints_id' => $sprintId], ['votes DESC', 'date_creation DESC']);
        $votedIds = [];
        if ($rows) {
            foreach (self::rows('glpi_plugin_sprint_sprintimprovementvotes', [
                'users_id' => (int)Session::getLoginUserID(),
                'plugin_sprint_sprintimprovements_id' => array_map(fn($r) => (int)$r['id'], $rows),
            ]) as $vote) {
                $votedIds[(int)$vote['plugin_sprint_sprintimprovements_id']] = true;
            }
        }
        foreach ($rows as &$row) {
            $row['voted_by_me'] = isset($votedIds[(int)$row['id']]);
            $row['owner_name']  = ((int)($row['is_anonymous'] ?? 0) === 1 && $row['category'] !== 'action')
                ? __('Anonymous', 'sprint')
                : (((int)$row['users_id'] > 0) ? SprintCache::userName((int)$row['users_id']) : '');
        }
        unset($row);
        return array_values($rows);
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

    private static function signalTypeLabel(string $type): string
    {
        return match ($type) {
            'meeting'        => __('Meeting', 'sprint'),
            'approval'       => __('Approval', 'sprint'),
            'stale_approval' => __('Approval', 'sprint'),
            'retro_action'   => __('Improvement action', 'sprint'),
            'capacity'       => __('Capacity', 'sprint'),
            'blocked'        => __('Blocked', 'sprint'),
            'dependency'     => __('Dependency', 'sprint'),
            default          => $type,
        };
    }

    private static function memberOptions(int $sprintId): string
    {
        $html = "<option value='0'>—</option>";
        foreach (SprintMember::getSprintMemberOptions($sprintId) as $id => $name) if ((int)$id > 0) $html .= "<option value='" . (int)$id . "'>" . htmlescape($name) . '</option>';
        return $html;
    }

    /** @return bool|int true/false, or the new row id for 'improvement' */
    public static function handle(array $input)
    {
        global $DB;
        $sprintId = (int)($input['sprint_id'] ?? 0);
        if ((string)($input['action'] ?? '') === 'signal_read') {
            return $DB->update('glpi_plugin_sprint_sprintsignals', ['is_read' => 1], [
                'id' => (int)($input['id'] ?? 0), 'users_id' => (int)Session::getLoginUserID(),
            ]);
        }
        if ($sprintId <= 0) return false;
        $action = (string)($input['action'] ?? '');
        $isScrumMaster = Config::isCurrentUserScrumMaster($sprintId) && Sprint::canUpdate();
        // Retro input and voting are open to every sprint member; toggling an
        // improvement done/open is also allowed for the facilitator of a
        // meeting of this sprint (guided retro, "previous actions" phase).
        // The rest stays Scrum-Master-only.
        if (in_array($action, ['improvement', 'improvement_vote'], true)) {
            if (!$isScrumMaster && !self::isCurrentUserMember($sprintId)) return false;
        } elseif ($action === 'improvement_toggle' && !$isScrumMaster) {
            if (!self::isCurrentUserMember($sprintId)
                || !self::isCurrentUserMeetingFacilitator($sprintId, (int)($input['meeting_id'] ?? 0))) {
                return false;
            }
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
                if (!$DB->insert('glpi_plugin_sprint_sprintimprovements', [
                    'plugin_sprint_sprints_id' => $sprintId, 'category' => $category,
                    'description' => $description, 'users_id' => $owner,
                    'due_date' => ($input['due_date'] ?? '') ?: null, 'status' => 'open', 'votes' => 0, 'carry_to_next' => 1,
                    'is_anonymous' => isset($input['is_anonymous']) ? 1 : 0,
                    'date_creation' => $now, 'date_mod' => $now,
                ])) return false;
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
                // Toggle: one vote per user, second click removes it.
                $id = (int)($input['id'] ?? 0);
                $rows = self::rows('glpi_plugin_sprint_sprintimprovements', ['id' => $id, 'plugin_sprint_sprints_id' => $sprintId]);
                $row = $rows ? reset($rows) : null;
                if (!$row) return false;
                $uid = (int)Session::getLoginUserID();
                $existing = self::rows('glpi_plugin_sprint_sprintimprovementvotes', [
                    'plugin_sprint_sprintimprovements_id' => $id, 'users_id' => $uid,
                ]);
                if ($existing) {
                    $DB->delete('glpi_plugin_sprint_sprintimprovementvotes', ['id' => (int)reset($existing)['id']]);
                    return $DB->update('glpi_plugin_sprint_sprintimprovements', ['votes' => max(0, (int)$row['votes'] - 1), 'date_mod' => $now], ['id' => $id]);
                }
                $DB->insert('glpi_plugin_sprint_sprintimprovementvotes', [
                    'plugin_sprint_sprintimprovements_id' => $id, 'users_id' => $uid, 'date_creation' => $now,
                ]);
                return $DB->update('glpi_plugin_sprint_sprintimprovements', ['votes' => (int)$row['votes'] + 1, 'date_mod' => $now], ['id' => $id]);
            case 'improvement_toggle':
                $id = (int)($input['id'] ?? 0);
                $rows = self::rows('glpi_plugin_sprint_sprintimprovements', ['id' => $id, 'plugin_sprint_sprints_id' => $sprintId]);
                $row = $rows ? reset($rows) : null;
                return $row ? $DB->update('glpi_plugin_sprint_sprintimprovements', ['status' => $row['status'] === 'done' ? 'open' : 'done', 'date_mod' => $now], ['id' => $id]) : false;
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
