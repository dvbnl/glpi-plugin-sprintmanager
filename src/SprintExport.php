<?php

namespace GlpiPlugin\Sprint;

use CommonGLPI;
use Plugin;
use Session;
use Html;

/**
 * SprintExport - End-of-sprint printable report (summary, charts, workload,
 * item list) as a self-contained, printer-friendly HTML view.
 *
 * Uses the browser's "Print / Save as PDF" pipeline rather than a server-side
 * PDF library — avoids a TCPDF/Dompdf dependency and matches what users see.
 */
class SprintExport extends CommonGLPI
{
    public static $rightname = 'plugin_sprint_sprint';

    public static function getTypeName($nb = 0): string
    {
        return __('Export report', 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-file-pdf';
    }

    public static function getExportURL(int $sprintId): string
    {
        return Plugin::getWebDir('sprint') . '/front/sprint.export.php?id=' . (int)$sprintId;
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Sprint) {
            return self::createTabEntry(self::getTypeName(), 0, $item::getType(), self::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof Sprint) {
            self::render($item, false);
            return true;
        }
        return false;
    }

    /**
     * Render the export page body (toolbar + sections). Caller owns the
     * surrounding html chrome (GLPI tab framework or front/sprint.export.php).
     *
     * @param bool $standalone true when rendered outside the tab framework (adds a "Back to Sprint" button).
     */
    public static function render(Sprint $sprint, bool $standalone = false): void
    {
        $sprintId = (int)$sprint->getID();

        echo "<div class='sprint-export-toolbar' style='display:flex;gap:8px;justify-content:space-between;align-items:center;margin-bottom:18px;'>";
        echo "<div>";
        if ($standalone) {
            echo "<a href='" . htmlescape(Sprint::getFormURLWithID($sprintId)) . "' class='btn btn-outline-secondary'>"
                . "<i class='fas fa-arrow-left me-1'></i>" . __('Back to Sprint', 'sprint')
                . "</a>";
        }
        echo "</div>";
        echo "<div style='display:flex;gap:8px;'>";
        echo "<button type='button' class='btn btn-outline-success sprint-csv-open'>"
            . "<i class='fas fa-file-csv me-1'></i>" . __('Download CSV', 'sprint')
            . "</button>";
        echo "<button type='button' class='btn btn-primary' onclick='window.print()'>"
            . "<i class='fas fa-print me-1'></i>" . __('Print / Save as PDF', 'sprint')
            . "</button>";
        echo "</div>";
        echo "</div>";

        echo "<div class='sprint-export-page'>";

        self::renderHeader($sprint);
        self::renderSummary($sprintId);
        self::renderBurndown($sprint);
        self::renderVelocity($sprint);
        self::renderMemberWorkload($sprintId);
        self::renderFastlane($sprintId);
        self::renderDependencies($sprintId);
        self::renderTeamActivity($sprintId);
        self::renderItemsBreakdown($sprintId);

        echo "<div class='sprint-export-footer' style='margin-top:32px;padding-top:12px;border-top:1px solid #dee2e6;font-size:0.78em;color:#6c757d;text-align:center;'>"
            . sprintf(__('Generated on %s', 'sprint'), Html::convDateTime(date('Y-m-d H:i:s')))
            . "</div>";

        echo "</div>"; // .sprint-export-page

        self::renderCsvOptionsUI($sprintId);
        self::renderPrintStyles();
    }

    private static function renderHeader(Sprint $sprint): void
    {
        $statuses = Sprint::getAllStatuses();
        $name     = htmlescape($sprint->fields['name'] ?? '');
        $status   = $statuses[$sprint->fields['status']] ?? (string)($sprint->fields['status'] ?? '');
        $start    = Html::convDateTime($sprint->fields['date_start'] ?? '');
        $end      = Html::convDateTime($sprint->fields['date_end'] ?? '');
        $scrumId  = (int)($sprint->fields['users_id'] ?? 0);
        $scrumName = $scrumId > 0 ? SprintCache::userName($scrumId) : __('Unassigned', 'sprint');
        $goal     = trim((string)($sprint->fields['goal'] ?? ''));
        $logoSrc   = self::resolveLogoSrc();

        echo "<div class='sprint-export-header' style='border-bottom:2px solid #0d6efd;padding-bottom:14px;margin-bottom:20px;display:flex;gap:18px;align-items:flex-start;'>";

        if ($logoSrc !== '') {
            echo "<div style='flex:0 0 auto;'>";
            echo "<img src='" . htmlescape($logoSrc) . "' alt='' "
                . "style='max-height:64px;max-width:200px;height:auto;width:auto;'>";
            echo "</div>";
        }

        echo "<div style='flex:1 1 auto;min-width:0;'>";
        echo "<div style='font-size:0.85em;color:#6c757d;text-transform:uppercase;letter-spacing:0.05em;'>"
            . __('Sprint report', 'sprint') . "</div>";
        echo "<h1 style='margin:4px 0 8px;font-size:1.7em;color:#212529;'>{$name}</h1>";
        echo "<div style='display:flex;flex-wrap:wrap;gap:18px;font-size:0.92em;color:#495057;'>";
        echo "<div><strong>" . __('Status') . ":</strong> " . htmlescape((string)$status) . "</div>";
        echo "<div><strong>" . __('Period', 'sprint') . ":</strong> "
            . htmlescape($start) . " — " . htmlescape($end) . "</div>";
        echo "<div><strong>" . __('Scrum Master', 'sprint') . ":</strong> " . htmlescape($scrumName) . "</div>";
        echo "</div>";
        if ($goal !== '') {
            echo "<div style='margin-top:10px;padding:10px 14px;background:#f1f8ff;border-left:3px solid #0d6efd;border-radius:4px;'>";
            echo "<div style='font-size:0.78em;color:#6c757d;margin-bottom:3px;text-transform:uppercase;letter-spacing:0.04em;'>"
                . __('Sprint goal', 'sprint') . "</div>";
            echo "<div>" . nl2br(htmlescape($goal)) . "</div>";
            echo "</div>";
        }
        echo "</div>"; // text column
        echo "</div>"; // header
    }

    /**
     * Resolve a logo for the report header, in preference order:
     *  1. plugin `report_logo_url` override, 2. GLPI `central_logo`,
     *  3. logo URL parsed from the entity/global custom CSS.
     *
     * Returns '' when nothing resolves (caller suppresses the <img> rather
     * than showing GLPI's default, which would mislead branded instances).
     * Result is an absolute URL, or a `data:` URI when readable on disk so a
     * saved PDF stays self-contained.
     */
    private static function resolveLogoSrc(): string
    {
        // 1. Plugin Config override
        $override = Config::getReportLogoUrl();
        if ($override !== '') {
            $resolved = self::resolveLogoCandidate($override);
            if ($resolved !== '') {
                return $resolved;
            }
            return $override; // honour what the admin typed even if we can't read it
        }

        // 2. GLPI central_logo
        global $CFG_GLPI;
        if (!empty($CFG_GLPI['central_logo'])) {
            $resolved = self::resolveLogoCandidate((string)$CFG_GLPI['central_logo']);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        // 3. Custom CSS — entity scope first (GLPI 10/11 stores per-entity
        //    custom CSS at glpi_entities.custom_css_code with the
        //    `enable_custom_css` toggle), then the global config.
        $customCss = self::collectCustomCss();
        if ($customCss !== '') {
            $cssLogo = self::extractLogoFromCss($customCss);
            if ($cssLogo !== '') {
                $resolved = self::resolveLogoCandidate($cssLogo);
                if ($resolved !== '') {
                    return $resolved;
                }
                return $cssLogo;
            }
        }

        return '';
    }

    /**
     * Turn a logo address (absolute/root-relative URL, or GLPI_ROOT-relative
     * path) into a `data:` URI (when readable on disk) or a fetchable URL.
     * Returns '' when nothing usable can be resolved.
     */
    private static function resolveLogoCandidate(string $candidate): string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return '';
        }

        // Already a data: URI — pass through.
        if (stripos($candidate, 'data:') === 0) {
            return $candidate;
        }

        $glpiRoot = defined('GLPI_ROOT') ? rtrim((string)constant('GLPI_ROOT'), '/') : '';

        // Absolute http(s) URL → embed as data: URI if it maps to a local file, else return as-is.
        if (preg_match('#^https?://#i', $candidate)) {
            $localPath = self::mapAbsoluteUrlToLocalPath($candidate);
            if ($localPath !== '' && is_readable($localPath) && is_file($localPath)) {
                $embedded = self::embedAsDataUri($localPath);
                if ($embedded !== '') {
                    return $embedded;
                }
            }
            return $candidate;
        }

        // Absolute filesystem path (rare but possible for the Config override).
        if ($candidate !== '' && $candidate[0] === '/' && @is_file($candidate)) {
            $embedded = self::embedAsDataUri($candidate);
            if ($embedded !== '') {
                return $embedded;
            }
        }

        // Treat as path relative to GLPI install.
        $rel = ltrim($candidate, '/');
        if ($glpiRoot !== '') {
            $abs = $glpiRoot . '/' . $rel;
            if (is_readable($abs) && is_file($abs)) {
                $embedded = self::embedAsDataUri($abs);
                if ($embedded !== '') {
                    return $embedded;
                }
            }
        }

        // Not readable on disk — fall back to a root_doc-anchored URL for the browser to try.
        global $CFG_GLPI;
        $rootDoc = (string)($CFG_GLPI['root_doc'] ?? '');
        if ($rootDoc !== '') {
            return $rootDoc . '/' . $rel;
        }
        return $candidate;
    }

    /**
     * Encode a local file as a `data:` URI. Returns '' on read failure or
     * when the file lives outside the GLPI install (containment guard, so a
     * config-supplied path can never leak arbitrary server files).
     */
    private static function embedAsDataUri(string $absPath): string
    {
        $real     = realpath($absPath);
        $glpiRoot = defined('GLPI_ROOT') ? realpath((string)constant('GLPI_ROOT')) : false;
        if ($real === false || $glpiRoot === false
            || strpos($real, rtrim($glpiRoot, '/') . '/') !== 0) {
            return '';
        }
        $bytes = @file_get_contents($real);
        if ($bytes === false || $bytes === '') {
            return '';
        }
        $mime = 'image/png';
        if (preg_match('/\.svg$/i', $absPath))            { $mime = 'image/svg+xml'; }
        elseif (preg_match('/\.(jpe?g)$/i', $absPath))    { $mime = 'image/jpeg'; }
        elseif (preg_match('/\.gif$/i', $absPath))        { $mime = 'image/gif'; }
        elseif (preg_match('/\.webp$/i', $absPath))       { $mime = 'image/webp'; }
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * Best-effort map of an absolute URL back to a GLPI_ROOT filesystem path
     * (so we can embed, not link). Returns '' for external/non-root_doc URLs.
     */
    private static function mapAbsoluteUrlToLocalPath(string $url): string
    {
        global $CFG_GLPI;

        $glpiRoot = defined('GLPI_ROOT') ? rtrim((string)constant('GLPI_ROOT'), '/') : '';
        if ($glpiRoot === '') {
            return '';
        }
        $rootDoc = (string)($CFG_GLPI['root_doc'] ?? '');

        $parts = parse_url($url);
        $path  = (string)($parts['path'] ?? '');
        if ($path === '') {
            return '';
        }
        if ($rootDoc !== '' && strpos($path, $rootDoc) === 0) {
            $path = substr($path, strlen($rootDoc));
        }
        $path = ltrim($path, '/');
        return $glpiRoot . '/' . $path;
    }

    /**
     * Concatenate every custom CSS body GLPI knows about (active entity tree
     * via getUsedConfig + global config keys) for downstream regex parsing.
     */
    private static function collectCustomCss(): string
    {
        global $CFG_GLPI;
        $css = '';

        // Entity-scoped: GLPI 10/11 stores per-entity custom CSS in glpi_entities.
        // getUsedConfig walks the entity tree to inherit a parent's setting.
        if (class_exists('Entity')) {
            $entityId = (int)\Session::getActiveEntity();
            try {
                $entityCss = \Entity::getUsedConfig('enable_custom_css', $entityId, 'custom_css_code', '');
                if (is_string($entityCss) && $entityCss !== '') {
                    $css .= "\n" . $entityCss;
                }
            } catch (\Throwable $e) {
                // Silently ignore — older GLPI versions or RBAC quirks.
            }
        }

        // Global config — key name varies by GLPI version, so try the common ones.
        foreach (['custom_css_code', 'custom_css', 'css_code'] as $key) {
            if (!empty($CFG_GLPI[$key]) && is_string($CFG_GLPI[$key])) {
                $css .= "\n" . $CFG_GLPI[$key];
            }
        }

        return $css;
    }

    /**
     * Best-effort regex pluck of a logo URL out of arbitrary CSS: first the
     * url() in any "logo" selector, then any url() pointing at a "logo" file.
     * Returns '' if nothing useful is found.
     */
    private static function extractLogoFromCss(string $css): string
    {
        if ($css === '') {
            return '';
        }

        if (preg_match_all('/([^{}]*logo[^{}]*)\{([^}]*)\}/i', $css, $blocks, PREG_SET_ORDER)) {
            foreach ($blocks as $block) {
                $body = (string)($block[2] ?? '');
                if (preg_match('/url\(\s*[\'"]?([^\'")\s]+)/i', $body, $m)) {
                    $url = trim((string)$m[1]);
                    if ($url !== '') {
                        return $url;
                    }
                }
            }
        }

        if (preg_match('/url\(\s*[\'"]?([^\'")\s]*logo[^\'")\s]*)/i', $css, $m)) {
            $url = trim((string)$m[1]);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private static function renderSummary(int $sprintId): void
    {
        $stats = self::computeStats($sprintId);

        echo "<h2 style='font-size:1.15em;margin:18px 0 10px;color:#0d6efd;'>"
            . "<i class='fas fa-chart-pie me-1'></i>" . __('Summary', 'sprint') . "</h2>";

        $cards = [
            [__('Total Items', 'sprint'), $stats['total_items'], '#6c757d'],
            [__('Done', 'sprint'),         $stats['done_items'],   '#198754'],
            [__('In Progress', 'sprint'),  $stats['in_progress'],  '#0d6efd'],
            [__('In Review', 'sprint'),    $stats['review_items'], '#6f42c1'],
            [__('Blocked', 'sprint'),      $stats['blocked_items'],'#dc3545'],
            [__('To Do', 'sprint'),        $stats['todo_items'],   '#adb5bd'],
            [__('Story Points', 'sprint'), $stats['done_points'] . ' / ' . $stats['total_points'], '#d68a00'],
        ];
        echo "<div class='sprint-export-cards' style='display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:14px;'>";
        foreach ($cards as [$label, $value, $color]) {
            echo "<div style='border:1px solid #e9ecef;border-top:3px solid {$color};border-radius:8px;padding:10px 12px;text-align:center;background:#fff;'>";
            echo "<div style='font-size:1.4em;font-weight:700;color:{$color};line-height:1.2;'>"
                . htmlescape((string)$value) . "</div>";
            echo "<div style='font-size:0.74em;color:#6c757d;margin-top:2px;text-transform:uppercase;letter-spacing:0.03em;'>"
                . htmlescape((string)$label) . "</div>";
            echo "</div>";
        }
        echo "</div>";

        // Stacked progress bar (mirrors the dashboard look). Weighted by
        // allocated capacity (%) so the bar reflects effort rather than a raw
        // item headcount; falls back to item count when no capacities are set.
        $segments = [
            [SprintItem::STATUS_DONE,        __('Done', 'sprint'),        '#198754', $stats['done_items'],   (int)$stats['done_cap']],
            [SprintItem::STATUS_IN_PROGRESS, __('In Progress', 'sprint'), '#0d6efd', $stats['in_progress'],  (int)$stats['in_progress_cap']],
            [SprintItem::STATUS_REVIEW,      __('In Review', 'sprint'),   '#6f42c1', $stats['review_items'], (int)$stats['review_cap']],
            [SprintItem::STATUS_BLOCKED,     __('Blocked', 'sprint'),     '#dc3545', $stats['blocked_items'],(int)$stats['blocked_cap']],
            [SprintItem::STATUS_TODO,        __('To Do', 'sprint'),       '#d5d8dc', $stats['todo_items'],   (int)$stats['todo_cap']],
        ];

        $totalCap   = array_sum(array_map(fn($s) => $s[4], $segments));
        $weightIdx  = $totalCap > 0 ? 4 : 3;
        $weightDen  = max(array_sum(array_map(fn($s) => $s[$weightIdx], $segments)), 1);

        echo "<div style='display:flex;gap:14px;justify-content:center;margin-bottom:6px;font-size:0.78em;color:#6c757d;flex-wrap:wrap;'>";
        foreach ($segments as $seg) {
            $pct = round(($seg[$weightIdx] / $weightDen) * 100, 1);
            echo "<span><span style='display:inline-block;width:9px;height:9px;border-radius:50%;background:{$seg[2]};margin-right:4px;vertical-align:middle;'></span>"
                . htmlescape((string)$seg[1]) . " {$pct}%</span>";
        }
        echo "</div>";
        echo "<div style='width:100%;height:14px;background:#e9ecef;border-radius:7px;overflow:hidden;display:flex;'>";
        foreach ($segments as $seg) {
            $pct = round(($seg[$weightIdx] / $weightDen) * 100, 2);
            if ($pct <= 0) {
                continue;
            }
            echo "<div style='width:{$pct}%;min-width:4px;height:100%;background:{$seg[2]};'></div>";
        }
        echo "</div>";
    }

    private static function computeStats(int $sprintId): array
    {
        $stats = [
            'total_items'   => 0,
            'todo_items'    => 0,
            'in_progress'   => 0,
            'review_items'  => 0,
            'done_items'    => 0,
            'blocked_items' => 0,
            'total_points'  => 0,
            'done_points'   => 0,
            'fastlane_items' => 0,
            // Allocated capacity (%) per status — drives the progress bar weight.
            'todo_cap'      => 0,
            'in_progress_cap' => 0,
            'review_cap'    => 0,
            'done_cap'      => 0,
            'blocked_cap'   => 0,
        ];
        $si = new SprintItem();
        foreach ($si->find(['plugin_sprint_sprints_id' => $sprintId]) as $row) {
            $stats['total_items']++;
            $stats['total_points'] += (int)$row['story_points'];
            // Min weight 1 per item so zero-capacity items keep a slice; real
            // capacities dominate. Mirrors the dashboard. See renderSummary().
            $cap = max((float)($row['capacity'] ?? 0), 1);
            if (!empty($row['is_fastlane'])) {
                $stats['fastlane_items']++;
            }
            switch ($row['status']) {
                case SprintItem::STATUS_TODO:        $stats['todo_items']++; $stats['todo_cap'] += $cap; break;
                case SprintItem::STATUS_IN_PROGRESS: $stats['in_progress']++; $stats['in_progress_cap'] += $cap; break;
                case SprintItem::STATUS_REVIEW:      $stats['review_items']++; $stats['review_cap'] += $cap; break;
                case SprintItem::STATUS_DONE:
                    $stats['done_items']++;
                    $stats['done_points'] += (int)$row['story_points'];
                    $stats['done_cap'] += $cap;
                    break;
                case SprintItem::STATUS_BLOCKED:     $stats['blocked_items']++; $stats['blocked_cap'] += $cap; break;
            }
        }
        return $stats;
    }

    private static function renderMemberWorkload(int $sprintId): void
    {
        $member  = new SprintMember();
        $members = $member->find(['plugin_sprint_sprints_id' => $sprintId], ['role ASC']);

        echo "<h2 style='font-size:1.15em;margin:22px 0 10px;color:#0d6efd;page-break-before:auto;'>"
            . "<i class='fas fa-users me-1'></i>" . __('Workload per member', 'sprint') . "</h2>";

        if (count($members) === 0) {
            echo "<div style='padding:14px;background:#f8f9fa;border:1px dashed #dee2e6;border-radius:6px;color:#6c757d;'>"
                . __('No team members defined for this sprint.', 'sprint') . "</div>";
            return;
        }

        $roles = SprintMember::getAllRoles();
        $si    = new SprintItem();

        echo "<table style='width:100%;border-collapse:collapse;font-size:0.9em;'>";
        echo "<thead><tr style='background:#f1f3f5;'>";
        echo "<th style='text-align:left;padding:8px;border-bottom:1px solid #dee2e6;'>" . __('Member', 'sprint') . "</th>";
        echo "<th style='text-align:left;padding:8px;border-bottom:1px solid #dee2e6;'>" . __('Role', 'sprint') . "</th>";
        echo "<th style='text-align:right;padding:8px;border-bottom:1px solid #dee2e6;'>" . __('Capacity', 'sprint') . "</th>";
        echo "<th style='text-align:right;padding:8px;border-bottom:1px solid #dee2e6;'>" . __('Regular', 'sprint') . "</th>";
        echo "<th style='text-align:right;padding:8px;border-bottom:1px solid #dee2e6;'>" . __('Fastlane', 'sprint') . "</th>";
        echo "<th style='text-align:right;padding:8px;border-bottom:1px solid #dee2e6;'>" . __('Used', 'sprint') . "</th>";
        echo "<th style='text-align:right;padding:8px;border-bottom:1px solid #dee2e6;'>" . __('Free', 'sprint') . "</th>";
        echo "<th style='padding:8px;border-bottom:1px solid #dee2e6;width:160px;'>" . __('Distribution', 'sprint') . "</th>";
        echo "</tr></thead><tbody>";

        foreach ($members as $row) {
            $userId   = (int)$row['users_id'];
            // Effective capacity (availability exceptions applied), matching the dashboard.
            $totalCap = SprintAgility::effectiveCapacity(
                $sprintId,
                $userId,
                SprintMember::normalizeCapacity($row['capacity_percent'])
            );
            $roleName = $roles[$row['role']] ?? $row['role'];

            $regularUsed = 0.0;
            foreach ($si->find([
                'plugin_sprint_sprints_id' => $sprintId,
                'users_id'                 => $userId,
                'is_fastlane'              => 0,
            ]) as $r) {
                $regularUsed += (float)($r['capacity'] ?? 0);
            }
            $fastlaneUsed = SprintFastlaneMember::getUsedFastlaneCapacityForUser($sprintId, $userId);
            $used         = $regularUsed + $fastlaneUsed;
            $free         = max($totalCap - $used, 0);
            $regWidth     = $totalCap > 0 ? min(round(($regularUsed / $totalCap) * 100), 100) : 0;
            $fastWidth    = $totalCap > 0 ? min(round(($fastlaneUsed / $totalCap) * 100), 100 - $regWidth) : 0;
            $regBg        = $used >= $totalCap ? '#dc3545' : ($used >= ($totalCap * 0.8) ? '#e67e22' : '#198754');

            echo "<tr style='border-bottom:1px solid #e9ecef;'>";
            echo "<td style='padding:6px 8px;'>" . htmlescape(SprintCache::userName($userId)) . "</td>";
            echo "<td style='padding:6px 8px;color:#6c757d;'>" . htmlescape((string)$roleName) . "</td>";
            echo "<td style='padding:6px 8px;text-align:right;'>{$totalCap}%</td>";
            echo "<td style='padding:6px 8px;text-align:right;'>{$regularUsed}%</td>";
            echo "<td style='padding:6px 8px;text-align:right;color:" . ($fastlaneUsed > 0 ? '#fd7e14' : '#6c757d') . ";'>{$fastlaneUsed}%</td>";
            echo "<td style='padding:6px 8px;text-align:right;font-weight:600;'>{$used}%</td>";
            echo "<td style='padding:6px 8px;text-align:right;color:#6c757d;'>{$free}%</td>";
            echo "<td style='padding:6px 8px;'>";
            echo "<div style='display:flex;height:10px;background:#e9ecef;border-radius:5px;overflow:hidden;'>";
            if ($regWidth > 0)  { echo "<div style='width:{$regWidth}%;height:100%;background:{$regBg};'></div>"; }
            if ($fastWidth > 0) { echo "<div style='width:{$fastWidth}%;height:100%;background:#fd7e14;'></div>"; }
            echo "</div>";
            echo "</td>";
            echo "</tr>";
        }

        echo "</tbody></table>";
    }

    /**
     * Fastlane section: per-item status/points/allocations plus a per-member
     * capacity summary, separating interrupt-driven work from planned work.
     */
    private static function renderFastlane(int $sprintId): void
    {
        echo "<h2 style='font-size:1.15em;margin:22px 0 10px;color:#fd7e14;page-break-before:auto;'>"
            . "<i class='fas fa-bolt me-1'></i>" . __('Fastlane', 'sprint') . "</h2>";

        $sprintObj   = new Sprint();
        $fastlaneCap = ($sprintObj->getFromDB($sprintId))
            ? (float)($sprintObj->fields['fastlane_capacity'] ?? 0)
            : 0.0;
        if ($fastlaneCap > 0) {
            $fastTotal = SprintFastlaneMember::getTotalFastlaneCapacityForSprint($sprintId);
            echo "<div style='margin:-4px 0 10px;font-size:0.86em;color:#6c757d;'>"
                . sprintf(__('Total capacity: %1$s%% / %2$s%%', 'sprint'), SprintMember::formatCapacity($fastTotal), SprintMember::formatCapacity($fastlaneCap));
            if ($fastTotal > $fastlaneCap) {
                echo " <span style='color:#dc3545;font-weight:700;'>"
                    . sprintf(__('+%s%% overflow', 'sprint'), SprintMember::formatCapacity($fastTotal - $fastlaneCap))
                    . "</span>";
            }
            echo "</div>";
        }

        $si = new SprintItem();
        $fastItems = $si->find(
            [
                'plugin_sprint_sprints_id' => $sprintId,
                'is_fastlane'              => 1,
            ],
            ['priority DESC', 'sort_order ASC']
        );

        if (count($fastItems) === 0) {
            echo "<div style='padding:14px;background:#fff8e1;border:1px dashed #fd7e14;border-radius:6px;color:#8a6d3b;'>"
                . "<i class='fas fa-bolt me-1' style='color:#fd7e14;'></i>"
                . __('No fastlane items in this sprint.', 'sprint')
                . "</div>";
            return;
        }

        $statuses = SprintItem::getAllStatuses();
        $statusBgColors = [
            SprintItem::STATUS_TODO        => '#6c757d',
            SprintItem::STATUS_IN_PROGRESS => '#0d6efd',
            SprintItem::STATUS_REVIEW      => '#6f42c1',
            SprintItem::STATUS_DEPENDENCY  => '#20c997',
            SprintItem::STATUS_DONE        => '#198754',
            SprintItem::STATUS_BLOCKED     => '#dc3545',
        ];
        $typeLabels = [
            ''            => __('Manual', 'sprint'),
            'Ticket'      => __('Ticket'),
            'Change'      => __('Change'),
            'Problem'     => __('Problem'),
            'ProjectTask' => __('Project task'),
        ];

        $fastIds  = array_map(fn($r) => (int)$r['id'], $fastItems);
        $tagsById = SprintItem::getTagsForItems($fastIds);
        $depsByIdFast = SprintItemDependency::getOpenSummariesForItems($fastIds);

        // Per-item table — name, type, status, story points, allocations.
        echo "<table style='width:100%;border-collapse:collapse;font-size:0.86em;margin-bottom:14px;'>";
        echo "<thead><tr style='background:#fff3cd;'>";
        echo "<th style='text-align:left;padding:6px 8px;border-bottom:1px solid #ffeeba;'>" . __('Name') . "</th>";
        echo "<th style='text-align:left;padding:6px 8px;border-bottom:1px solid #ffeeba;'>" . __('Type') . "</th>";
        echo "<th style='text-align:left;padding:6px 8px;border-bottom:1px solid #ffeeba;'>" . __('Status') . "</th>";
        echo "<th style='text-align:right;padding:6px 8px;border-bottom:1px solid #ffeeba;'>" . __('Story Points', 'sprint') . "</th>";
        echo "<th style='text-align:left;padding:6px 8px;border-bottom:1px solid #ffeeba;'>" . __('Allocations', 'sprint') . "</th>";
        echo "<th style='text-align:right;padding:6px 8px;border-bottom:1px solid #ffeeba;'>" . __('Total %', 'sprint') . "</th>";
        echo "</tr></thead><tbody>";

        // Aggregate per-user totals in this pass to feed the summary table below.
        $perUserTotal = [];
        $rel = new SprintFastlaneMember();

        foreach ($fastItems as $row) {
            $itemId   = (int)$row['id'];
            $statusBg = $statusBgColors[$row['status']] ?? '#6c757d';
            $type     = $typeLabels[$row['itemtype']] ?? $row['itemtype'];

            $allocations = $rel->find(['plugin_sprint_sprintitems_id' => $itemId]);
            $allocText   = [];
            $itemTotal   = 0.0;
            foreach ($allocations as $alloc) {
                $uid = (int)$alloc['users_id'];
                $cap = (float)$alloc['capacity'];
                $itemTotal += $cap;
                $perUserTotal[$uid] = ($perUserTotal[$uid] ?? 0) + $cap;
                $allocText[] = htmlescape(SprintCache::userName($uid)) . " <span style='color:#6c757d;'>(" . SprintMember::formatCapacity($cap) . "%)</span>";
            }
            $allocHtml = $allocText
                ? implode(', ', $allocText)
                : "<span style='color:#adb5bd;font-style:italic;'>" . __('No allocations', 'sprint') . "</span>";

            $rowTags = $tagsById[$itemId] ?? [];
            $rowDeps = $depsByIdFast[$itemId] ?? [];

            echo "<tr style='border-bottom:1px solid #fff3cd;page-break-inside:avoid;'>";
            echo "<td style='padding:5px 8px;'>"
                . "<i class='fas fa-bolt' style='color:#fd7e14;margin-right:4px;'></i>"
                . htmlescape((string)$row['name']) . SprintItem::renderTagPills($rowTags) . SprintItem::renderDependencyBadge($rowDeps) . "</td>";
            echo "<td style='padding:5px 8px;color:#6c757d;'>" . htmlescape((string)$type) . "</td>";
            echo "<td style='padding:5px 8px;'>"
                . "<span style='display:inline-block;padding:2px 8px;border-radius:12px;color:#fff;background:{$statusBg};font-size:0.78em;'>"
                . htmlescape($statuses[$row['status']] ?? $row['status'])
                . "</span></td>";
            echo "<td style='padding:5px 8px;text-align:right;'>" . (int)$row['story_points'] . "</td>";
            echo "<td style='padding:5px 8px;'>" . $allocHtml . "</td>";
            echo "<td style='padding:5px 8px;text-align:right;font-weight:600;'>" . SprintMember::formatCapacity($itemTotal) . "%</td>";
            echo "</tr>";
        }

        echo "</tbody></table>";

        // Per-member summary — only render when at least one allocation exists.
        if (!empty($perUserTotal)) {
            arsort($perUserTotal);

            // Find the highest total to drive the relative bar widths.
            $maxTotal = max($perUserTotal);

            echo "<h3 style='font-size:0.95em;margin:8px 0 6px;color:#6c757d;'>"
                . "<i class='fas fa-bolt me-1' style='color:#fd7e14;'></i>"
                . __('Fastlane capacity per member', 'sprint')
                . "</h3>";
            echo "<table style='width:100%;border-collapse:collapse;font-size:0.86em;'>";
            echo "<thead><tr style='background:#f1f3f5;'>";
            echo "<th style='text-align:left;padding:6px 8px;border-bottom:1px solid #dee2e6;'>" . __('Member', 'sprint') . "</th>";
            echo "<th style='text-align:right;padding:6px 8px;border-bottom:1px solid #dee2e6;'>" . __('Fastlane', 'sprint') . " %</th>";
            echo "<th style='padding:6px 8px;border-bottom:1px solid #dee2e6;'>" . __('Distribution', 'sprint') . "</th>";
            echo "</tr></thead><tbody>";
            foreach ($perUserTotal as $uid => $total) {
                $pct = $maxTotal > 0 ? round(($total / $maxTotal) * 100) : 0;
                echo "<tr style='border-bottom:1px solid #e9ecef;'>";
                echo "<td style='padding:5px 8px;'>" . htmlescape(SprintCache::userName((int)$uid)) . "</td>";
                echo "<td style='padding:5px 8px;text-align:right;font-weight:600;color:#fd7e14;'>" . SprintMember::formatCapacity($total) . "%</td>";
                echo "<td style='padding:5px 8px;'>";
                echo "<div style='height:8px;background:#fff3cd;border-radius:4px;overflow:hidden;'>";
                echo "<div style='width:{$pct}%;height:100%;background:#fd7e14;'></div>";
                echo "</div>";
                echo "</td>";
                echo "</tr>";
            }
            echo "</tbody></table>";
        }
    }

    /**
     * Dependency section: one row per sprint item with dependency helpers,
     * resolved helpers struck through, mirroring the dashboard widget.
     */
    private static function renderDependencies(int $sprintId): void
    {
        if (!SprintItemDependency::isTableReady()) {
            return;
        }

        echo "<h2 style='font-size:1.15em;margin:22px 0 10px;color:#20c997;page-break-before:auto;'>"
            . "<i class='fas fa-link me-1'></i>" . __('Dependencies', 'sprint') . "</h2>";

        $si          = new SprintItem();
        $sprintItems = $si->find(['plugin_sprint_sprints_id' => $sprintId]);

        $relRowsByItem = [];
        if (count($sprintItems) > 0) {
            $rel = new SprintItemDependency();
            foreach ($rel->find(['plugin_sprint_sprintitems_id' => array_keys($sprintItems)], ['date_creation ASC']) as $r) {
                $relRowsByItem[(int)$r['plugin_sprint_sprintitems_id']][] = $r;
            }
        }

        if (count($relRowsByItem) === 0) {
            echo "<div style='padding:14px;background:#e6fcf5;border:1px dashed #20c997;border-radius:6px;color:#0c6b58;'>"
                . "<i class='fas fa-link me-1' style='color:#20c997;'></i>"
                . __('No dependencies in this sprint.', 'sprint')
                . "</div>";
            return;
        }

        $statuses = SprintItem::getAllStatuses();
        $statusBgColors = [
            SprintItem::STATUS_TODO        => '#6c757d',
            SprintItem::STATUS_IN_PROGRESS => '#0d6efd',
            SprintItem::STATUS_REVIEW      => '#6f42c1',
            SprintItem::STATUS_DEPENDENCY  => '#20c997',
            SprintItem::STATUS_DONE        => '#198754',
            SprintItem::STATUS_BLOCKED     => '#dc3545',
        ];

        echo "<table style='width:100%;border-collapse:collapse;font-size:0.86em;margin-bottom:14px;'>";
        echo "<thead><tr style='background:#d1f2ea;'>";
        echo "<th style='text-align:left;padding:6px 8px;border-bottom:1px solid #a3e4d3;'>" . __('Name') . "</th>";
        echo "<th style='text-align:left;padding:6px 8px;border-bottom:1px solid #a3e4d3;'>" . __('Owner', 'sprint') . "</th>";
        echo "<th style='text-align:left;padding:6px 8px;border-bottom:1px solid #a3e4d3;'>" . __('Status') . "</th>";
        echo "<th style='text-align:left;padding:6px 8px;border-bottom:1px solid #a3e4d3;'>" . __('Helpers', 'sprint') . "</th>";
        echo "<th style='text-align:right;padding:6px 8px;border-bottom:1px solid #a3e4d3;'>" . __('Open total', 'sprint') . "</th>";
        echo "</tr></thead><tbody>";

        foreach ($relRowsByItem as $itemId => $relRows) {
            $row = $sprintItems[$itemId];

            $openCap     = 0.0;
            $helperLines = [];
            foreach ($relRows as $r) {
                $cap      = (float)$r['capacity'];
                $capLabel = SprintMember::formatCapacity($cap);
                $name = htmlescape(SprintCache::userName((int)$r['users_id']));
                if ((int)($r['is_resolved'] ?? 0) === 1) {
                    $helperLines[] = "<span style='color:#6c757d;text-decoration:line-through;'>{$name} ({$capLabel}%)</span>";
                } else {
                    $openCap      += $cap;
                    $helperLines[] = "{$name} ({$capLabel}%)";
                }
            }

            $statusBg  = $statusBgColors[$row['status']] ?? '#6c757d';
            $ownerName = ((int)$row['users_id'] > 0)
                ? htmlescape(SprintCache::userName((int)$row['users_id']))
                : "<span style='color:#adb5bd;font-style:italic;'>" . __('Unassigned', 'sprint') . "</span>";

            echo "<tr style='border-bottom:1px solid #d1f2ea;page-break-inside:avoid;'>";
            echo "<td style='padding:5px 8px;'>"
                . "<i class='fas fa-link' style='color:#20c997;margin-right:4px;'></i>"
                . htmlescape((string)$row['name']) . "</td>";
            echo "<td style='padding:5px 8px;'>{$ownerName}</td>";
            echo "<td style='padding:5px 8px;'>"
                . "<span style='display:inline-block;padding:2px 8px;border-radius:12px;color:#fff;background:{$statusBg};font-size:0.78em;'>"
                . htmlescape($statuses[$row['status']] ?? $row['status'])
                . "</span></td>";
            echo "<td style='padding:5px 8px;'>" . implode('<br>', $helperLines) . "</td>";
            echo "<td style='padding:5px 8px;text-align:right;font-weight:600;'>{$openCap}%</td>";
            echo "</tr>";
        }

        echo "</tbody></table>";
    }

    private static function renderTeamActivity(int $sprintId): void
    {
        $data    = SprintAudit::getMemberActivity($sprintId);
        $dates   = $data['dates'];
        $members = $data['members'];

        echo "<h2 style='font-size:1.15em;margin:22px 0 10px;color:#0d6efd;page-break-before:auto;'>"
            . "<i class='fas fa-chart-line me-1'></i>" . __('Team activity', 'sprint') . "</h2>";

        if (count($dates) < 2 || count($members) === 0) {
            echo "<div style='padding:14px;background:#f8f9fa;border:1px dashed #dee2e6;border-radius:6px;color:#6c757d;'>"
                . __('No tracked activity to chart for this sprint.', 'sprint') . "</div>";
            return;
        }

        // Inline SVG chart, sized for letter/A4 print (no overflow/scroll).
        $width  = 760;
        $height = 220;
        $padL   = 40;
        $padR   = 20;
        $padT   = 16;
        $padB   = 36;
        $plotW  = $width - $padL - $padR;
        $plotH  = $height - $padT - $padB;

        $max = 1;
        foreach ($members as $m) {
            foreach ($m['counts'] as $c) {
                if ($c > $max) { $max = $c; }
            }
        }
        $yTickStep = (int)max(1, ceil($max / 4));
        $yMax      = $yTickStep * 4;

        $nDates = count($dates);
        $xStep  = ($nDates > 1) ? $plotW / ($nDates - 1) : 0;
        $xAt    = fn(int $i) => $padL + ($xStep * $i);
        $yAt    = fn(int $v) => $padT + $plotH - ($plotH * ($v / $yMax));

        echo "<svg class='sprint-responsive-chart' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$width} {$height}' "
            . "preserveAspectRatio='xMinYMin meet' style='width:100%;height:auto;display:block;font-family:sans-serif;font-size:11px;'>";

        for ($t = 0; $t <= 4; $t++) {
            $yv = (int)round($yMax * $t / 4);
            $y  = $yAt($yv);
            $yStr = number_format($y, 2, '.', '');
            echo "<line x1='{$padL}' y1='{$yStr}' x2='" . ($padL + $plotW) . "' y2='{$yStr}' stroke='#e9ecef' stroke-width='1' />";
            echo "<text x='" . ($padL - 6) . "' y='" . number_format($y + 3, 2, '.', '') . "' text-anchor='end' fill='#6c757d'>{$yv}</text>";
        }

        $labelEvery = max(1, (int)ceil($nDates / 10));
        for ($i = 0; $i < $nDates; $i++) {
            if ($i % $labelEvery !== 0 && $i !== $nDates - 1) {
                continue;
            }
            $x = $xAt($i);
            $xStr = number_format($x, 2, '.', '');
            $ts = strtotime($dates[$i]);
            $label = $ts ? date('d/m', $ts) : $dates[$i];
            echo "<text x='{$xStr}' y='" . ($padT + $plotH + 16) . "' text-anchor='middle' fill='#6c757d'>"
                . htmlescape($label) . "</text>";
        }

        echo "<line x1='{$padL}' y1='{$padT}' x2='{$padL}' y2='" . ($padT + $plotH) . "' stroke='#adb5bd' stroke-width='1' />";
        echo "<line x1='{$padL}' y1='" . ($padT + $plotH) . "' x2='" . ($padL + $plotW) . "' y2='" . ($padT + $plotH) . "' stroke='#adb5bd' stroke-width='1' />";

        foreach ($members as $idx => $m) {
            $points = [];
            foreach ($m['counts'] as $i => $c) {
                $points[] = number_format($xAt($i), 2, '.', '') . ',' . number_format($yAt((int)$c), 2, '.', '');
            }
            $color = htmlescape((string)$m['color']);
            $pts   = implode(' ', $points);
            echo "<polyline points='{$pts}' fill='none' stroke='{$color}' stroke-width='2' stroke-linejoin='round' stroke-linecap='round' />";
            foreach ($m['counts'] as $i => $c) {
                if ($c <= 0) { continue; }
                $cx = number_format($xAt($i), 2, '.', '');
                $cy = number_format($yAt((int)$c), 2, '.', '');
                echo "<circle cx='{$cx}' cy='{$cy}' r='2.5' fill='{$color}' />";
            }
        }
        echo "</svg>";

        echo "<div style='display:flex;flex-wrap:wrap;gap:12px;margin-top:8px;font-size:0.85em;'>";
        foreach ($members as $m) {
            $color = htmlescape((string)$m['color']);
            echo "<div style='display:flex;align-items:center;gap:6px;'>"
                . "<span style='display:inline-block;width:14px;height:3px;background:{$color};border-radius:2px;'></span>"
                . "<span>" . htmlescape((string)$m['name'])
                . " <span style='color:#6c757d;'>(" . (int)$m['total'] . ")</span></span>"
                . "</div>";
        }
        echo "</div>";
    }

    /**
     * Burndown chart for the report — reuses the dashboard's chart body so the export matches the dashboard.
     */
    private static function renderBurndown(Sprint $sprint): void
    {
        echo "<h2 style='font-size:1.15em;margin:22px 0 10px;color:#0d6efd;page-break-before:auto;'>"
            . "<i class='fas fa-chart-area me-1'></i>" . __('Burndown', 'sprint') . "</h2>";
        SprintDashboard::renderBurndownChartBody($sprint);
    }

    /**
     * Velocity chart in the printable report (see {@see renderBurndown()}).
     */
    private static function renderVelocity(Sprint $sprint): void
    {
        echo "<h2 style='font-size:1.15em;margin:22px 0 10px;color:#0d6efd;page-break-before:auto;'>"
            . "<i class='fas fa-chart-column me-1'></i>" . __('Velocity', 'sprint') . "</h2>";
        SprintDashboard::renderVelocityChartBody($sprint);
    }

    private static function renderItemsBreakdown(int $sprintId): void
    {
        $statuses = SprintItem::getAllStatuses();
        $si       = new SprintItem();
        $items    = $si->find(['plugin_sprint_sprints_id' => $sprintId], ['is_fastlane DESC', 'priority DESC', 'sort_order ASC']);

        echo "<h2 style='font-size:1.15em;margin:22px 0 10px;color:#0d6efd;page-break-before:auto;'>"
            . "<i class='fas fa-list-ul me-1'></i>" . __('Sprint items', 'sprint')
            . " <span style='color:#6c757d;font-weight:400;font-size:0.85em;'>(" . count($items) . ")</span>"
            . "</h2>";

        if (count($items) === 0) {
            echo "<div style='padding:14px;background:#f8f9fa;border:1px dashed #dee2e6;border-radius:6px;color:#6c757d;'>"
                . __('No items in this sprint.', 'sprint') . "</div>";
            return;
        }

        $statusBgColors = [
            SprintItem::STATUS_TODO        => '#6c757d',
            SprintItem::STATUS_IN_PROGRESS => '#0d6efd',
            SprintItem::STATUS_REVIEW      => '#6f42c1',
            SprintItem::STATUS_DEPENDENCY  => '#20c997',
            SprintItem::STATUS_DONE        => '#198754',
            SprintItem::STATUS_BLOCKED     => '#dc3545',
        ];

        $depsById = SprintItemDependency::getOpenSummariesForItems(array_map(fn($r) => (int)$r['id'], $items));

        echo "<table style='width:100%;border-collapse:collapse;font-size:0.86em;'>";
        echo "<thead><tr style='background:#f1f3f5;'>";
        echo "<th style='text-align:left;padding:6px 8px;border-bottom:1px solid #dee2e6;width:24px;'></th>";
        echo "<th style='text-align:left;padding:6px 8px;border-bottom:1px solid #dee2e6;'>" . __('Name') . "</th>";
        echo "<th style='text-align:left;padding:6px 8px;border-bottom:1px solid #dee2e6;'>" . __('Type') . "</th>";
        echo "<th style='text-align:left;padding:6px 8px;border-bottom:1px solid #dee2e6;'>" . __('Category', 'sprint') . "</th>";
        echo "<th style='text-align:left;padding:6px 8px;border-bottom:1px solid #dee2e6;'>" . __('Owner', 'sprint') . "</th>";
        echo "<th style='text-align:left;padding:6px 8px;border-bottom:1px solid #dee2e6;'>" . __('Status') . "</th>";
        echo "<th style='text-align:right;padding:6px 8px;border-bottom:1px solid #dee2e6;'>" . __('Story Points', 'sprint') . "</th>";
        echo "<th style='text-align:right;padding:6px 8px;border-bottom:1px solid #dee2e6;'>" . __('Capacity', 'sprint') . " %</th>";
        echo "</tr></thead><tbody>";

        $typeLabels = [
            ''            => __('Manual', 'sprint'),
            'Ticket'      => __('Ticket'),
            'Change'      => __('Change'),
            'Problem'     => __('Problem'),
            'ProjectTask' => __('Project task'),
        ];

        foreach ($items as $row) {
            $statusBg = $statusBgColors[$row['status']] ?? '#6c757d';
            $owner    = ((int)$row['users_id'] > 0) ? SprintCache::userName((int)$row['users_id']) : __('Unassigned', 'sprint');
            $type     = $typeLabels[$row['itemtype']] ?? $row['itemtype'];
            $isFast   = !empty($row['is_fastlane']);

            echo "<tr style='border-bottom:1px solid #e9ecef;page-break-inside:avoid;'>";
            echo "<td style='padding:5px 8px;text-align:center;'>"
                . ($isFast ? "<i class='fas fa-bolt' style='color:#fd7e14;' title='" . htmlescape(__('Fastlane', 'sprint')) . "'></i>" : '')
                . "</td>";
            $rowDeps = $depsById[(int)$row['id']] ?? [];
            echo "<td style='padding:5px 8px;'>" . htmlescape((string)$row['name']) . SprintItem::renderDependencyBadge($rowDeps) . "</td>";
            echo "<td style='padding:5px 8px;color:#6c757d;'>" . htmlescape((string)$type) . "</td>";
            $catId = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
            echo "<td style='padding:5px 8px;color:#6c757d;'>"
                . ($catId > 0 ? htmlescape(SprintCategory::getFullNameFor($catId)) : '-')
                . "</td>";
            echo "<td style='padding:5px 8px;'>" . htmlescape((string)$owner) . "</td>";
            echo "<td style='padding:5px 8px;'>"
                . "<span style='display:inline-block;padding:2px 8px;border-radius:12px;color:#fff;background:{$statusBg};font-size:0.78em;'>"
                . htmlescape($statuses[$row['status']] ?? $row['status'])
                . "</span></td>";
            echo "<td style='padding:5px 8px;text-align:right;'>" . (int)$row['story_points'] . "</td>";
            echo "<td style='padding:5px 8px;text-align:right;'>" . SprintMember::formatCapacity($row['capacity'] ?? 0) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }

    /**
     * "Download CSV" dialog: pick the sections, the item columns and the
     * categories to include, and whether the item rows are split into one
     * block per category. The choice is turned into a query string and handed
     * to front/sprint.export.php, which streams the file.
     *
     * Deliberately not a <form>: this tab renders inside GLPI's own form on
     * some pages, and nested forms do not submit.
     */
    private static function renderCsvOptionsUI(int $sprintId): void
    {
        $baseUrl    = self::getExportURL($sprintId);
        $categories = SprintCategory::getAll(false);

        echo "<div class='modal fade sprint-csv-modal' id='sprint-csv-modal' tabindex='-1' aria-hidden='true'>";
        echo "<div class='modal-dialog modal-dialog-centered modal-lg'>";
        echo "<div class='modal-content'>";
        echo "<div class='modal-header'>";
        echo "<h5 class='modal-title'><i class='fas fa-file-csv me-1'></i>" . __('Download CSV', 'sprint') . "</h5>";
        echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>";
        echo "</div>";
        echo "<div class='modal-body'>";
        echo "<p class='text-muted sprint-small'>"
            . htmlescape(__('Tick what the file should contain. Every ticked section is written as its own block, one after another.', 'sprint'))
            . "</p>";

        // --- Sections ---------------------------------------------------
        echo "<div class='mb-3'><label class='form-label fw-bold'>"
            . "<i class='fas fa-layer-group me-1'></i>" . __('Sections', 'sprint') . "</label>";
        echo "<div class='d-flex flex-wrap gap-3'>";
        foreach (self::getCsvSections() as $key => $label) {
            $checked = in_array($key, self::CSV_DEFAULT_SECTIONS, true) ? ' checked' : '';
            echo "<label class='form-check' style='display:inline-flex;align-items:center;gap:6px;'>"
                . "<input type='checkbox' class='form-check-input sprint-csv-section' value='" . htmlescape($key) . "'{$checked}>"
                . "<span class='form-check-label'>" . htmlescape($label) . "</span></label>";
        }
        echo "</div></div>";

        // --- Item columns -----------------------------------------------
        echo "<div class='mb-3 sprint-csv-columns-block'><label class='form-label fw-bold'>"
            . "<i class='fas fa-table-columns me-1'></i>" . __('Columns for the item rows', 'sprint')
            . " <button type='button' class='btn btn-sm btn-link p-0 ms-2 sprint-csv-toggle-all'>"
            . htmlescape(__('Select all / none', 'sprint')) . "</button></label>";
        echo "<div class='d-flex flex-wrap gap-3'>";
        foreach (self::getCsvColumns() as $key => $label) {
            $checked = in_array($key, self::CSV_DEFAULT_COLUMNS, true) ? ' checked' : '';
            echo "<label class='form-check' style='display:inline-flex;align-items:center;gap:6px;'>"
                . "<input type='checkbox' class='form-check-input sprint-csv-col' value='" . htmlescape($key) . "'{$checked}>"
                . "<span class='form-check-label'>" . htmlescape($label) . "</span></label>";
        }
        echo "</div>";
        echo "<div class='form-text sprint-small text-muted'>"
            . htmlescape(__('Planned is the estimate the item was taken in with; realised is the actual figure, which falls back to the planned one where nothing was recorded.', 'sprint'))
            . "</div></div>";

        // --- Category filter --------------------------------------------
        echo "<div class='mb-3'><label class='form-label fw-bold'>"
            . "<i class='fas fa-folder me-1'></i>" . __('Categories', 'sprint')
            . " <button type='button' class='btn btn-sm btn-link p-0 ms-2 sprint-csv-toggle-cats'>"
            . htmlescape(__('Select all / none', 'sprint')) . "</button></label>";
        echo "<div class='d-flex flex-wrap gap-3'>";
        foreach ($categories as $cid => $cat) {
            $indent = (int)($cat['level'] ?? 0) > 0 ? 'margin-left:18px;' : '';
            echo "<label class='form-check' style='display:inline-flex;align-items:center;gap:6px;{$indent}'>"
                . "<input type='checkbox' class='form-check-input sprint-csv-cat' value='" . (int)$cid . "' checked>"
                . "<span class='form-check-label'>" . htmlescape((string)$cat['name']) . "</span></label>";
        }
        echo "<label class='form-check' style='display:inline-flex;align-items:center;gap:6px;'>"
            . "<input type='checkbox' class='form-check-input sprint-csv-cat' value='0' checked>"
            . "<span class='form-check-label fst-italic'>" . htmlescape(__('No category', 'sprint')) . "</span></label>";
        echo "</div>";
        echo "<div class='form-check form-switch mt-2'>";
        echo "<input class='form-check-input' type='checkbox' id='sprint-csv-split'>";
        echo "<label class='form-check-label' for='sprint-csv-split'>"
            . htmlescape(__('Split the item rows into one block per category, each with its own subtotal', 'sprint'))
            . "</label>";
        echo "</div></div>";

        echo "<div class='alert alert-warning py-2 sprint-small sprint-csv-error' style='display:none;'></div>";
        echo "</div>"; // modal-body

        echo "<div class='modal-footer'>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . __('Cancel') . "</button>";
        echo "<button type='button' class='btn btn-success sprint-csv-download'>"
            . "<i class='fas fa-download me-1'></i>" . __('Download CSV', 'sprint') . "</button>";
        echo "</div>";
        echo "</div></div></div>";

        $errNothing = addslashes(__('Pick at least one section and one column.', 'sprint'));
        $errNoCats  = addslashes(__('Pick at least one category.', 'sprint'));
        $baseUrlJs  = addslashes($baseUrl);

        echo <<<HTML
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }

    // Move the dialog to <body> so it stacks above the tab container; a GLPI
    // ajax-tab reload re-echoes it, so drop any stale copy from an earlier
    // load first.
    jQuery(function(){
        var all = jQuery('#sprint-csv-modal, body > #sprint-csv-modal');
        if (all.length > 1) { all.slice(0, all.length - 1).remove(); }
        var last = jQuery('#sprint-csv-modal').last();
        if (last.length && !last.parent().is('body')) { last.detach().appendTo('body'); }
    });

    if (window.__sprintCsvDialogBound) { return; }
    window.__sprintCsvDialogBound = true;

    function modal() { return jQuery('#sprint-csv-modal').last(); }

    jQuery(document).on('click', '.sprint-csv-open', function(){
        var \$m = modal();
        if (!\$m.length) { return; }
        \$m.find('.sprint-csv-error').hide().text('');
        bootstrap.Modal.getOrCreateInstance(\$m[0]).show();
    });

    // Both "select all / none" links flip to whatever the majority is not,
    // so a half-ticked list resolves to "all" on the first click.
    function toggleGroup(\$boxes) {
        var allOn = \$boxes.length > 0 && \$boxes.filter(':checked').length === \$boxes.length;
        \$boxes.prop('checked', !allOn);
    }
    jQuery(document).on('click', '.sprint-csv-toggle-all', function(){
        toggleGroup(modal().find('.sprint-csv-col'));
    });
    jQuery(document).on('click', '.sprint-csv-toggle-cats', function(){
        toggleGroup(modal().find('.sprint-csv-cat'));
    });

    jQuery(document).on('click', '.sprint-csv-download', function(){
        var \$m = modal();
        var params = [];
        var cols = [], sections = [], cats = [];
        \$m.find('.sprint-csv-section:checked').each(function(){ sections.push(this.value); });
        \$m.find('.sprint-csv-col:checked').each(function(){ cols.push(this.value); });
        \$m.find('.sprint-csv-cat:checked').each(function(){ cats.push(this.value); });

        var wantsItems = sections.indexOf('items') !== -1;
        if (!sections.length || (wantsItems && !cols.length)) {
            \$m.find('.sprint-csv-error').text('{$errNothing}').show();
            return;
        }
        if (!cats.length) {
            \$m.find('.sprint-csv-error').text('{$errNoCats}').show();
            return;
        }

        params.push('format=csv');
        sections.forEach(function(v){ params.push('sections[]=' + encodeURIComponent(v)); });
        cols.forEach(function(v){ params.push('cols[]=' + encodeURIComponent(v)); });
        // Every category ticked means "no filter" — leave the parameter out so
        // items carrying a category deleted since are not silently dropped.
        if (cats.length !== \$m.find('.sprint-csv-cat').length) {
            cats.forEach(function(v){ params.push('cats[]=' + encodeURIComponent(v)); });
        }
        if (\$m.find('#sprint-csv-split').is(':checked')) { params.push('split=1'); }

        \$m.find('.sprint-csv-error').hide().text('');
        bootstrap.Modal.getOrCreateInstance(\$m[0]).hide();
        window.location.href = '{$baseUrlJs}&' + params.join('&');
    });
})();
</script>
HTML;
    }

    /**
     * Every column the item section can carry, in the order they are written.
     * Keys are the values submitted by the export dialog.
     *
     * @return array<string,string> key => header label
     */
    public static function getCsvColumns(): array
    {
        return [
            'name'             => __('Name'),
            'type'             => __('Type'),
            'category'         => __('Category', 'sprint'),
            'fastlane'         => __('Fastlane', 'sprint'),
            'adhoc'            => __('Adhoc', 'sprint'),
            'owner'            => __('Owner', 'sprint'),
            'status'           => __('Status'),
            'priority'         => __('Priority'),
            'story_points'     => __('Story Points', 'sprint'),
            'capacity_planned' => __('Planned capacity', 'sprint') . ' %',
            'capacity_actual'  => __('Realised capacity', 'sprint') . ' %',
            'tags'             => __('Tags', 'sprint'),
            'linked'           => __('Linked item', 'sprint'),
            'note'             => __('Note', 'sprint'),
        ];
    }

    /** Columns pre-ticked in the export dialog (the pre-1.2.3 fixed layout). */
    public const CSV_DEFAULT_COLUMNS = [
        'name', 'type', 'category', 'fastlane', 'owner', 'status',
        'story_points', 'capacity_planned', 'tags', 'linked',
    ];

    /**
     * Blocks the CSV can contain, each written as its own titled section.
     *
     * @return array<string,string> key => label
     */
    public static function getCsvSections(): array
    {
        return [
            'items'        => __('Sprint items', 'sprint'),
            'categories'   => __('Totals per category', 'sprint'),
            'members'      => __('Workload per member', 'sprint'),
            'dependencies' => __('Dependencies', 'sprint'),
        ];
    }

    /** Sections pre-ticked in the export dialog. */
    public const CSV_DEFAULT_SECTIONS = ['items', 'dependencies'];

    /**
     * Turn raw request parameters into a validated export configuration.
     * Anything unknown is dropped and anything empty falls back to the
     * defaults, so a hand-written URL can never produce an empty file.
     *
     * @param array<string,mixed> $params usually $_GET
     * @return array{columns:string[],sections:string[],categories:int[]|null,split:bool}
     */
    public static function parseCsvOptions(array $params): array
    {
        // array_intersect against the registry keys keeps the canonical order,
        // so the column sequence never depends on checkbox click order.
        $columns = array_values(array_intersect(
            array_keys(self::getCsvColumns()),
            array_map('strval', (array)($params['cols'] ?? []))
        ));
        $sections = array_values(array_intersect(
            array_keys(self::getCsvSections()),
            array_map('strval', (array)($params['sections'] ?? []))
        ));

        // No category filter at all (absent or "everything ticked") stays null
        // so the query is not constrained; 0 is a real value ("no category").
        $categories = null;
        if (isset($params['cats']) && is_array($params['cats']) && count($params['cats']) > 0) {
            $categories = array_values(array_unique(array_map('intval', $params['cats'])));
        }

        return [
            'columns'    => $columns ?: self::CSV_DEFAULT_COLUMNS,
            'sections'   => $sections ?: self::CSV_DEFAULT_SECTIONS,
            'categories' => $categories,
            'split'      => (int)($params['split'] ?? 0) === 1,
        ];
    }

    /**
     * Stream a sprint report as a CSV download. The dialog in render() decides
     * which sections and which item columns are written, which categories are
     * included and whether the item rows are split into one block per category.
     *
     * Caller must NOT have emitted any HTML/headers yet (see
     * front/sprint.export.php, which branches on ?format=csv before Html::header()).
     *
     * @param array<string,mixed> $options raw request params, see parseCsvOptions()
     */
    public static function streamCsv(Sprint $sprint, array $options = []): void
    {
        $sprintId = (int)$sprint->getID();
        $opt      = self::parseCsvOptions($options);

        $si    = new SprintItem();
        $items = $si->find(
            ['plugin_sprint_sprints_id' => $sprintId],
            ['is_fastlane DESC', 'priority DESC', 'sort_order ASC']
        );

        if ($opt['categories'] !== null) {
            $wanted = array_flip($opt['categories']);
            $items  = array_filter(
                $items,
                static fn($r) => isset($wanted[(int)($r['plugin_sprint_sprintcategories_id'] ?? 0)])
            );
        }

        $itemIds  = array_map(static fn($r) => (int)$r['id'], $items);
        $tagsById = SprintItem::getTagsForItems($itemIds);

        // Sanitised filename: sprint-<name>-<timestamp>.csv
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($sprint->fields['name'] ?? 'sprint'));
        $slug = trim((string)$slug, '-');
        if ($slug === '') {
            $slug = 'sprint';
        }
        $filename = 'sprint-' . $slug . '-' . date('Ymd-His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel reads accented characters correctly.
        fwrite($out, "\xEF\xBB\xBF");

        $first = true;
        foreach ($opt['sections'] as $section) {
            if (!$first) {
                self::csvRow($out, ['']);
            }
            $first = false;
            switch ($section) {
                case 'items':
                    self::writeCsvItems($out, $items, $tagsById, $opt);
                    break;
                case 'categories':
                    self::writeCsvCategoryTotals($out, $items);
                    break;
                case 'members':
                    self::writeCsvMembers($out, $sprintId);
                    break;
                case 'dependencies':
                    self::writeCsvDependencies($out, $items, $itemIds);
                    break;
            }
        }

        fclose($out);
    }

    /**
     * One CSV line. Explicit escape arg ('') keeps output RFC-compliant and
     * silences the PHP 8.4+ escape-default deprecation.
     *
     * @param resource $out
     */
    private static function csvRow($out, array $fields): void
    {
        fputcsv($out, $fields, ',', '"', '');
    }

    /**
     * Item rows, optionally split into one titled block per category so a
     * single export can still be sliced per category in a spreadsheet.
     *
     * @param resource $out
     */
    private static function writeCsvItems($out, array $items, array $tagsById, array $opt): void
    {
        $allColumns = self::getCsvColumns();
        $header     = array_map(static fn($c) => $allColumns[$c], $opt['columns']);

        self::csvRow($out, [__('Sprint items', 'sprint')]);

        if (!$opt['split']) {
            self::csvRow($out, $header);
            foreach ($items as $row) {
                self::csvRow($out, self::csvItemFields($row, $tagsById, $opt['columns']));
            }
            return;
        }

        // Group by category, keeping the categories in their configured tree
        // order and "no category" last.
        $grouped = [];
        foreach ($items as $row) {
            $grouped[(int)($row['plugin_sprint_sprintcategories_id'] ?? 0)][] = $row;
        }
        $order = array_keys(SprintCategory::getAll(false));
        $order[] = 0;
        foreach (array_keys($grouped) as $cid) {
            if (!in_array($cid, $order, true)) {
                $order[] = $cid;
            }
        }

        foreach ($order as $cid) {
            if (empty($grouped[$cid])) {
                continue;
            }
            $label = $cid > 0 ? SprintCategory::getFullNameFor($cid) : __('No category', 'sprint');
            if ($label === '') {
                $label = '#' . $cid;
            }
            self::csvRow($out, ['']);
            self::csvRow($out, [__('Category', 'sprint') . ': ' . $label]);
            self::csvRow($out, $header);
            $plannedTotal = 0.0;
            $actualTotal  = 0.0;
            $pointsTotal  = 0;
            foreach ($grouped[$cid] as $row) {
                self::csvRow($out, self::csvItemFields($row, $tagsById, $opt['columns']));
                $plannedTotal += self::csvItemCapacity($row, false);
                $actualTotal  += self::csvItemCapacity($row, true);
                $pointsTotal  += (int)$row['story_points'];
            }
            // Subtotal line mirroring the selected columns, so it lines up
            // underneath the figures it sums.
            $subtotal = [];
            foreach ($opt['columns'] as $col) {
                switch ($col) {
                    case 'name':             $subtotal[] = __('Subtotal', 'sprint'); break;
                    case 'story_points':     $subtotal[] = $pointsTotal; break;
                    case 'capacity_planned': $subtotal[] = SprintMember::formatCapacity($plannedTotal); break;
                    case 'capacity_actual':  $subtotal[] = SprintMember::formatCapacity($actualTotal); break;
                    default:                 $subtotal[] = ''; break;
                }
            }
            self::csvRow($out, $subtotal);
        }
    }

    /**
     * Allocated capacity for one item. Fastlane items have no single figure of
     * their own — their capacity is the sum of the member allocations, which
     * carry no separate realised value, so planned and realised match there.
     */
    private static function csvItemCapacity(array $row, bool $actual): float
    {
        if ((int)($row['is_fastlane'] ?? 0) === 1) {
            $total = 0.0;
            foreach ((new SprintFastlaneMember())->find([
                'plugin_sprint_sprintitems_id' => (int)$row['id'],
            ]) as $alloc) {
                $total += (float)$alloc['capacity'];
            }
            return $total;
        }
        return SprintItem::capacityFor($row, $actual);
    }

    /**
     * One item as the selected columns, in registry order.
     *
     * @return array<int,string|int>
     */
    private static function csvItemFields(array $row, array $tagsById, array $columns): array
    {
        static $statuses   = null;
        static $typeLabels = null;
        static $priorities = null;
        if ($statuses === null) {
            $statuses   = SprintItem::getAllStatuses();
            $typeLabels = [
                ''            => __('Manual', 'sprint'),
                'Ticket'      => __('Ticket'),
                'Change'      => __('Change'),
                'Problem'     => __('Problem'),
                'ProjectTask' => __('Project task'),
            ];
            $priorities = [
                1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
                4 => __('High'), 5 => __('Very high'),
            ];
        }

        $itemId   = (int)$row['id'];
        $isFast   = (int)($row['is_fastlane'] ?? 0) === 1;
        $catId    = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
        $itemtype = (string)($row['itemtype'] ?? '');

        $fields = [];
        foreach ($columns as $col) {
            switch ($col) {
                case 'name':
                    $fields[] = (string)$row['name'];
                    break;
                case 'type':
                    $fields[] = (string)($typeLabels[$itemtype] ?? $itemtype);
                    break;
                case 'category':
                    $fields[] = $catId > 0
                        ? SprintCategory::getFullNameFor($catId)
                        : __('No category', 'sprint');
                    break;
                case 'fastlane':
                    $fields[] = $isFast ? __('Yes') : __('No');
                    break;
                case 'adhoc':
                    $fields[] = (int)($row['is_adhoc'] ?? 0) === 1 ? __('Yes') : __('No');
                    break;
                case 'owner':
                    $fields[] = self::csvItemOwner($row);
                    break;
                case 'status':
                    $fields[] = (string)($statuses[$row['status']] ?? $row['status']);
                    break;
                case 'priority':
                    $fields[] = (string)($priorities[(int)($row['priority'] ?? 3)] ?? $row['priority']);
                    break;
                case 'story_points':
                    $fields[] = (int)$row['story_points'];
                    break;
                case 'capacity_planned':
                    $fields[] = SprintMember::formatCapacity(self::csvItemCapacity($row, false));
                    break;
                case 'capacity_actual':
                    $fields[] = SprintMember::formatCapacity(self::csvItemCapacity($row, true));
                    break;
                case 'tags':
                    $fields[] = implode('; ', $tagsById[$itemId] ?? []);
                    break;
                case 'linked':
                    $linked   = SprintCache::getObject($itemtype, (int)($row['items_id'] ?? 0));
                    $fields[] = $linked !== null ? (string)($linked->fields['name'] ?? '') : '';
                    break;
                case 'note':
                    $fields[] = (string)($row['note'] ?? '');
                    break;
                default:
                    $fields[] = '';
                    break;
            }
        }
        return $fields;
    }

    /**
     * Owner cell: fastlane items list every member with their share, regular
     * items their single owner.
     */
    private static function csvItemOwner(array $row): string
    {
        if ((int)($row['is_fastlane'] ?? 0) !== 1) {
            return ((int)$row['users_id'] > 0)
                ? SprintCache::userName((int)$row['users_id'])
                : __('Unassigned', 'sprint');
        }
        $names = [];
        foreach ((new SprintFastlaneMember())->find([
            'plugin_sprint_sprintitems_id' => (int)$row['id'],
        ]) as $alloc) {
            $names[] = SprintCache::userName((int)$alloc['users_id'])
                . ' (' . SprintMember::formatCapacity($alloc['capacity']) . '%)';
        }
        if ($names) {
            return implode(', ', $names);
        }
        return ((int)$row['users_id'] > 0)
            ? SprintCache::userName((int)$row['users_id'])
            : __('Unassigned', 'sprint');
    }

    /**
     * Per-category totals over the exported items, so the category split can be
     * read without pivoting the item rows.
     *
     * @param resource $out
     */
    private static function writeCsvCategoryTotals($out, array $items): void
    {
        $totals = [];
        foreach ($items as $row) {
            $cid = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
            $totals[$cid] ??= [
                'items' => 0, 'done' => 0, 'points' => 0, 'points_done' => 0,
                'planned' => 0.0, 'actual' => 0.0,
            ];
            $done = (string)$row['status'] === SprintItem::STATUS_DONE;
            $totals[$cid]['items']++;
            $totals[$cid]['points']  += (int)$row['story_points'];
            $totals[$cid]['planned'] += self::csvItemCapacity($row, false);
            $totals[$cid]['actual']  += self::csvItemCapacity($row, true);
            if ($done) {
                $totals[$cid]['done']++;
                $totals[$cid]['points_done'] += (int)$row['story_points'];
            }
        }

        self::csvRow($out, [__('Totals per category', 'sprint')]);
        self::csvRow($out, [
            __('Category', 'sprint'),
            __('Items', 'sprint'),
            __('Done', 'sprint'),
            __('Story Points', 'sprint'),
            __('Story points done', 'sprint'),
            __('Planned capacity', 'sprint') . ' %',
            __('Realised capacity', 'sprint') . ' %',
        ]);

        if (count($totals) === 0) {
            return;
        }

        // Configured tree order first, "no category" last, unknown ids appended.
        $order = array_keys(SprintCategory::getAll(false));
        $order[] = 0;
        foreach (array_keys($totals) as $cid) {
            if (!in_array($cid, $order, true)) {
                $order[] = $cid;
            }
        }
        foreach ($order as $cid) {
            if (!isset($totals[$cid])) {
                continue;
            }
            $t     = $totals[$cid];
            $label = $cid > 0 ? SprintCategory::getFullNameFor($cid) : __('No category', 'sprint');
            self::csvRow($out, [
                $label !== '' ? $label : ('#' . $cid),
                $t['items'],
                $t['done'],
                $t['points'],
                $t['points_done'],
                SprintMember::formatCapacity($t['planned']),
                SprintMember::formatCapacity($t['actual']),
            ]);
        }
    }

    /**
     * Capacity per sprint member: what they can take, what is planned on them
     * and what it really took. Mirrors the workload table in the HTML report.
     *
     * @param resource $out
     */
    private static function writeCsvMembers($out, int $sprintId): void
    {
        self::csvRow($out, [__('Workload per member', 'sprint')]);
        self::csvRow($out, [
            __('Member', 'sprint'),
            __('Role', 'sprint'),
            __('Capacity', 'sprint') . ' %',
            __('Planned capacity', 'sprint') . ' %',
            __('Realised capacity', 'sprint') . ' %',
            __('Fastlane', 'sprint') . ' %',
            __('Used', 'sprint') . ' %',
            __('Free', 'sprint') . ' %',
        ]);

        $roles = SprintMember::getAllRoles();
        $si    = new SprintItem();

        foreach ((new SprintMember())->find(['plugin_sprint_sprints_id' => $sprintId], ['role ASC']) as $row) {
            $userId = (int)$row['users_id'];
            // Effective capacity (availability exceptions applied), matching
            // the dashboard and the HTML report.
            $totalCap = SprintAgility::effectiveCapacity(
                $sprintId,
                $userId,
                SprintMember::normalizeCapacity($row['capacity_percent'])
            );

            $planned = 0.0;
            $actual  = 0.0;
            foreach ($si->find([
                'plugin_sprint_sprints_id' => $sprintId,
                'users_id'                 => $userId,
                'is_fastlane'              => 0,
            ]) as $r) {
                $planned += SprintItem::capacityFor($r, false);
                $actual  += SprintItem::capacityFor($r, true);
            }
            $fastlane = SprintFastlaneMember::getUsedFastlaneCapacityForUser($sprintId, $userId);
            $used     = $planned + $fastlane;

            self::csvRow($out, [
                SprintCache::userName($userId),
                (string)($roles[$row['role']] ?? $row['role']),
                SprintMember::formatCapacity($totalCap),
                SprintMember::formatCapacity($planned),
                SprintMember::formatCapacity($actual),
                SprintMember::formatCapacity($fastlane),
                SprintMember::formatCapacity($used),
                SprintMember::formatCapacity(max($totalCap - $used, 0)),
            ]);
        }
    }

    /**
     * One row per helper, so capacity and resolution state stay analysable in
     * a spreadsheet. Limited to the exported items.
     *
     * @param resource $out
     */
    private static function writeCsvDependencies($out, array $items, array $itemIds): void
    {
        self::csvRow($out, [__('Dependencies', 'sprint')]);
        self::csvRow($out, [
            __('Name'),
            __('Category', 'sprint'),
            __('Owner', 'sprint'),
            __('Helper', 'sprint'),
            __('Capacity', 'sprint') . ' %',
            __('Resolved', 'sprint'),
            __('Comments'),
            __('Creation date'),
        ]);

        $depRows = (SprintItemDependency::isTableReady() && count($itemIds) > 0)
            ? (new SprintItemDependency())->find(['plugin_sprint_sprintitems_id' => $itemIds], ['date_creation ASC'])
            : [];

        foreach ($depRows as $r) {
            $parent = $items[(int)$r['plugin_sprint_sprintitems_id']] ?? null;
            if ($parent === null) {
                continue;
            }
            $catId = (int)($parent['plugin_sprint_sprintcategories_id'] ?? 0);
            self::csvRow($out, [
                (string)$parent['name'],
                $catId > 0 ? SprintCategory::getFullNameFor($catId) : __('No category', 'sprint'),
                ((int)$parent['users_id'] > 0) ? SprintCache::userName((int)$parent['users_id']) : __('Unassigned', 'sprint'),
                SprintCache::userName((int)$r['users_id']),
                SprintMember::formatCapacity($r['capacity']),
                ((int)($r['is_resolved'] ?? 0) === 1) ? __('Yes') : __('No'),
                (string)($r['comment'] ?? ''),
                (string)($r['date_creation'] ?? ''),
            ]);
        }
    }

    private static function renderPrintStyles(): void
    {
        // PDF output must be uniform across users — overrides win against
        // the active GLPI theme without unloading its stylesheet.
        echo "<style>
        @media print {
            :root, html, body, [data-bs-theme], [data-bs-theme=\"dark\"], [data-theme=\"dark\"] {
                color-scheme: light !important;
                --tblr-body-bg: #ffffff !important;
                --tblr-body-color: #212529 !important;
                --tblr-body-bg-rgb: 255,255,255 !important;
                --tblr-body-color-rgb: 33,37,41 !important;
                --tblr-bg-surface: #ffffff !important;
                --tblr-bg-surface-secondary: #ffffff !important;
                --tblr-bg-surface-tertiary: #ffffff !important;
                --tblr-card-bg: #ffffff !important;
                --tblr-card-color: #212529 !important;
                --tblr-border-color: #dee2e6 !important;
                --tblr-border-color-translucent: rgba(0,0,0,.10) !important;
                --tblr-light: #f5f7fb !important;
                --tblr-dark: #1d273b !important;
                --tblr-secondary: #6c757d !important;
                --tblr-muted: #6c757d !important;
            }
            #header, #header-logo, #c_menu, #c_breadcrumb, #c_ssotabs, .c_main_left,
            .navigationheader, .footer, .menu_navigate, #c_footer, #toolbar,
            #navigation-header, .navigation-header, .navigation-header-wrapper,
            .app-sidebar, .app-aside, .header-nav, .scrollable-logo,
            .navbar, .breadcrumb, .breadcrumbs, aside,
            #notifications, .notifications, .notifications-link, .notifications-bell,
            .notification-badge, .header-notifications, .navbar-notifications,
            #see_all_notifications, [data-bs-target=\"#notifications\"],
            [aria-label*=\"notification\" i], [title*=\"notification\" i],
            .nav-tabs, .nav.nav-tabs, ul.nav-tabs, .nav-tabs-container,
            .tab-content > .nav, .glpi-form-tabs, .tabs-bg,
            .sprint-export-toolbar, .alert.alert-info, .sprint-csv-modal { display: none !important; }
            body, .container, .container-fluid, #page, #page > .container-fluid,
            main, .main-content, .tab-content, .tab-pane, .card, .card-body {
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                color: #212529 !important;
                box-shadow: none !important;
                border: 0 !important;
            }
            .sprint-export-page {
                max-width: none !important;
                margin: 0 !important;
                padding: 8mm !important;
                box-shadow: none !important;
                background-color: #ffffff !important;
                color: #212529 !important;
            }
            h1, h2 { page-break-after: avoid; }
            table, tr { page-break-inside: avoid; }
            a { color: inherit !important; text-decoration: none !important; }
        }
        @page { margin: 12mm; }
        .sprint-export-page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 18px 24px;
            background: #fff;
        }
        </style>";
    }
}
