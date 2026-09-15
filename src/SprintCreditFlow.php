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

    /** Stacked bar per sprint, one segment per top-level catalogue folder. */
    private static function renderFolderView(array $window, array $bySprint): void
    {
        $max     = 0.01;
        $folders = [];
        foreach ($bySprint as $cell) {
            $max = max($max, (float)$cell['total']);
            foreach ($cell['folders'] as $fid => $v) {
                $folders[(int)$fid] = ($folders[(int)$fid] ?? 0.0) + (float)$v;
            }
        }
        // Folders in catalogue order, manual credits last.
        $order = [];
        foreach (SprintCreditProduct::getAll(false) as $id => $row) {
            if (isset($folders[(int)$id])) {
                $order[] = (int)$id;
            }
        }
        if (isset($folders[self::FOLDER_MANUAL])) {
            $order[] = self::FOLDER_MANUAL;
        }

        echo "<div class='sprint-creditflow-block'>";
        echo "<div class='sprint-creditflow-title'><i class='fas fa-folder-tree me-1'></i>"
            . __('Credits per catalogue folder', 'sprint') . "</div>";
        echo "<div class='sprint-credit-chart'>";
        foreach ($window as $sprint) {
            $sid  = (int)$sprint['id'];
            $cell = $bySprint[$sid] ?? null;
            echo "<div class='sprint-credit-chart-row'>";
            echo "<div class='sprint-credit-chart-label'><a href='" . Sprint::getFormURLWithID($sid) . "'>"
                . htmlescape((string)$sprint['name']) . "</a></div>";
            echo "<div class='sprint-credit-chart-track'>";
            if ($cell) {
                foreach ($order as $fid) {
                    $v = (float)($cell['folders'][$fid] ?? 0);
                    if ($v <= 0) {
                        continue;
                    }
                    $pct = round(100 * $v / $max, 2);
                    echo "<span class='sprint-creditflow-seg' data-folder='" . $fid . "' style='width:{$pct}%;background:"
                        . htmlescape(self::folderColor($fid)) . ";' title='"
                        . htmlescape(self::folderLabel($fid) . ': ' . SprintCustomer::formatCredits($v)
                            . ' (' . round(100 * $v / max($cell['total'], 0.01)) . '%)') . "'></span>";
                }
            }
            echo "</div>";
            echo "<div class='sprint-credit-chart-value'>"
                . ($cell ? htmlescape(SprintCustomer::formatCredits($cell['total'])) : "<span class='text-muted'>—</span>")
                . "</div>";
            echo "</div>";
        }
        echo "</div>";

        echo "<div class='sprint-credit-legend'>";
        foreach ($order as $fid) {
            echo "<span class='sprint-credit-legend-item'>"
                . "<span class='sprint-matrix-dot' style='background:" . htmlescape(self::folderColor($fid)) . ";'></span>"
                . htmlescape(self::folderLabel($fid))
                . " <span class='text-muted'>" . htmlescape(SprintCustomer::formatCredits($folders[$fid])) . "</span>"
                . "</span>";
        }
        echo "</div>";
        echo "</div>";
    }

    /** Per sprint a 100 %-stacked bar per tag, split over the categories. */
    private static function renderTagView(array $window, array $bySprint): void
    {
        $tagOrder = [];
        $catSeen  = [];
        foreach (Config::getDefinedTags() as $tag) {
            $tagOrder[$tag] = true;
        }
        foreach ($bySprint as $cell) {
            foreach ($cell['tags'] as $tag => $byCat) {
                $tagOrder[$tag] = true;
                foreach ($byCat as $catId => $v) {
                    $catSeen[(int)$catId] = true;
                }
            }
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
            . __('Tags × categories', 'sprint') . "</div>";
        echo "<div class='text-muted sprint-small mb-2'>"
            . htmlescape(__('Every tag bar is scaled to 100 %: the split shows which categories that tag worked on in the sprint; the figure is the tag\'s credits.', 'sprint'))
            . "</div>";
        echo "<div class='sprint-credit-chart'>";
        foreach ($window as $sprint) {
            $sid  = (int)$sprint['id'];
            $cell = $bySprint[$sid] ?? null;
            echo "<div class='sprint-creditflow-tagrow'>";
            echo "<div class='sprint-credit-chart-label'><a href='" . Sprint::getFormURLWithID($sid) . "'>"
                . htmlescape((string)$sprint['name']) . "</a></div>";
            echo "<div class='sprint-creditflow-tags'>";
            $any = false;
            foreach (array_keys($tagOrder) as $tag) {
                $tag   = (string)$tag;
                $byCat = $cell['tags'][$tag] ?? null;
                if (!$byCat) {
                    continue;
                }
                $any      = true;
                $tagTotal = array_sum($byCat);
                echo "<div class='sprint-creditflow-tag'>";
                echo "<div class='sprint-creditflow-tagname'>"
                    . ($tag === self::TAG_NONE
                        ? "<span class='text-muted'>" . htmlescape(self::tagLabel($tag)) . "</span>"
                        : htmlescape($tag))
                    . " <span class='text-muted'>" . htmlescape(SprintCustomer::formatCredits($tagTotal)) . "</span></div>";
                echo "<div class='sprint-credit-chart-track'>";
                foreach ($catOrder as $catId) {
                    $v = (float)($byCat[$catId] ?? 0);
                    if ($v <= 0) {
                        continue;
                    }
                    $pct = round(100 * $v / max($tagTotal, 0.01), 2);
                    echo "<span style='width:{$pct}%;background:" . htmlescape($catColor($catId)) . ";' title='"
                        . htmlescape(self::tagLabel($tag) . ' · ' . self::categoryLabel($catId) . ': '
                            . SprintCustomer::formatCredits($v) . ' (' . round($pct) . '%)') . "'></span>";
                }
                echo "</div></div>";
            }
            if (!$any) {
                echo "<span class='text-muted'>—</span>";
            }
            echo "</div>";
            echo "</div>";
        }
        echo "</div>";

        echo "<div class='sprint-credit-legend'>";
        foreach ($catOrder as $catId) {
            echo "<span class='sprint-credit-legend-item'>"
                . "<span class='sprint-matrix-dot' style='background:" . htmlescape($catColor($catId)) . ";'></span>"
                . htmlescape(self::categoryLabel($catId)) . "</span>";
        }
        echo "</div>";
        echo "</div>";
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
})();
</script>
HTML;
    }
}
