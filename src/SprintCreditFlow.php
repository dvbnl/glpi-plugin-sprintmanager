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
    /** Grey for "no folder" / "no category" / "no tag". */
    const NEUTRAL = SprintChart::NEUTRAL;

    /** Series key of the folders folded into "Other". */
    const FOLDER_OTHER = -1;

    /** Series key of the tags folded into "Other". */
    const TAG_OTHER = '__other__';

    /** Width of a slot without credits, as a share of a filled one. */
    const EMPTY_SLOT = 0.7;

    /** Palette slot of the first tag, so tags and folders start on different hues. */
    const TAG_SLOT_OFFSET = 4;

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

    /**
     * Colour per top-level catalogue entry, stable across customers: the
     * shared series palette (SprintChart) in catalogue order. With more
     * folders than slots the colours repeat here and the chart re-assigns
     * them per view (distinctColors()).
     */
    public static function folderColor(int $folderId): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            $i   = 0;
            foreach (SprintCreditProduct::getAll(false) as $id => $row) {
                if ((int)($row['level'] ?? 0) === 0) {
                    $map[(int)$id] = SprintChart::seriesColor($i % SprintChart::SERIES_COUNT);
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
     * @param int                            $selected the customer the page filter is set to, 0 for all
     */
    public static function render(array $window, array $balances, int $selected = 0): void
    {
        $title = __('Credit flow per customer per sprint', 'sprint');
        $flow  = self::collect(array_map(static fn($s) => (int)$s['id'], $window));
        // Only customers the session may see (0 = no customer), and only the
        // filtered one when the page filter is set.
        $flow = array_filter(
            $flow,
            static fn($cid) => $selected > 0 ? (int)$cid === $selected : SprintCustomer::isVisible((int)$cid),
            ARRAY_FILTER_USE_KEY
        );

        echo "<div class='card mb-3'><div class='card-body'>";
        if (!$window || !$flow) {
            echo SprintUi::cardHeader(
                'fas fa-diagram-project',
                $title,
                __('Per customer: which catalogue folders their credits went to, and per tag which categories that tag picked up, sprint by sprint.', 'sprint')
            );
            echo SprintChart::emptyState(
                'fas fa-diagram-project',
                __('No credits assigned to a sprint yet.', 'sprint'),
                __('Give sprint items a customer and credits, or choose a longer period.', 'sprint')
            );
            echo "</div></div>";
            return;
        }

        // The picker only exists while the page shows every customer: with
        // the page filter on one customer that filter is the single source.
        $customerIds = array_keys($flow);
        $picker = '';
        if (count($customerIds) > 1) {
            $picker = "<select class='form-select form-select-sm sprint-creditflow-pick' aria-label='"
                . __s('Customer shown in the credit flow', 'sprint') . "'>";
            foreach ($customerIds as $i => $cid) {
                $picker .= "<option value='" . (int)$cid . "'" . ($i === 0 ? ' selected' : '') . ">"
                    . htmlescape(self::customerLabel((int)$cid, $balances)) . "</option>";
            }
            $picker .= "</select>";
        }
        echo SprintUi::cardHeader(
            'fas fa-diagram-project',
            $title,
            __('Per customer: which catalogue folders their credits went to, and per tag which categories that tag picked up, sprint by sprint. Open and delivered work in the sprint counts. A dependency\'s credits follow the parent item.', 'sprint'),
            $picker
        );

        foreach ($customerIds as $i => $cid) {
            $name = self::customerLabel((int)$cid, $balances);
            // Sprints before the customer's first and after their last claim
            // only add empty columns, so each customer gets their own span.
            $own  = self::trimWindow($window, $flow[$cid]);
            echo "<div class='sprint-creditflow-customer' data-customer='" . (int)$cid . "'" . ($i === 0 ? '' : ' hidden') . ">";
            self::renderFolderView($own, $flow[$cid], $name);
            self::renderTagView($own, $flow[$cid], $name);
            echo "</div>";
        }
        echo "</div></div>";
    }

    /** The window cut down to the span between the first and last sprint with credits. */
    private static function trimWindow(array $window, array $bySprint): array
    {
        $window = array_values($window);
        $first  = $last = null;
        foreach ($window as $i => $sprint) {
            if (abs((float)($bySprint[(int)$sprint['id']]['total'] ?? 0)) > 0) {
                $first ??= $i;
                $last = $i;
            }
        }
        return $first === null ? $window : array_slice($window, $first, $last - $first + 1);
    }

    /**
     * At most SprintChart::SERIES_COUNT series per chart: beyond that the
     * smallest ones are folded into one neutral "Other" series.
     *
     * @param array<string|int,array{label:string,color:string,total:float}> $series
     * @param array<int,array<string|int,float>>                             $columns  rewritten to match
     * @param string|int                                                     $otherKey key of the folded series
     */
    private static function foldSeries(array $series, array &$columns, $otherKey): array
    {
        if (count($series) <= SprintChart::SERIES_COUNT) {
            return $series;
        }
        $ranked = $series;
        uasort($ranked, static fn($a, $b) => $b['total'] <=> $a['total']);
        $keep  = array_slice(array_keys($ranked), 0, SprintChart::SERIES_COUNT - 1);
        $out   = [];
        $other = 0.0;
        $fold  = [];
        foreach ($series as $key => $meta) {
            if (in_array($key, $keep, true)) {
                $out[$key] = $meta;
            } else {
                $fold[] = $key;
                $other += (float)$meta['total'];
            }
        }
        foreach ($columns as $sid => $cell) {
            foreach ($fold as $key) {
                if (isset($cell[$key])) {
                    $columns[$sid][$otherKey] = ($columns[$sid][$otherKey] ?? 0.0) + (float)$cell[$key];
                    unset($columns[$sid][$key]);
                }
            }
        }
        $out[$otherKey] = ['label' => __('Other', 'sprint'), 'color' => SprintChart::OTHER, 'total' => $other];
        return $out;
    }

    /**
     * Give every series of one chart its own palette slot when the stable
     * colours collide (more catalogue folders or tags than slots).
     *
     * @param int $offset palette slot of the first series
     */
    private static function distinctColors(array $series, int $offset): array
    {
        $seen = [];
        $clash = false;
        foreach ($series as $meta) {
            if ($meta['color'] === SprintChart::NEUTRAL || $meta['color'] === SprintChart::OTHER) {
                continue;
            }
            $clash = $clash || isset($seen[$meta['color']]);
            $seen[$meta['color']] = true;
        }
        if (!$clash) {
            return $series;
        }
        $pos = 0;
        foreach ($series as $key => $meta) {
            if ($meta['color'] === SprintChart::NEUTRAL || $meta['color'] === SprintChart::OTHER) {
                continue;
            }
            $series[$key]['color'] = SprintChart::seriesColor(($pos + $offset) % SprintChart::SERIES_COUNT);
            $pos++;
        }
        return $series;
    }

    /** Legend entries of a column chart: one per series, with its total. */
    private static function seriesLegend(array $series, string $mark): string
    {
        $items = [];
        foreach ($series as $key => $meta) {
            $items[] = [
                'key'   => (string)$key,
                'label' => $meta['label'],
                'color' => $meta['color'],
                'mark'  => $mark,
                'value' => SprintCustomer::formatCredits($meta['total']),
            ];
        }
        return SprintChart::legend($items, true);
    }

    /** Stacked column per sprint, one segment per top-level catalogue folder. */
    private static function renderFolderView(array $window, array $bySprint, string $customer): void
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
        $series = self::distinctColors(self::foldSeries($series, $columns, self::FOLDER_OTHER), 0);

        $largest = '';
        $top     = 0.0;
        foreach ($series as $meta) {
            if ($meta['total'] > $top) {
                $top     = $meta['total'];
                $largest = $meta['label'];
            }
        }
        echo "<div class='sprint-creditflow-block' data-chart-scope>";
        echo "<div class='sprint-creditflow-head'><div class='sprint-creditflow-title'><i class='fas fa-folder-tree me-1' aria-hidden='true'></i>"
            . htmlescape(__('Credits per catalogue folder', 'sprint')) . "</div>"
            . self::seriesLegend($series, 'bar') . "</div>";
        echo "<div class='text-muted sprint-small mb-2'>"
            . htmlescape(__('Credits the customer\'s items claimed per sprint, split over the top-level catalogue folders. Click a folder in the legend to isolate it.', 'sprint'))
            . "</div>";
        self::renderColumnChart($window, $columns, $series, false, false, sprintf(
            __('Credits per catalogue folder for %1$s. %2$s credits over %3$s sprints, most of it in %4$s.', 'sprint'),
            $customer,
            SprintCustomer::formatCredits(array_sum($folders)),
            count($window),
            $largest
        ));
        echo "</div>";
    }

    /**
     * Stacked column per sprint, one segment per tag, and below it the
     * category split of every tag for the sprint picked in the chart.
     */
    private static function renderTagView(array $window, array $bySprint, string $customer): void
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
        $series = self::distinctColors(self::foldSeries($series, $columns, self::TAG_OTHER), self::TAG_SLOT_OFFSET);

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

        $largest = '';
        $top     = 0.0;
        foreach ($series as $meta) {
            if ($meta['total'] > $top) {
                $top     = $meta['total'];
                $largest = $meta['label'];
            }
        }
        $withData = array_values(array_filter($window, static fn($s) => !empty($columns[(int)$s['id']])));
        $selected = $withData ? (int)$withData[count($withData) - 1]['id'] : 0;

        echo "<div class='sprint-creditflow-block' data-chart-scope>";
        echo "<div class='sprint-creditflow-head'><div class='sprint-creditflow-title'><i class='fas fa-tags me-1' aria-hidden='true'></i>"
            . htmlescape(__('Credits per tag', 'sprint')) . "</div>"
            . self::seriesLegend($series, 'hatch') . "</div>";
        echo "<div class='text-muted sprint-small mb-2'>"
            . htmlescape(__('The same credits per sprint, split over the tags. An item with several tags counts on each of them, so a column can exceed the folder total.', 'sprint'))
            . "</div>";
        self::renderColumnChart($window, $columns, $series, true, true, sprintf(
            __('Credits per tag for %1$s over %2$s sprints. The largest tag is %3$s with %4$s credits.', 'sprint'),
            $customer,
            count($window),
            $largest,
            SprintCustomer::formatCredits($top)
        ), $selected);

        // Category split per tag, one sprint at a time.
        if (!$withData) {
            echo "</div>";
            return;
        }
        echo "<div class='sprint-cf-mix'>";
        echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-1'>";
        echo "<span class='sprint-creditflow-title mb-0'><i class='fas fa-sitemap me-1' aria-hidden='true'></i>"
            . htmlescape(__('Categories per tag', 'sprint')) . "</span>";
        echo "<select class='form-select form-select-sm sprint-cf-mix-pick' aria-label='"
            . htmlescape(__('Sprint shown in the category split', 'sprint')) . "'>";
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
            // Tags folded into "Other" in the chart above keep their own row here.
            $rowTags = array_unique(array_merge(
                array_map('strval', array_keys($series)),
                array_map('strval', array_keys($cell['tags']))
            ));
            echo "<div class='sprint-cf-mix-sprint' data-sprint='{$sid}'" . ($sid === $selected ? '' : ' hidden') . ">";
            foreach ($rowTags as $tag) {
                $byCat = $cell['tags'][$tag] ?? null;
                if (!$byCat) {
                    continue;
                }
                $sum   = max(array_sum($byCat), 0.01);
                $color = $series[$tag]['color'] ?? SprintChart::OTHER;
                echo "<div class='sprint-cf-mix-row'>";
                echo "<div class='sprint-cf-mix-tag'><span class='sprint-chart-swatch sprint-chart-swatch-bar sprint-chart-hatch' style='background-color:"
                    . htmlescape($color) . "' aria-hidden='true'></span>"
                    . ($tag === self::TAG_NONE ? "<span class='text-muted'>" . htmlescape(self::tagLabel($tag)) . "</span>" : htmlescape($tag))
                    . "</div>";
                echo "<div class='sprint-credit-chart-track sprint-cf-mix-track'>";
                foreach ($catOrder as $catId) {
                    $v = (float)($byCat[$catId] ?? 0);
                    if ($v <= 0) {
                        continue;
                    }
                    $pct   = round(100 * $v / $sum, 2);
                    $value = SprintCustomer::formatCredits($v) . ' (' . round($pct) . '%)';
                    echo "<span style='width:{$pct}%;background:" . htmlescape($catColor($catId)) . ";' title='"
                        . htmlescape(self::tagLabel($tag) . ' · ' . self::categoryLabel($catId) . ': ' . $value) . "'"
                        . SprintChart::tipAttr(self::tagLabel($tag), [[self::categoryLabel($catId), $value, $catColor($catId)]])
                        . "></span>";
                }
                echo "</div>";
                echo "<div class='sprint-credit-chart-value'>" . htmlescape(SprintCustomer::formatCredits($sum)) . "</div>";
                echo "</div>";
            }
            echo "</div>";
        }
        $catItems = [];
        foreach ($catOrder as $catId) {
            $catItems[] = ['label' => self::categoryLabel($catId), 'color' => $catColor($catId), 'mark' => 'bar'];
        }
        echo SprintChart::legend($catItems, false);
        echo "</div>";
        echo "</div>";
    }

    /**
     * Inline SVG stacked column chart: one column per sprint of the window,
     * one segment per series, the total on the cap. The legend is built by
     * the caller (seriesLegend) and isolates a series through the shared
     * chart script.
     *
     * @param array<int,array<string|int,float>>                             $columns    [sprint id][series key] => credits
     * @param array<string|int,array{label:string,color:string,total:float}> $series     stack order, bottom first
     * @param bool                                                           $selectable columns act as a sprint picker
     * @param bool                                                           $hatched    segments carry the tag hatch
     * @param string                                                         $aria       title plus one sentence summary
     * @param int                                                            $selected   sprint whose column starts selected
     */
    private static function renderColumnChart(array $window, array $columns, array $series, bool $selectable, bool $hatched, string $aria, int $selected = 0): void
    {
        $window = array_values($window);
        $w      = 1000;
        $h      = 280;
        $padL   = 48;
        $padR   = 14;
        $padT   = 34;
        $plotW  = $w - $padL - $padR;
        $num    = [SprintChart::class, 'num'];
        $fmt    = static fn($v): string => SprintCustomer::formatCredits($v);

        $max = 0.0;
        foreach ($columns as $cell) {
            $max = max($max, array_sum($cell));
        }
        $scale = SprintChart::niceScale($max);

        // A sprint without credits in the middle keeps its place (the gap is
        // information) but takes a narrower slot.
        $weights = [];
        foreach ($window as $i => $sprint) {
            $weights[$i] = array_sum($columns[(int)$sprint['id']] ?? []) > 0 ? 1.0 : self::EMPTY_SLOT;
        }
        $unit = $plotW / max(self::EMPTY_SLOT, array_sum($weights));
        $barW = min(34.0, max(10.0, $unit * 0.55));
        $axis = SprintChart::axisLabels(array_map(static fn($s) => (string)$s['name'], $window), $unit);
        $padB = $axis['height'] + ($selectable ? 12 : 6);
        $yB   = $h - $padB;
        $plotH = $yB - $padT;

        echo "<div class='sprint-cf-chart'>";
        echo "<div class='sprint-chart-box'>";
        echo SprintChart::svgOpen($aria, $w, $h, 'sprint-cf-svg');
        if ($hatched) {
            // One pattern id for every tag chart on the page: they are identical.
            echo "<defs><pattern id='sprint-chart-hatch' patternUnits='userSpaceOnUse' width='6' height='6' patternTransform='rotate(45)'>"
                . "<line class='sprint-chart-hatch-line' x1='0' y1='0' x2='0' y2='6'/></pattern></defs>";
        }
        echo SprintChart::yAxis($scale, $padL, $w - $padR, $padT, $yB, $fmt);

        $left = (float)$padL;
        foreach ($window as $i => $sprint) {
            $sid     = (int)$sprint['id'];
            $slot    = $unit * $weights[$i];
            $cx      = $left + $slot / 2;
            $x       = $cx - $barW / 2;
            $cell    = $columns[$sid] ?? [];
            $total   = array_sum($cell);
            $running = (string)$sprint['status'] === Sprint::STATUS_ACTIVE;

            $stack = [];
            $rows  = [];
            foreach ($series as $key => $meta) {
                $v = (float)($cell[$key] ?? 0);
                if ($v > 0) {
                    $stack[] = [$key, $v, $meta];
                    $rows[]  = [$meta['label'], $fmt($v) . ' (' . round(100 * $v / max($total, 0.01)) . '%)', $meta['color']];
                }
            }
            // Top of the stack first, the way the column reads.
            $rows = array_reverse($rows);
            if (count($rows) > 1) {
                $rows[] = [__('Total', 'sprint'), $fmt($total)];
            }
            $title = (string)$sprint['name'] . ($running ? ' · ' . __('running', 'sprint') : '');

            echo "<g class='sprint-cf-col" . ($total > 0 ? '' : ' is-empty') . ($running ? ' is-running' : '')
                . ($selectable && $sid === $selected ? ' is-selected' : '') . "' data-sprint='{$sid}'"
                . ($total > 0 ? " tabindex='0'" . ($selectable ? " role='button'" : '') : '')
                . SprintChart::tipAttr($title, $rows ?: [[__('No credits', 'sprint'), '']]) . ">";
            // A narrow empty slot only keeps its label when it fits there.
            $fits = $total > 0 || ($axis['mode'] !== 'rotate' && mb_strlen($axis['lines'][$i][0]) * 5.6 <= $slot - 4
                && ($axis['mode'] !== 'two' || mb_strlen($axis['lines'][$i][1]) * 5.0 <= $slot - 4));
            if ($fits) {
                echo SprintChart::axisLabel($axis, $i, $cx, $yB, 'sprint-cf-label' . ($total > 0 ? '' : ' is-dim'));
            }
            echo "<rect class='sprint-cf-hit' x='" . $num($left + 1) . "' y='{$padT}' width='" . $num(max(0, $slot - 2))
                . "' height='" . $num($plotH) . "' rx='4'/>";
            if ($selectable) {
                // Marker under the labels for the sprint whose split is shown below.
                $markY = $num($yB + $axis['height'] + 4);
                $markW = min(44.0, max(12.0, $slot - 16));
                echo "<line class='sprint-cf-mark' x1='" . $num($cx - $markW / 2) . "' y1='{$markY}' x2='" . $num($cx + $markW / 2) . "' y2='{$markY}'/>";
            }
            $top = (float)$yB;
            foreach ($stack as $idx => [$key, $v, $meta]) {
                $hPx   = $plotH * $v / $scale['max'];
                $yTop  = $top - $hPx;
                $isTop = $idx === count($stack) - 1;
                $segTitle = htmlescape($meta['label'] . ': ' . $fmt($v) . ' (' . round(100 * $v / max($total, 0.01)) . '%)');
                if ($isTop) {
                    $r = min(4.0, $hPx / 2);
                    $d = 'M' . $num($x) . ',' . $num($yTop + $r)
                        . ' a' . $num($r) . ',' . $num($r) . ' 0 0 1 ' . $num($r) . ',-' . $num($r)
                        . ' h' . $num($barW - 2 * $r)
                        . ' a' . $num($r) . ',' . $num($r) . ' 0 0 1 ' . $num($r) . ',' . $num($r)
                        . ' v' . $num($hPx - $r)
                        . ' h-' . $num($barW) . ' z';
                    $shape = "<path d='{$d}'";
                } else {
                    // 2px surface gap between this segment and the one above it.
                    $shape = "<rect x='" . $num($x) . "' y='" . $num($yTop + 2) . "' width='" . $num($barW)
                        . "' height='" . $num(max(0.0, $hPx - 2)) . "'";
                }
                echo "<g class='sprint-chart-series sprint-cf-seg' data-series='" . htmlescape((string)$key) . "'>"
                    . $shape . " fill='" . htmlescape($meta['color']) . "'><title>{$segTitle}</title>" . ($isTop ? '</path>' : '</rect>')
                    . ($hatched ? $shape . " fill='url(#sprint-chart-hatch)' class='sprint-chart-hatch-overlay'/>" : '')
                    . "</g>";
                $top = $yTop;
            }
            if ($running) {
                // A dashed frame: a column that is still filling is no verdict.
                $frameTop = min($top, $yB - 14) - 3;
                echo "<rect class='sprint-chart-running' x='" . $num($x - 3) . "' y='" . $num($frameTop) . "' width='" . $num($barW + 6)
                    . "' height='" . $num($yB - $frameTop) . "' rx='5'/>";
            }
            if ($total > 0) {
                echo "<text class='sprint-chart-value' x='" . $num($cx) . "' y='" . $num(max($top - 7, 22)) . "' text-anchor='middle'>"
                    . htmlescape($fmt($total)) . "</text>";
            } elseif (!$running) {
                echo "<line class='sprint-chart-axis sprint-cf-zero' x1='" . $num($cx - 5) . "' y1='" . $num($yB) . "' x2='" . $num($cx + 5) . "' y2='" . $num($yB) . "'/>";
            }
            if ($running) {
                echo "<text class='sprint-chart-label sprint-chart-label-sub' x='" . $num($cx) . "' y='"
                    . $num(max(($total > 0 ? $top - 19 : $yB - 22), 10)) . "' text-anchor='middle'>" . htmlescape(__('running', 'sprint')) . "</text>";
            }
            echo "</g>";
            $left += $slot;
        }
        echo "</svg></div>";
        echo "</div>";
    }

    /**
     * Colour per tag: the configured tags in their order, then any other tag
     * as met. Tags start halfway down the palette so the first tag never
     * shares a colour with the first catalogue folder of the same card.
     */
    public static function tagColor(string $tag): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (array_values(Config::getDefinedTags()) as $name) {
                $map[(string)$name] = count($map);
            }
        }
        if ($tag === self::TAG_NONE) {
            return self::NEUTRAL;
        }
        if (!isset($map[$tag])) {
            $map[$tag] = count($map);
        }
        return SprintChart::seriesColor(($map[$tag] + self::TAG_SLOT_OFFSET) % SprintChart::SERIES_COUNT);
    }

    private static function customerLabel(int $cid, array $balances): string
    {
        if ($cid <= 0) {
            return __('No customer', 'sprint');
        }
        $name = (string)($balances[$cid]['name'] ?? SprintCustomer::getNameFor($cid));
        return $name !== '' ? $name : '#' . $cid;
    }
}
