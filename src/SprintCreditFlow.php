<?php

namespace GlpiPlugin\Sprint;

/**
 * Credit flow per customer per sprint: where a customer's credits went.
 *
 * Two views per customer, one row per sprint in the window:
 *  - by catalogue folder: the credits split over the top-level folders of the
 *    catalogue (a top-level product counts as its own folder, credits entered
 *    by hand as "Manual credits");
 *  - tags × categories: per tag a 100 %-stacked bar over the backlog
 *    categories, so the ratio of what each tag picked up is visible next to
 *    the other tags of the same sprint.
 *
 * The figures follow the balances: open and delivered work in the sprint
 * counts, parked open work does not (SprintCustomer::bucketFor()). Credits on
 * a dependency are charged to the parent item's customer and carry the
 * parent's tags and category; the folder comes from the dependency's own
 * product, or the parent's when the helper picked none.
 */
final class SprintCreditFlow
{
    /** Folder colours, by tree order of the top-level catalogue entries. */
    const PALETTE = [
        '#0d6efd', '#6f42c1', '#20c997', '#fd7e14', '#d63384', '#0dcaf0',
        '#198754', '#ffc107', '#6610f2', '#e83e8c', '#17a2b8', '#8b5cf6',
    ];

    /** Grey for "no folder" / "no category" / "no tag". */
    const NEUTRAL = '#6c757d';

    /** Key for items whose credits were entered by hand. */
    const FOLDER_MANUAL = 0;

    /** Key for items without a tag. */
    const TAG_NONE = '';

    // =========================================================================
    // Data
    // =========================================================================

    /**
     * @param int[] $sprintIds
     * @return array<int,array<int,array{total:float,folders:array<int,float>,tags:array<string,array<int,float>>}>> [customer][sprint]
     */
    public static function collect(array $sprintIds): array
    {
        global $DB;

        $sprintIds = array_values(array_unique(array_filter(array_map('intval', $sprintIds))));
        if (empty($sprintIds) || !SprintCustomer::canViewCredits()) {
            return [];
        }
        $keep = array_flip($sprintIds);

        $rows = [];
        foreach ($DB->request([
            'SELECT' => [
                'id', 'plugin_sprint_sprintcustomers_id', 'plugin_sprint_sprints_id', 'status', 'is_parked',
                'credits', 'plugin_sprint_sprintcreditproducts_id', 'plugin_sprint_sprintcategories_id',
            ],
            'FROM'   => SprintItem::getTable(),
            'WHERE'  => [
                'plugin_sprint_sprints_id' => $sprintIds,
                'NOT'                      => ['credits' => 0],
            ],
        ]) as $row) {
            $rows[] = $row;
        }
        foreach (SprintItemDependency::creditRows() as $row) {
            if (isset($keep[(int)$row['plugin_sprint_sprints_id']])) {
                $rows[] = $row;
            }
        }

        $itemIds  = array_values(array_unique(array_map(static fn($r) => (int)$r['id'], $rows)));
        $tagsById = SprintItem::getTagsForItems($itemIds);

        $out = [];
        foreach ($rows as $row) {
            if (SprintCustomer::bucketFor($row) === null) {
                continue;
            }
            $cid     = (int)$row['plugin_sprint_sprintcustomers_id'];
            $sid     = (int)$row['plugin_sprint_sprints_id'];
            $credits = (float)$row['credits'];
            $folder  = self::rootFolderOf((int)($row['plugin_sprint_sprintcreditproducts_id'] ?? 0));
            $catId   = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
            $tags    = $tagsById[(int)$row['id']] ?? [];
            if (empty($tags)) {
                $tags = [self::TAG_NONE];
            }

            $out[$cid][$sid] ??= ['total' => 0.0, 'folders' => [], 'tags' => []];
            $cell = &$out[$cid][$sid];
            $cell['total'] += $credits;
            $cell['folders'][$folder] = ($cell['folders'][$folder] ?? 0.0) + $credits;
            // A tag bar is normalised on its own, so an item with several tags
            // is counted in full on each of them.
            foreach ($tags as $tag) {
                $cell['tags'][$tag][$catId] = ($cell['tags'][$tag][$catId] ?? 0.0) + $credits;
            }
            unset($cell);
        }
        ksort($out);
        return $out;
    }

    /** The top-level catalogue entry above $productId (itself when top-level), 0 for none. */
    public static function rootFolderOf(int $productId): int
    {
        $all   = SprintCreditProduct::getAll(false);
        $guard = 0;
        while ($productId > 0 && isset($all[$productId]) && $guard++ < 20) {
            $parent = (int)($all[$productId]['sprintcreditproducts_id'] ?? 0);
            if ($parent <= 0 || !isset($all[$parent])) {
                return $productId;
            }
            $productId = $parent;
        }
        return self::FOLDER_MANUAL;
    }

    /** Colour per top-level catalogue entry, stable across customers. */
    public static function folderColor(int $folderId): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            $i   = 0;
            foreach (SprintCreditProduct::getAll(false) as $id => $row) {
                if ((int)($row['level'] ?? 0) === 0) {
                    $map[(int)$id] = self::PALETTE[$i % count(self::PALETTE)];
                    $i++;
                }
            }
        }
        return $map[$folderId] ?? self::NEUTRAL;
    }

    public static function folderLabel(int $folderId): string
    {
        if ($folderId <= 0) {
            return __('Manual credits', 'sprint');
        }
        $name = SprintCreditProduct::getNameFor($folderId);
        return $name !== '' ? $name : '#' . $folderId;
    }

    public static function categoryLabel(int $catId): string
    {
        if ($catId <= 0) {
            return __('No category', 'sprint');
        }
        $name = SprintCategory::getFullNameFor($catId);
        return $name !== '' ? $name : '#' . $catId;
    }

    public static function tagLabel(string $tag): string
    {
        return $tag === self::TAG_NONE ? __('No tag', 'sprint') : $tag;
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    /**
     * The card: a customer picker and, per customer, both views.
     *
     * @param array<int,array<string,mixed>> $window   sprints, oldest first
     * @param array<int,array<string,mixed>> $balances SprintCustomer::balances(false)
     */
    public static function render(array $window, array $balances): void
    {
        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-1'>";
        echo "<h3 class='card-title mb-0'><i class='fas fa-diagram-project me-2'></i>"
            . __('Credit flow per customer per sprint', 'sprint') . "</h3>";
        echo "<span style='flex:1;'></span>";

        $flow = self::collect(array_map(static fn($s) => (int)$s['id'], $window));
        if (!$window || !$flow) {
            echo "</div>";
            echo "<div class='text-muted sprint-small mb-2'>"
                . htmlescape(__('Per customer: which catalogue folders their credits went to, and per tag which categories that tag picked up, sprint by sprint.', 'sprint'))
                . "</div>";
            echo "<div class='text-muted'>" . __('No credits assigned to a sprint yet.', 'sprint') . "</div>";
            echo "</div></div>";
            return;
        }

        $customerIds = array_keys($flow);
        echo "<select class='form-select form-select-sm sprint-creditflow-pick' style='max-width:260px;'>";
        foreach ($customerIds as $i => $cid) {
            echo "<option value='" . (int)$cid . "'" . ($i === 0 ? ' selected' : '') . ">"
                . htmlescape(self::customerLabel((int)$cid, $balances)) . "</option>";
        }
        echo "</select>";
        echo "</div>";
        echo "<div class='text-muted sprint-small mb-3'>"
            . htmlescape(__('Per customer: which catalogue folders their credits went to, and per tag which categories that tag picked up, sprint by sprint. Open and delivered work in the sprint counts; a dependency\'s credits follow the parent item.', 'sprint'))
            . "</div>";

        foreach ($customerIds as $i => $cid) {
            echo "<div class='sprint-creditflow-customer' data-customer='" . (int)$cid . "'" . ($i === 0 ? '' : ' hidden') . ">";
            self::renderFolderView($window, $flow[$cid]);
            self::renderTagView($window, $flow[$cid]);
            echo "</div>";
        }

        self::renderScript();
        echo "</div></div>";
    }

    /** Stacked column per sprint, one segment per top-level catalogue folder. */
    private static function renderFolderView(array $window, array $bySprint): void
    {
        $folders = [];
        $columns = [];
        foreach ($bySprint as $sid => $cell) {
            foreach ($cell['folders'] as $fid => $v) {
                $folders[(int)$fid]        = ($folders[(int)$fid] ?? 0.0) + (float)$v;
                $columns[(int)$sid][(int)$fid] = (float)$v;
            }
        }
        // Folders in catalogue order, manual credits last.
        $series = [];
        foreach (SprintCreditProduct::getAll(false) as $id => $row) {
            if (isset($folders[(int)$id])) {
                $series[(int)$id] = ['label' => self::folderLabel((int)$id), 'color' => self::folderColor((int)$id), 'total' => $folders[(int)$id]];
            }
        }
        if (isset($folders[self::FOLDER_MANUAL])) {
            $series[self::FOLDER_MANUAL] = ['label' => self::folderLabel(self::FOLDER_MANUAL), 'color' => self::NEUTRAL, 'total' => $folders[self::FOLDER_MANUAL]];
        }

        echo "<div class='sprint-creditflow-block'>";
        echo "<div class='sprint-creditflow-title'><i class='fas fa-folder-tree me-1'></i>"
            . __('Credits per catalogue folder', 'sprint') . "</div>";
        echo "<div class='text-muted sprint-small mb-2'>"
            . htmlescape(__('Credits the customer\'s items claimed per sprint, split over the top-level catalogue folders. Click a folder in the legend to isolate it.', 'sprint'))
            . "</div>";
        self::renderColumnChart($window, $columns, $series, false);
        echo "</div>";
    }

    /**
     * Stacked column per sprint, one segment per tag, and below it the
     * category split of every tag for the sprint picked in the chart.
     */
    private static function renderTagView(array $window, array $bySprint): void
    {
        $tagOrder = [];
        $catSeen  = [];
        $tagTotal = [];
        $columns  = [];
        foreach (Config::getDefinedTags() as $tag) {
            $tagOrder[$tag] = true;
        }
        foreach ($bySprint as $sid => $cell) {
            foreach ($cell['tags'] as $tag => $byCat) {
                $tagOrder[$tag] = true;
                $sum = array_sum($byCat);
                $tagTotal[$tag] = ($tagTotal[$tag] ?? 0.0) + $sum;
                $columns[(int)$sid][$tag] = $sum;
                foreach ($byCat as $catId => $v) {
                    $catSeen[(int)$catId] = true;
                }
            }
        }
        $series = [];
        foreach (array_keys($tagOrder) as $tag) {
            $tag = (string)$tag;
            if (!isset($tagTotal[$tag])) {
                continue;
            }
            $series[$tag] = ['label' => self::tagLabel($tag), 'color' => self::tagColor($tag), 'total' => $tagTotal[$tag]];
        }
        // "No tag" always sits on top of the stack.
        if (isset($series[self::TAG_NONE])) {
            $none = $series[self::TAG_NONE];
            unset($series[self::TAG_NONE]);
            $series[self::TAG_NONE] = $none;
        }
        // Categories in tree order, "no category" last.
        $catOrder = [];
        foreach (SprintCategory::getAll(false) as $id => $row) {
            if (isset($catSeen[(int)$id])) {
                $catOrder[] = (int)$id;
            }
        }
        if (isset($catSeen[0])) {
            $catOrder[] = 0;
        }
        $catColor = static fn(int $catId) => $catId > 0 ? SprintCategory::getColorFor($catId) : self::NEUTRAL;

        echo "<div class='sprint-creditflow-block'>";
        echo "<div class='sprint-creditflow-title'><i class='fas fa-tags me-1'></i>"
            . __('Credits per tag', 'sprint') . "</div>";
        echo "<div class='text-muted sprint-small mb-2'>"
            . htmlescape(__('The same credits per sprint, split over the tags. An item with several tags counts on each of them, so a column can exceed the folder total.', 'sprint'))
            . "</div>";
        self::renderColumnChart($window, $columns, $series, true);

        // Category split per tag, one sprint at a time.
        $withData = array_values(array_filter($window, static fn($s) => !empty($columns[(int)$s['id']])));
        if (!$withData) {
            echo "</div>";
            return;
        }
        $selected = (int)$withData[count($withData) - 1]['id'];
        echo "<div class='sprint-cf-mix'>";
        echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-1'>";
        echo "<span class='sprint-creditflow-title mb-0'><i class='fas fa-sitemap me-1'></i>" . __('Categories per tag', 'sprint') . "</span>";
        echo "<select class='form-select form-select-sm sprint-cf-mix-pick' style='max-width:240px;'>";
        foreach ($withData as $sprint) {
            $sid = (int)$sprint['id'];
            echo "<option value='{$sid}'" . ($sid === $selected ? ' selected' : '') . ">" . htmlescape((string)$sprint['name']) . "</option>";
        }
        echo "</select></div>";
        echo "<div class='text-muted sprint-small mb-2'>"
            . htmlescape(__('Every tag bar is scaled to 100 %: which categories that tag worked on in the sprint. Click a column above or pick a sprint.', 'sprint'))
            . "</div>";
        foreach ($withData as $sprint) {
            $sid  = (int)$sprint['id'];
            $cell = $bySprint[$sid];
            echo "<div class='sprint-cf-mix-sprint' data-sprint='{$sid}'" . ($sid === $selected ? '' : ' hidden') . ">";
            foreach (array_keys($series) as $tag) {
                $tag   = (string)$tag;
                $byCat = $cell['tags'][$tag] ?? null;
                if (!$byCat) {
                    continue;
                }
                $sum = max(array_sum($byCat), 0.01);
                echo "<div class='sprint-cf-mix-row'>";
                echo "<div class='sprint-cf-mix-tag'><span class='sprint-matrix-dot' style='background:" . htmlescape(self::tagColor($tag)) . ";'></span>"
                    . ($tag === self::TAG_NONE ? "<span class='text-muted'>" . htmlescape(self::tagLabel($tag)) . "</span>" : htmlescape($tag))
                    . "</div>";
                echo "<div class='sprint-credit-chart-track sprint-cf-mix-track'>";
                foreach ($catOrder as $catId) {
                    $v = (float)($byCat[$catId] ?? 0);
                    if ($v <= 0) {
                        continue;
                    }
                    $pct = round(100 * $v / $sum, 2);
                    echo "<span style='width:{$pct}%;background:" . htmlescape($catColor($catId)) . ";' title='"
                        . htmlescape(self::tagLabel($tag) . ' · ' . self::categoryLabel($catId) . ': '
                            . SprintCustomer::formatCredits($v) . ' (' . round($pct) . '%)') . "'></span>";
                }
                echo "</div>";
                echo "<div class='sprint-credit-chart-value'>" . htmlescape(SprintCustomer::formatCredits($sum)) . "</div>";
                echo "</div>";
            }
            echo "</div>";
        }
        echo "<div class='sprint-credit-legend'>";
        foreach ($catOrder as $catId) {
            echo "<span class='sprint-credit-legend-item'>"
                . "<span class='sprint-matrix-dot' style='background:" . htmlescape($catColor($catId)) . ";'></span>"
                . htmlescape(self::categoryLabel($catId)) . "</span>";
        }
        echo "</div>";
        echo "</div>";
        echo "</div>";
    }

    /**
     * Inline SVG stacked column chart in the dashboard's style: one column per
     * sprint of the window, one segment per series, the total on the cap and a
     * legend that isolates a series on click.
     *
     * @param array<int,array<string|int,float>>                                     $columns    [sprint id][series key] => credits
     * @param array<string|int,array{label:string,color:string,total:float}>         $series     stack order, bottom first
     * @param bool                                                                    $selectable columns act as a sprint picker
     */
    private static function renderColumnChart(array $window, array $columns, array $series, bool $selectable): void
    {
        $w = 860; $h = 250;
        $padL = 48; $padR = 14; $padT = 24; $padB = 40;
        $plotW = $w - $padL - $padR;
        $plotH = $h - $padT - $padB;

        $max = 0.0;
        foreach ($columns as $cell) {
            $max = max($max, array_sum($cell));
        }
        $max  = $max > 0 ? $max : 1.0;
        $step = pow(10, max(0, (int)floor(log10($max)) - 1));
        $max  = ceil($max / (4 * $step)) * 4 * $step;

        $n    = max(1, count($window));
        $slot = $plotW / $n;
        $barW = min(30.0, max(10.0, $slot * 0.55));
        $num  = static fn(float $v) => number_format($v, 2, '.', '');
        $yAt  = static fn(float $v) => $padT + $plotH * (1 - $v / $max);
        $ink  = 'var(--tblr-secondary,#6c757d)';
        $grid = 'var(--tblr-border-color,#e9ecef)';
        $halo = "paint-order:stroke;stroke:var(--tblr-bg-surface,#fff);stroke-width:3px;stroke-linejoin:round;";

        echo "<div class='sprint-cf-chart'>";
        echo "<div class='sprint-credit-chartbox'>";
        echo "<svg viewBox='0 0 {$w} {$h}' class='sprint-credit-svg sprint-cf-svg' preserveAspectRatio='xMidYMid meet' role='img'>";
        for ($g = 0; $g <= 4; $g++) {
            $value = $max * $g / 4;
            $y     = $num($yAt($value));
            echo "<line x1='{$padL}' y1='{$y}' x2='" . ($w - $padR) . "' y2='{$y}' stroke='{$grid}' stroke-width='1'/>";
            echo "<text x='" . ($padL - 8) . "' y='" . $num($yAt($value) + 4) . "' fill='{$ink}' font-size='10' text-anchor='end'>"
                . htmlescape(SprintCustomer::formatCredits($value)) . "</text>";
        }
        $base = $num($yAt(0));
        foreach ($window as $i => $sprint) {
            $sid   = (int)$sprint['id'];
            $cx    = $padL + $slot * $i + $slot / 2;
            $x     = $num($cx - $barW / 2);
            $cell  = $columns[$sid] ?? [];
            $total = array_sum($cell);
            [$line1, $line2] = self::splitLabel((string)$sprint['name']);

            echo "<g class='sprint-cf-col' data-sprint='{$sid}'" . ($selectable && $total > 0 ? " role='button' tabindex='0'" : '') . ">";
            echo "<text class='sprint-cf-label' x='" . $num($cx) . "' y='" . ($h - $padB + 15) . "' fill='{$ink}' font-size='10' text-anchor='middle'>" . htmlescape($line1) . "</text>";
            if ($line2 !== '') {
                echo "<text class='sprint-cf-label' x='" . $num($cx) . "' y='" . ($h - $padB + 27) . "' fill='{$ink}' font-size='9' opacity='0.8' text-anchor='middle'>" . htmlescape($line2) . "</text>";
            }
            echo "<rect class='sprint-cf-hit' x='" . $num($padL + $slot * $i + 1) . "' y='{$padT}' width='" . $num(max(0, $slot - 2)) . "' height='{$plotH}' rx='4'/>";
            if ($selectable) {
                // Marker under the labels for the sprint whose split is shown below;
                // right under the axis it read as a loose piece of the bar.
                $markY = $h - $padB + ($line2 !== '' ? 34 : 22);
                $markW = min(44.0, max(12.0, $slot - 16));
                echo "<line class='sprint-cf-mark' x1='" . $num($cx - $markW / 2) . "' y1='{$markY}' x2='" . $num($cx + $markW / 2) . "' y2='{$markY}' stroke='#0d6efd' stroke-width='2' stroke-linecap='round'/>";
            }
            $stack = [];
            foreach ($series as $key => $meta) {
                $v = (float)($cell[$key] ?? 0);
                if ($v > 0) {
                    $stack[] = [$key, $v, $meta];
                }
            }
            $top = $yAt(0);
            foreach ($stack as $idx => [$key, $v, $meta]) {
                $hPx   = $plotH * $v / $max;
                $yTop  = $top - $hPx;
                $isTop = $idx === count($stack) - 1;
                $title = htmlescape($meta['label'] . ': ' . SprintCustomer::formatCredits($v) . ' (' . round(100 * $v / max($total, 0.01)) . '%)');
                $color = htmlescape($meta['color']);
                $attrs = "class='sprint-cf-seg' data-key='" . htmlescape((string)$key) . "' fill='{$color}'";
                if ($isTop) {
                    $r = min(4.0, $hPx / 2);
                    $d = 'M' . $x . ',' . $num($yTop + $r)
                        . ' a' . $num($r) . ',' . $num($r) . ' 0 0 1 ' . $num($r) . ',-' . $num($r)
                        . ' h' . $num($barW - 2 * $r)
                        . ' a' . $num($r) . ',' . $num($r) . ' 0 0 1 ' . $num($r) . ',' . $num($r)
                        . ' v' . $num($hPx - $r)
                        . ' h-' . $num($barW) . ' z';
                    echo "<path d='{$d}' {$attrs}><title>{$title}</title></path>";
                } else {
                    // 2px surface gap between this segment and the one above it.
                    $gapH = max(0.0, $hPx - 2);
                    echo "<rect x='{$x}' y='" . $num($yTop + 2) . "' width='" . $num($barW) . "' height='" . $num($gapH) . "' {$attrs}><title>{$title}</title></rect>";
                }
                $top = $yTop;
            }
            if ($total > 0) {
                echo "<text x='" . $num($cx) . "' y='" . $num(max($top - 6, 10)) . "' fill='var(--tblr-body-color,#1f2937)' font-size='10.5' font-weight='600' text-anchor='middle' style='{$halo}'>"
                    . htmlescape(SprintCustomer::formatCredits($total)) . "</text>";
            } else {
                echo "<line x1='" . $num($cx - 5) . "' y1='{$base}' x2='" . $num($cx + 5) . "' y2='{$base}' stroke='{$ink}' stroke-width='2' opacity='0.5'/>";
            }
            echo "</g>";
        }
        echo "<line x1='{$padL}' y1='{$base}' x2='" . ($w - $padR) . "' y2='{$base}' stroke='{$ink}' stroke-width='1' opacity='0.6'/>";
        echo "</svg></div>";

        echo "<div class='sprint-credit-legend'>";
        foreach ($series as $key => $meta) {
            echo "<button type='button' class='sprint-credit-legend-item sprint-cf-legend' data-key='" . htmlescape((string)$key) . "'>"
                . "<span class='sprint-matrix-dot' style='background:" . htmlescape($meta['color']) . ";'></span>"
                . htmlescape($meta['label'])
                . " <span class='text-muted'>" . htmlescape(SprintCustomer::formatCredits($meta['total'])) . "</span>"
                . "</button>";
        }
        echo "</div>";
        echo "</div>";
    }

    /** "Sprint 12 - 16/09/2026" reads as two axis lines; anything else is shortened. */
    private static function splitLabel(string $name): array
    {
        foreach ([' - ', ' – ', ' — '] as $sep) {
            $pos = mb_strpos($name, $sep);
            if ($pos !== false && $pos > 0) {
                return [mb_substr($name, 0, $pos), mb_substr($name, $pos + mb_strlen($sep))];
            }
        }
        return [mb_strlen($name) > 12 ? mb_substr($name, 0, 11) . '…' : $name, ''];
    }

    /** Colour per tag: the configured tags in their order, then any other tag as met. */
    public static function tagColor(string $tag): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (Config::getDefinedTags() as $i => $name) {
                $map[(string)$name] = self::PALETTE[$i % count(self::PALETTE)];
            }
        }
        if ($tag === self::TAG_NONE) {
            return self::NEUTRAL;
        }
        if (!isset($map[$tag])) {
            $map[$tag] = self::PALETTE[count($map) % count(self::PALETTE)];
        }
        return $map[$tag];
    }

    private static function customerLabel(int $cid, array $balances): string
    {
        if ($cid <= 0) {
            return __('No customer', 'sprint');
        }
        $name = (string)($balances[$cid]['name'] ?? SprintCustomer::getNameFor($cid));
        return $name !== '' ? $name : '#' . $cid;
    }

    private static function renderScript(): void
    {
        echo <<<'HTML'
<script>
(function(){
    if (typeof jQuery === 'undefined' || window.__sprintCreditFlowBound) { return; }
    window.__sprintCreditFlowBound = true;
    jQuery(document).on('change', '.sprint-creditflow-pick', function(){
        var id = String(jQuery(this).val());
        jQuery(this).closest('.card-body').find('.sprint-creditflow-customer').each(function(){
            this.hidden = (this.getAttribute('data-customer') !== id);
        });
    });
    // Legend click isolates one series of that chart; clicking again restores all.
    jQuery(document).on('click', '.sprint-cf-legend', function(){
        var chart = jQuery(this).closest('.sprint-cf-chart');
        var key = this.getAttribute('data-key');
        var on = !jQuery(this).hasClass('is-active');
        chart.find('.sprint-cf-legend').removeClass('is-active');
        chart.find('.sprint-cf-seg').removeClass('is-muted');
        if (on) {
            jQuery(this).addClass('is-active');
            chart.find('.sprint-cf-seg').each(function(){
                if (this.getAttribute('data-key') !== key) { jQuery(this).addClass('is-muted'); }
            });
        }
    });
    // A column of the tag chart picks the sprint for the category split.
    function pickSprint(block, id) {
        block.find('.sprint-cf-mix-pick').val(id);
        block.find('.sprint-cf-mix-sprint').each(function(){
            this.hidden = (this.getAttribute('data-sprint') !== id);
        });
        block.find('.sprint-cf-col').each(function(){
            jQuery(this).toggleClass('is-selected', this.getAttribute('data-sprint') === id);
        });
    }
    jQuery(document).on('change', '.sprint-cf-mix-pick', function(){
        pickSprint(jQuery(this).closest('.sprint-creditflow-block'), String(jQuery(this).val()));
    });
    jQuery(document).on('click keydown', '.sprint-cf-col[role="button"]', function(e){
        if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') { return; }
        e.preventDefault();
        pickSprint(jQuery(this).closest('.sprint-creditflow-block'), String(this.getAttribute('data-sprint')));
    });
    jQuery('.sprint-cf-mix-pick').each(function(){
        pickSprint(jQuery(this).closest('.sprint-creditflow-block'), String(jQuery(this).val()));
    });
})();
</script>
HTML;
    }
}
