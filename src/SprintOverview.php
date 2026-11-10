<?php

namespace GlpiPlugin\Sprint;

use CommonGLPI;
use Dropdown;
use Html;
use Search;
use Session;
use Plugin;

/**
 * SprintManager landing page: a side navigation with a cross-sprint statistics
 * section next to the regular sprint list.
 */
class SprintOverview extends CommonGLPI
{
    public static $rightname = 'plugin_sprint_sprint';

    const SECTION_OVERVIEW = 'overview';
    const SECTION_LIST     = 'list';

    const PERIOD_DAY      = 'day';
    const PERIOD_WEEK     = 'week';
    const PERIOD_MONTH    = 'month';
    const PERIOD_HALFYEAR = 'halfyear';
    const PERIOD_YEAR     = 'year';
    const PERIOD_ALL      = 'all';

    /** Maximum sprints shown in charts. */
    const CHART_SPRINTS = 20;

    public static function getTypeName($nb = 0): string
    {
        return __('Sprint overview', 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-chart-line';
    }

    public static function getPeriods(): array
    {
        return [
            self::PERIOD_DAY      => __('Today', 'sprint'),
            self::PERIOD_WEEK     => __('This week', 'sprint'),
            self::PERIOD_MONTH    => __('This month', 'sprint'),
            self::PERIOD_HALFYEAR => __('6 months', 'sprint'),
            self::PERIOD_YEAR     => __('This year', 'sprint'),
            self::PERIOD_ALL      => __('All time', 'sprint'),
        ];
    }

    /** @return array<string,array{label:string,url:string,icon:string}> shared by every page */
    public static function navItems(): array
    {
        // Full URLs: a root-relative path would double the plugin prefix.
        $self = Plugin::getWebDir('sprint') . '/front/sprint.php';

        $items = [
            self::SECTION_OVERVIEW => [
                'label' => __('Overview', 'sprint'),
                'url'   => $self . '?section=' . self::SECTION_OVERVIEW,
                'icon'  => self::getIcon(),
            ],
            self::SECTION_LIST => [
                'label' => Sprint::getTypeName(2),
                'url'   => $self . '?section=' . self::SECTION_LIST,
                'icon'  => Sprint::getIcon(),
            ],
        ];
        if (Backlog::canView()) {
            $items['backlog'] = [
                'label' => Backlog::getTypeName(2),
                'url'   => Backlog::getSearchURL(),
                'icon'  => Backlog::getIcon(),
            ];
        }
        if (SprintCustomer::canViewCredits()) {
            $items['credits'] = [
                'label' => SprintCredits::getTypeName(2),
                'url'   => SprintCredits::getSearchURL(),
                'icon'  => SprintCredits::getIcon(),
            ];
            $items['creditfall'] = [
                'label' => SprintCreditFall::getTypeName(2),
                'url'   => SprintCreditFall::getSearchURL(),
                'icon'  => SprintCreditFall::getIcon(),
            ];
            $items['catalogue'] = [
                'label' => __('Catalogue', 'sprint'),
                'url'   => SprintCreditProduct::getSearchURL(),
                'icon'  => SprintCreditProduct::getIcon(),
            ];
        }
        $items['sprinttemplate'] = [
            'label' => SprintTemplate::getTypeName(2),
            'url'   => SprintTemplate::getSearchURL(),
            'icon'  => SprintTemplate::getIcon(),
        ];
        if (Session::haveRight('config', READ)) {
            $items['config'] = [
                'label' => __('Settings', 'sprint'),
                'url'   => Plugin::getWebDir('sprint') . '/front/config.php',
                'icon'  => 'fas fa-cog',
            ];
        }
        return $items;
    }

    /**
     * Two-column layout: navigation left, page content right. Close with {@see navEnd()}.
     * Below the md breakpoint the menu collapses behind one toggle row so it
     * no longer pushes the content down; deeplinks stay the same.
     */
    public static function navStart(string $active): void
    {
        $items  = self::navItems();
        $panel  = 'sprint-side-nav-' . mt_rand();
        $activeLabel = $items[$active]['label'] ?? 'SprintManager';

        echo "<div class='row g-3 sprint-nav-layout'>";
        echo "<div class='col-12 col-md-3 col-xl-2'>";
        echo "<nav class='card sprint-side-nav' aria-label='" . __s('SprintManager navigation', 'sprint') . "'>";
        echo "<button type='button' class='btn sprint-nav-toggle d-md-none' data-bs-toggle='collapse' "
            . "data-bs-target='#{$panel}' aria-expanded='false' aria-controls='{$panel}'>"
            . "<i class='fas fa-bars me-2' aria-hidden='true'></i>"
            . "<span>" . htmlescape($activeLabel) . "</span>"
            . "<i class='fas fa-chevron-down ms-auto' aria-hidden='true'></i></button>";
        echo "<div id='{$panel}' class='collapse d-md-block sprint-nav-panel'>";
        echo "<div class='list-group list-group-flush'>";
        foreach ($items as $key => $item) {
            $isActive = $key === $active;
            $class = 'list-group-item list-group-item-action' . ($isActive ? ' active' : '');
            echo "<a href='" . htmlescape($item['url']) . "' class='" . $class . "'" . ($isActive ? " aria-current='page'" : '') . ">"
                . "<i class='" . htmlescape($item['icon']) . " me-2' aria-hidden='true'></i>" . htmlescape($item['label']) . "</a>";
        }
        echo "</div></div></nav></div>";
        echo "<div class='col-12 col-md-9 col-xl-10 sprint-nav-content'>";
    }

    public static function navEnd(): void
    {
        echo "</div></div>";
    }

    /** Page entry point: side nav + the requested section. */
    public static function show(string $section, string $period): void
    {
        $section = $section === self::SECTION_LIST ? self::SECTION_LIST : self::SECTION_OVERVIEW;
        if (!array_key_exists($period, self::getPeriods())) {
            $period = self::PERIOD_YEAR;
        }

        self::navStart($section);
        if ($section === self::SECTION_LIST) {
            Search::show(Sprint::class);
        } else {
            self::renderStatistics($period);
        }
        self::navEnd();
    }

    // =========================================================================
    // Statistics section
    // =========================================================================

    private static function renderStatistics(string $period): void
    {
        echo "<div class='sprint-overview-page'>";
        self::renderStatisticsBody($period);
        echo "</div>";
    }

    /**
     * Daily work first (live sprints), then four key figures with the rest
     * as a compact row, then the analysis zone with the trend charts.
     */
    private static function renderStatisticsBody(string $period): void
    {
        $filters  = self::filtersFromRequest();
        $data     = self::collect($period, false, $filters);
        $previous = $period === self::PERIOD_ALL ? ['sprint_count' => 0] : self::collect($period, true, $filters);
        $inWindow = self::countCompletedInWindow($period, $filters);

        echo "<h2 class='mb-1'><i class='" . self::getIcon() . " me-2' aria-hidden='true'></i>"
            . __('Sprint overview', 'sprint') . "</h2>";
        echo "<div class='text-muted mb-3'>"
            . __('Trends across completed sprints, filtered on their end date.', 'sprint') . "</div>";

        self::renderPeriodSelector($period, $filters);
        self::renderFilterBar($filters, $period, (int)$data['sprint_count'], $inWindow);
        self::renderLiveSprints($filters);

        if ($data['sprint_count'] === 0) {
            self::renderEmptyState($period, $filters, $inWindow);
            self::renderComparison($data, $period, $filters);
            self::renderOverviewBehaviour();
            return;
        }

        self::renderTiles($data, $previous, $period);

        echo "<div class='sprint-overview-analysis-head'>";
        echo "<h3 class='m-0'><i class='fas fa-chart-pie me-2' aria-hidden='true'></i>" . __s('Analysis', 'sprint') . "</h3>";
        echo "<div class='btn-group btn-group-sm'>"
            . "<button type='button' class='btn btn-outline-secondary' data-overview-all='expand'>" . __s('Expand all', 'sprint') . "</button>"
            . "<button type='button' class='btn btn-outline-secondary' data-overview-all='collapse'>" . __s('Collapse all', 'sprint') . "</button>"
            . "</div></div>";

        $chart = self::trimToChartWindow($data);
        if ($chart['dropped'] > 0) {
            echo "<div class='text-muted sprint-small mb-2'><i class='fas fa-info-circle me-1' aria-hidden='true'></i>"
                . sprintf(
                    __('Charts show the last %1$d of %2$d sprints in this period. The tiles cover all of them.', 'sprint'),
                    self::CHART_SPRINTS,
                    $data['sprint_count']
                ) . "</div>";
        }

        self::renderCard('velocity',
            __('Velocity trend', 'sprint'),
            'fas fa-chart-column',
            __('Completed story points per sprint, with predictability % (line).', 'sprint'),
            function () use ($chart, $data) {
                self::renderGroupedBars(
                    $chart['labels'],
                    [['label' => __('Completed points', 'sprint'), 'color' => SprintChart::metricColor('completed'), 'values' => $chart['series']['pts_done']]],
                    [
                        'label'  => __('Predictability %', 'sprint'),
                        'color'  => SprintChart::seriesColor(2),
                        'values' => $chart['series']['predictability'],
                        'suffix' => '%',
                        'max'    => 100,
                    ],
                    [
                        'label' => sprintf(__('Avg points: %s', 'sprint'), number_format($data['avg_velocity'], 1)),
                        'value' => $data['avg_velocity'],
                        'color' => SprintChart::metricColor('completed'),
                    ],
                    $chart['links'],
                    __('Velocity trend', 'sprint')
                );
            }
        );

        self::renderCard('planned-delivered',
            __('Planned vs. delivered', 'sprint'),
            'fas fa-bullseye',
            __('Story points taken into the sprint against the points actually completed.', 'sprint'),
            function () use ($chart) {
                self::renderGroupedBars($chart['labels'], [
                    ['label' => __('Planned points', 'sprint'), 'color' => SprintChart::metricColor('planned'), 'values' => $chart['series']['pts_planned']],
                    ['label' => __('Completed points', 'sprint'), 'color' => SprintChart::metricColor('completed'), 'values' => $chart['series']['pts_done']],
                    ['label' => __('Carry-over items', 'sprint'), 'color' => SprintChart::metricColor('carryover'), 'values' => $chart['series']['carryover']],
                ], null, null, $chart['links'], __('Planned vs. delivered', 'sprint'));
            }
        );

        self::renderCategoryDelivery($data);
        self::renderMemberDelivery($data);
        self::renderCategoryTrend($data);

        self::renderCard('fastlane-adhoc',
            __('Fastlane & adhoc', 'sprint'),
            'fas fa-bolt',
            __('Interrupt work per sprint: completed fastlane items, adhoc items and the allocated fastlane capacity % (line).', 'sprint'),
            function () use ($chart) {
                self::renderGroupedBars(
                    $chart['labels'],
                    [
                        ['label' => __('Completed fastlane items', 'sprint'), 'color' => SprintChart::metricColor('fastlane'), 'values' => $chart['series']['fl_done']],
                        ['label' => __('Adhoc items', 'sprint'), 'color' => SprintChart::metricColor('adhoc'), 'values' => $chart['series']['adhoc']],
                    ],
                    [
                        'label'  => __('Fastlane capacity %', 'sprint'),
                        'color'  => SprintChart::metricColor('fastlane'),
                        'values' => $chart['series']['fl_cap'],
                        'suffix' => '%',
                        'max'    => max(25, (int)ceil(max(array_merge([0], $chart['series']['fl_cap'])) / 25) * 25),
                    ],
                    null,
                    $chart['links'],
                    __('Fastlane & adhoc', 'sprint')
                );
            }
        );

        echo "<div class='row g-3 mb-3'>";
        self::renderCard('points-member',
            __('Completed points per member', 'sprint'),
            'fas fa-users',
            '',
            function () use ($data) {
                self::renderRankedBars($data['per_member'], 'blue', __('points', 'sprint'));
            },
            'col-12 col-lg-6'
        );
        self::renderCard('items-type',
            __('Completed items per type', 'sprint'),
            'fas fa-shapes',
            '',
            function () use ($data) {
                self::renderRankedBars($data['per_type'], 'purple', __('items', 'sprint'));
            },
            'col-12 col-lg-6'
        );
        echo "</div>";

        self::renderWorkloadTrend($data);
        self::renderWorkload($data);
        self::renderScopeStability($chart);
        self::renderFlowHealth($data);
        self::renderCapacityDelivery($data);
        self::renderDependencyHealth($data);
        self::renderComparison($data, $period, $filters);
        self::renderSprintTable($data);
        self::renderOverviewBehaviour();
    }

    /**
     * Realised work per backlog category/subcategory over the completed sprints
     * in the period: planned vs completed points, so the product owner can
     * review per category what was delivered and what was not.
     */
    private static function renderCategoryDelivery(array $data): void
    {
        $per = $data['per_category'] ?? [];
        if (!$per) {
            return;
        }

        self::renderCard('category-delivery',
            __('Delivered per category', 'sprint'),
            'fas fa-folder-tree',
            __('Planned versus completed story points per backlog category across the completed sprints in this period. A parent category row includes its subcategories.', 'sprint'),
            function () use ($per) {
                $cats = SprintCategory::getAll(false);

                // Points on deleted categories count as uncategorized.
                $none = ['pts_planned' => 0, 'pts_done' => 0, 'items_total' => 0, 'items_done' => 0];
                foreach ($per as $cid => $bucket) {
                    if ((int)$cid === 0 || !isset($cats[(int)$cid])) {
                        foreach ($none as $key => $v) {
                            $none[$key] += (int)$bucket[$key];
                        }
                    }
                }

                // Tree rows: parent = own + subcategories, children indented.
                $rows = [];
                foreach ($cats as $cid => $cat) {
                    if ((int)($cat['level'] ?? 0) > 0) {
                        continue;
                    }
                    $childIds = SprintCategory::getChildrenOf((int)$cid, false);
                    $rollup   = $per[(int)$cid] ?? ['pts_planned' => 0, 'pts_done' => 0, 'items_total' => 0, 'items_done' => 0];
                    foreach ($childIds as $childId) {
                        foreach ($per[$childId] ?? [] as $key => $v) {
                            $rollup[$key] += (int)$v;
                        }
                    }
                    if ($rollup['items_total'] === 0) {
                        continue;
                    }
                    $rows[] = ['id' => (int)$cid, 'label' => (string)$cat['name'], 'color' => (string)$cat['color'], 'level' => 0] + $rollup;
                    foreach ($childIds as $childId) {
                        $bucket = $per[$childId] ?? null;
                        if ($bucket === null || (int)$bucket['items_total'] === 0) {
                            continue;
                        }
                        $rows[] = [
                            'id'    => $childId,
                            'label' => (string)($cats[$childId]['name'] ?? ''),
                            'color' => (string)($cats[$childId]['color'] ?? '#6c757d'),
                            'level' => 1,
                        ] + $bucket;
                    }
                }
                if ($none['items_total'] > 0) {
                    $rows[] = ['id' => 0, 'label' => __('No category', 'sprint'), 'color' => '#6c757d', 'level' => 0] + $none;
                }

                if (!$rows) {
                    echo "<div class='text-muted py-3'>" . __('No data for this period.', 'sprint') . "</div>";
                    return;
                }

                echo "<div class='table-responsive'><table class='table table-vcenter mb-0'>";
                echo "<thead><tr>";
                echo "<th>" . __('Category', 'sprint') . "</th>";
                echo "<th style='width:30%;'>" . __('Completed vs planned points', 'sprint') . "</th>";
                echo "<th class='text-end'>" . __('Completed points', 'sprint') . "</th>";
                echo "<th class='text-end'>" . __('Planned points', 'sprint') . "</th>";
                echo "<th class='text-end'>" . __('Items completed', 'sprint') . "</th>";
                echo "</tr></thead><tbody>";
                foreach ($rows as $row) {
                    $pct   = $row['pts_planned'] > 0 ? (int)round(100 * $row['pts_done'] / $row['pts_planned']) : 0;
                    $width = number_format(min(100, $pct), 1, '.', '');
                    $bar   = $pct >= 85 ? 'green' : ($pct >= 60 ? 'yellow' : 'red');
                    $color = htmlescape($row['color']);

                    $query = $_GET;
                    $query['section'] = self::SECTION_OVERVIEW;
                    $query['category_id'] = $row['id'];
                    $url = $row['id'] > 0
                        ? Plugin::getWebDir('sprint') . '/front/sprint.php?' . http_build_query($query)
                        : '';

                    $indent = $row['level'] > 0
                        ? "<i class='fas fa-turn-up fa-rotate-90 text-muted me-1' style='margin-left:18px;font-size:0.8em;' aria-hidden='true'></i>"
                        : '';
                    $label = htmlescape($row['label']);
                    if ($url !== '') {
                        $label = "<a href='" . htmlescape($url) . "'>" . $label . "</a>";
                    }
                    if ($row['level'] === 0) {
                        $label = "<strong>" . $label . "</strong>";
                    }

                    echo "<tr>";
                    echo "<td>{$indent}<span style='display:inline-block;width:10px;height:10px;border-radius:3px;background:{$color};margin-right:6px;'></span>{$label}</td>";
                    echo "<td><div class='d-flex align-items-center gap-2'>"
                        . "<div class='progress progress-sm flex-grow-1'><div class='progress-bar bg-{$bar}' role='progressbar' "
                        . "style='width:{$width}%' aria-valuenow='{$width}' aria-valuemin='0' aria-valuemax='100'></div></div>"
                        . "<span class='text-nowrap sprint-small'>{$pct}%</span>"
                        . "</div></td>";
                    echo "<td class='text-end'>" . (int)$row['pts_done'] . "</td>";
                    echo "<td class='text-end'>" . (int)$row['pts_planned'] . "</td>";
                    echo "<td class='text-end text-nowrap'>" . (int)$row['items_done'] . " / " . (int)$row['items_total'] . "</td>";
                    echo "</tr>";
                }
                echo "</tbody></table></div>";
            }
        );
    }

    /**
     * Where the time went per member: the capacity each member spent per
     * backlog category over the completed sprints in the period, next to the
     * points and items they finished. Subcategories roll up into their parent.
     */
    private static function renderMemberDelivery(array $data): void
    {
        $per = $data['per_member_category'] ?? [];
        if (!$per) {
            return;
        }
        self::renderCard('member-delivery',
            __('Delivered per member', 'sprint'),
            'fas fa-user-clock',
            __('Where each member\'s capacity went per backlog category across the completed sprints in this period. A parent category includes its subcategories. Fastlane and dependency work counts on every member allocated to it.', 'sprint'),
            function () use ($per) {
                $cats  = SprintCategory::getAll(false);
                $topOf = [];
                foreach ($cats as $cid => $cat) {
                    $cid = (int)$cid;
                    $pid = (int)($cat['plugin_sprint_sprintcategories_id'] ?? 0);
                    $topOf[$cid] = ((int)($cat['level'] ?? 0) > 0 && $pid > 0 && isset($cats[$pid]))
                        ? ($topOf[$pid] ?? $pid)
                        : $cid;
                }
                $capKey  = 'cap_planned';
                $noCat   = __('No category', 'sprint');
                $nameOf  = static fn(int $cid) => $cid > 0 ? (string)($cats[$cid]['name'] ?? $noCat) : $noCat;
                $colorOf = static fn(int $cid) => $cid > 0 ? (string)($cats[$cid]['color'] ?? '#6c757d') : '#6c757d';

                // Per member: capacity rolled up to top level, with the
                // subcategory split kept for the tooltip.
                $rows = [];
                $teamTop = $teamSub = [];
                $teamTotal = 0.0;
                foreach ($per as $uid => $byCat) {
                    $top = $sub = [];
                    $total = 0.0;
                    $ptsDone = $ptsPlanned = $itemsDone = $itemsTotal = 0;
                    foreach ($byCat as $cid => $bucket) {
                        $cid = (int)$cid;
                        $t   = ($cid > 0 && isset($cats[$cid])) ? ($topOf[$cid] ?? $cid) : 0;
                        $cap = (float)$bucket[$capKey];
                        if ($cap > 0) {
                            $top[$t]     = ($top[$t] ?? 0) + $cap;
                            $teamTop[$t] = ($teamTop[$t] ?? 0) + $cap;
                            $total      += $cap;
                            $teamTotal  += $cap;
                            if ($t !== $cid) {
                                $sub[$t][$cid]     = ($sub[$t][$cid] ?? 0) + $cap;
                                $teamSub[$t][$cid] = ($teamSub[$t][$cid] ?? 0) + $cap;
                            }
                        }
                        $ptsDone    += (int)$bucket['pts_done'];
                        $ptsPlanned += (int)$bucket['pts_planned'];
                        $itemsDone  += (int)$bucket['items_done'];
                        $itemsTotal += (int)$bucket['items_total'];
                    }
                    if ($total <= 0 && $itemsTotal === 0) {
                        continue;
                    }
                    arsort($top);
                    $rows[] = [
                        'label' => SprintCache::userName((int)$uid),
                        'top' => $top, 'sub' => $sub, 'total' => $total,
                        'pts_done' => $ptsDone, 'pts_planned' => $ptsPlanned,
                        'items_done' => $itemsDone, 'items_total' => $itemsTotal,
                    ];
                }
                if (!$rows) {
                    echo "<div class='text-muted py-3'>" . __('No data for this period.', 'sprint') . "</div>";
                    return;
                }
                usort($rows, static fn($a, $b) => [$b['total'], $b['pts_done']] <=> [$a['total'], $a['pts_done']]);
                arsort($teamTop);

                /** Stacked bar over the categories plus the readable shares under it. */
                $distribution = static function (array $top, array $sub, float $total) use ($nameOf, $colorOf): string {
                    if ($total <= 0) {
                        return "<span class='text-muted'>" . __('No capacity recorded', 'sprint') . "</span>";
                    }
                    $bar = "<div class='progress progress-sm' style='height:10px;'>";
                    $chips = [];
                    foreach ($top as $cid => $cap) {
                        $cid   = (int)$cid;
                        $pct   = 100 * $cap / $total;
                        $width = number_format($pct, 1, '.', '');
                        $tip   = $nameOf($cid) . ': ' . round($pct) . '% (' . SprintMember::formatCapacity($cap) . '%)';
                        foreach ($sub[$cid] ?? [] as $scid => $scap) {
                            $tip .= "\n" . $nameOf((int)$scid) . ': ' . round(100 * $scap / $total) . '%';
                        }
                        $bar .= "<div class='progress-bar' role='progressbar' style='width:{$width}%;background:"
                            . htmlescape($colorOf($cid)) . ";' title='" . htmlescape($tip) . "' "
                            . "aria-valuenow='{$width}' aria-valuemin='0' aria-valuemax='100'></div>";
                        if ($pct >= 1) {
                            $chips[] = "<span class='text-nowrap'><span style='display:inline-block;width:8px;height:8px;border-radius:2px;background:"
                                . htmlescape($colorOf($cid)) . ";margin-right:4px;'></span>"
                                . htmlescape($nameOf($cid)) . ' ' . round($pct) . '%</span>';
                        }
                    }
                    $bar .= "</div>";
                    return $bar . "<div class='text-muted sprint-small mt-1 d-flex flex-wrap' style='gap:2px 12px;'>"
                        . implode('', $chips) . "</div>";
                };

                echo "<div class='table-responsive'><table class='table table-vcenter mb-0'>";
                echo "<thead><tr>";
                echo "<th>" . __('Member', 'sprint') . "</th>";
                echo "<th style='width:40%;'>" . __('Where the capacity went', 'sprint') . "</th>";
                echo "<th class='text-end'>" . __('Share of the team', 'sprint') . "</th>";
                echo "<th class='text-end'>" . __('Completed points', 'sprint') . "</th>";
                echo "<th class='text-end'>" . __('Items completed', 'sprint') . "</th>";
                echo "</tr></thead><tbody>";
                foreach ($rows as $row) {
                    $share = $teamTotal > 0 ? (int)round(100 * $row['total'] / $teamTotal) : 0;
                    echo "<tr>";
                    echo "<td><i class='fas fa-user text-muted me-2' style='font-size:0.85em;' aria-hidden='true'></i>" . htmlescape($row['label']) . "</td>";
                    echo "<td>" . $distribution($row['top'], $row['sub'], $row['total']) . "</td>";
                    echo "<td class='text-end'>{$share}%</td>";
                    echo "<td class='text-end'>" . (int)$row['pts_done'] . " / " . (int)$row['pts_planned'] . "</td>";
                    echo "<td class='text-end text-nowrap'>" . (int)$row['items_done'] . " / " . (int)$row['items_total'] . "</td>";
                    echo "</tr>";
                }
                $teamPtsDone = array_sum(array_column($rows, 'pts_done'));
                $teamPtsPlan = array_sum(array_column($rows, 'pts_planned'));
                $teamDone    = array_sum(array_column($rows, 'items_done'));
                $teamItems   = array_sum(array_column($rows, 'items_total'));
                echo "<tr class='fw-bold' style='border-top:2px solid var(--tblr-border-color,#e2e8f0);'>";
                echo "<td>" . __('Team', 'sprint') . "</td>";
                echo "<td>" . $distribution($teamTop, $teamSub, $teamTotal) . "</td>";
                echo "<td class='text-end'>100%</td>";
                echo "<td class='text-end'>" . (int)$teamPtsDone . " / " . (int)$teamPtsPlan . "</td>";
                echo "<td class='text-end text-nowrap'>" . (int)$teamDone . " / " . (int)$teamItems . "</td>";
                echo "</tr>";
                echo "</tbody></table></div>";
            }
        );
    }

    /** Tone class + glyph for a load drift verdict; the glyph is what carries it under CVD. */
    private static function driftStyle(string $direction): array
    {
        return match ($direction) {
            'improving' => ['is-good', '↓', __('Improving', 'sprint')],
            'worsening' => ['is-bad', '↑', __('Worsening', 'sprint')],
            default     => ['is-flat', '→', __('Stable', 'sprint')],
        };
    }

    private static function formatDrift(float $drift): string
    {
        return ($drift > 0 ? '+' : '') . number_format($drift, 1, ',', '') . ' %';
    }

    /** Team average load per sprint, against the 100% capacity line. */
    private static function renderWorkloadTrend(array $data): void
    {
        $trend = $data['workload_trend'] ?? [];
        self::renderCard('workload-trend',
            __('Workload trend', 'sprint'),
            'fas fa-chart-line',
            __('Average allocated load across the members of each sprint. The dashed line is 100% capacity — above it the team is booked beyond availability.', 'sprint'),
            function () use ($trend) {
                $labels = $trend['labels'] ?? [];
                $team   = $trend['team'] ?? [];
                if (count(array_filter($team, static fn($v) => $v !== null)) < 2) {
                    echo SprintChart::emptyState(
                        'fas fa-chart-line',
                        __('Not enough sprints in this period to show a trend.', 'sprint'),
                        __('A trend needs at least two completed sprints with team members. Choose a longer period.', 'sprint')
                    );
                    return;
                }

                // Same window as the other charts, so the x-axis lines up.
                $labels = array_slice($labels, -self::CHART_SPRINTS);
                $team   = array_slice($team, -self::CHART_SPRINTS);
                $over   = array_slice($trend['over'] ?? [], -self::CHART_SPRINTS);

                [$tone, $glyph, $verdict] = self::driftStyle((string)($trend['direction'] ?? 'stable'));
                echo "<div class='d-flex align-items-baseline gap-2 mb-3'>";
                echo "<span class='sprint-drift-glyph sprint-delta " . $tone . "' aria-hidden='true'>" . $glyph . "</span>";
                echo "<span class='sprint-drift-value'>" . htmlescape(self::formatDrift((float)($trend['drift'] ?? 0))) . "</span>";
                echo "<span class='text-muted'>" . htmlescape(sprintf(
                    __('%1$s — modelled change in team load over this period (%2$s)', 'sprint'),
                    $verdict,
                    sprintf(_n('%d member', '%d members', (int)($trend['members'] ?? 0), 'sprint'), (int)($trend['members'] ?? 0))
                )) . "</span>";
                echo "</div>";

                self::renderLoadLine($labels, $team, $over);
            }
        );
    }

    /** Team load per sprint as one line; $over feeds the per-sprint tooltip. */
    private static function renderLoadLine(array $labels, array $values, array $over = []): void
    {
        $labels = array_values($labels);
        $values = array_values($values);
        $over   = array_values($over);
        $n      = count($labels);
        if ($n === 0) {
            echo self::chartEmptyState('fas fa-chart-line');
            return;
        }

        $num   = [SprintChart::class, 'num'];
        $w     = 900;
        $padL  = 52;
        $padR  = 24;
        $padT  = 22;
        $slot  = ($w - $padL - $padR) / $n;
        $axis  = SprintChart::axisLabels($labels, $slot);
        $yB    = $padT + 200;
        $h     = (int)ceil($yB + $axis['height'] + 8);
        $xAt   = static fn(int $i): float => $padL + $slot * ($i + 0.5);
        $color = SprintChart::seriesColor(0);
        $pct   = static fn(float $v): string => number_format($v, 1, ',', '') . '%';

        // A % axis stays on multiples of 25, so 100% sits on a round tick.
        $filled = array_map('floatval', array_filter($values, static fn($v) => $v !== null));
        $peak   = max(100.0, $filled ? max($filled) : 0.0);
        $step   = $peak <= 150 ? 25.0 : ($peak <= 300 ? 50.0 : 100.0);
        $scale  = ['max' => ceil($peak / $step) * $step, 'step' => $step, 'ticks' => []];
        for ($tick = 0.0; $tick <= $scale['max']; $tick += $step) {
            $scale['ticks'][] = $tick;
        }
        $yAt    = static fn(float $v): float => SprintChart::yAt($v, $scale, $padT, $yB);

        $points = [];
        foreach ($values as $i => $value) {
            if ($value !== null && $i < $n) {
                $points[$i] = ['x' => $xAt($i), 'y' => $yAt((float)$value), 'v' => (float)$value];
            }
        }
        $first = array_key_first($points);
        $last  = array_key_last($points);

        echo "<div data-chart-scope>";
        echo "<div class='sprint-chart-box'>";
        echo SprintChart::svgOpen($last === null
            ? __('Average team load per sprint.', 'sprint')
            : sprintf(
                __('Average team load per sprint. Latest sprint %1$s: %2$s.', 'sprint'),
                $labels[$last],
                $pct($points[$last]['v'])
            ), $w, $h, 'sprint-overview-chart');
        echo SprintChart::yAxis($scale, $padL, $w - $padR, $padT, $yB, static fn(float $v): string => (int)round($v) . '%');
        for ($i = 0; $i < $n; $i++) {
            echo SprintChart::axisLabel($axis, $i, $xAt($i), $yB);
        }

        $refY = $num($yAt(100.0));
        echo "<line class='sprint-chart-limit' x1='{$padL}' y1='{$refY}' x2='" . ($w - $padR) . "' y2='{$refY}'/>";

        // A sprint without a load breaks the line instead of bridging the gap.
        $path = '';
        $prev = null;
        foreach ($points as $i => $point) {
            $path .= ($prev === null || $prev !== $i - 1 ? 'M' : 'L') . $num($point['x']) . ',' . $num($point['y']) . ' ';
            $prev  = $i;
        }
        echo "<g class='sprint-chart-series' data-series='load'>";
        echo "<path class='sprint-chart-line' d='" . trim($path) . "' stroke='" . htmlescape($color) . "'/>";
        foreach ($points as $i => $point) {
            echo SprintChart::marker(0, $point['x'], $point['y'], 4.0, "class='sprint-chart-point' fill='" . htmlescape($color) . "'");
            // Direct-label the ends only: a number on every point is noise.
            if ($i === $first || $i === $last) {
                echo "<text class='sprint-chart-value' x='" . $num($point['x']) . "' y='" . $num(max($point['y'] - 9, $padT + 9))
                    . "' text-anchor='middle'>" . (int)round($point['v']) . "%</text>";
            }
        }
        echo "</g>";

        $tableRows = [];
        for ($i = 0; $i < $n; $i++) {
            $overCount = (int)($over[$i] ?? 0);
            $rows      = isset($points[$i])
                ? [[__('Average team load', 'sprint'), $pct($points[$i]['v']), $color]]
                : [[__('No data for this period.', 'sprint'), '']];
            if ($overCount > 0) {
                $rows[] = [sprintf(_n('%d member over capacity', '%d members over capacity', $overCount, 'sprint'), $overCount), ''];
            }
            echo self::chartSlot($padL + $slot * $i, $slot, $padT, $yB, $labels[$i], $rows);
            $tableRows[] = [$labels[$i], isset($points[$i]) ? $pct($points[$i]['v']) : '—', (string)$overCount];
        }
        echo "</svg></div>";

        echo SprintChart::legend([
            ['label' => __('Average team load', 'sprint'), 'color' => $color, 'mark' => 'line', 'shape' => 0],
            ['label' => __('100% capacity', 'sprint'), 'color' => 'var(--sprint-fg-danger, #dc3545)', 'mark' => 'ref'],
        ], false);
        echo self::chartTable(
            __('Average team load', 'sprint'),
            [Sprint::getTypeName(1), __('Average team load', 'sprint'), __('Over capacity', 'sprint')],
            $tableRows
        );
        echo "</div>";
    }

    /**
     * Inline sparkline: one line with a dot on its last value and an optional
     * dashed reference. Decorative (aria-hidden): the figure next to it carries
     * the value. Without $yMax the line fills its own range, with $yMax it
     * runs from 0 so several sparklines share one scale.
     *
     * @param array<int,float|int|null> $values a null breaks the line
     */
    private static function sparkline(array $values, ?float $yMax = null, ?float $reference = null): string
    {
        $values = array_values($values);
        $n      = count($values);
        $filled = array_map('floatval', array_filter($values, static fn($v) => $v !== null));
        if ($n < 2 || count($filled) < 2) {
            return '';
        }

        $w    = 96;
        $h    = 26;
        $pad  = 3;
        $num  = [SprintChart::class, 'num'];
        $low  = $yMax !== null ? 0.0 : min($filled);
        $high = $yMax !== null ? max($yMax, 0.0001) : max($filled);
        if ($high - $low < 0.0001) {
            // A flat run draws through the middle, not along an edge.
            $low  -= 1;
            $high += 1;
        }
        $step  = $w / ($n - 1);
        $yAt   = static fn(float $v): float => $pad + ($h - 2 * $pad) * (1 - (max($low, min($v, $high)) - $low) / ($high - $low));
        $color = htmlescape(SprintChart::seriesColor(0));

        $out = "<svg class='sprint-spark' viewBox='0 0 {$w} {$h}' width='{$w}' height='{$h}' aria-hidden='true' focusable='false'>";
        if ($reference !== null && $reference >= $low && $reference <= $high) {
            $refY = $num($yAt($reference));
            $out .= "<line class='sprint-chart-limit' x1='0' y1='{$refY}' x2='{$w}' y2='{$refY}'/>";
        }
        $path = '';
        $prev = null;
        $end  = null;
        foreach ($values as $i => $value) {
            if ($value === null) {
                continue;
            }
            $x     = $i * $step;
            $y     = $yAt((float)$value);
            $path .= ($prev === null || $prev !== $i - 1 ? 'M' : 'L') . $num($x) . ',' . $num($y) . ' ';
            $prev  = $i;
            $end   = [$x, $y];
        }
        $out .= "<path class='sprint-chart-line sprint-spark-line' d='" . trim($path) . "' stroke='{$color}'/>";
        if ($end !== null) {
            $out .= "<circle cx='" . $num($end[0]) . "' cy='" . $num($end[1]) . "' r='2.2' fill='{$color}'/>";
        }
        return $out . "</svg>";
    }

    /** Inline sparkline for one member's per-sprint load, with the 100% line. */
    private static function renderLoadSparkline(array $values, float $yMax): void
    {
        $spark = self::sparkline($values, $yMax, 100.0);
        echo $spark !== '' ? $spark : "<span class='text-muted'>&mdash;</span>";
    }

    /** Allocated capacity per member, with the overflow highlighted. */
    private static function renderWorkload(array $data): void
    {
        self::renderCard('workload',
            __('Workload per member', 'sprint'),
            'fas fa-gauge',
            __('Allocated capacity (regular, fastlane and dependencies) against availability, averaged over the sprints each member took part in.', 'sprint'),
            function () use ($data) {
                if (empty($data['workload'])) {
                    echo "<div class='text-muted py-3'>" . __('No data for this period.', 'sprint') . "</div>";
                    return;
                }

                // One shared scale, or a light member's sparkline would look as
                // steep as an overloaded one.
                $sparkMax = 100.0;
                foreach ($data['workload'] as $row) {
                    foreach ($row['loads'] ?? [] as $load) {
                        $sparkMax = max($sparkMax, (float)$load);
                    }
                }

                echo "<div class='table-responsive'>";
                echo "<table class='table table-vcenter mb-0'>";
                echo "<thead><tr>";
                echo "<th>" . __('Member', 'sprint') . "</th>";
                echo "<th>" . _n('Sprint', 'Sprints', 2, 'sprint') . "</th>";
                echo "<th style='width:28%;'>" . __('Avg load', 'sprint') . "</th>";
                echo "<th>" . __('Trend', 'sprint') . "</th>";
                echo "<th class='text-end'>" . __('Peak load', 'sprint') . "</th>";
                echo "<th class='text-end'>" . __('Sprints over capacity', 'sprint') . "</th>";
                echo "</tr></thead><tbody>";
                foreach ($data['workload'] as $row) {
                    $color = $row['avg'] > 100 ? 'red' : ($row['avg'] > 85 ? 'yellow' : 'green');
                    $width = number_format(min(100, $row['avg']), 1, '.', '');
                    echo "<tr>";
                    echo "<td>" . htmlescape($row['label']) . "</td>";
                    echo "<td>" . (int)$row['sprints'] . "</td>";
                    echo "<td><div class='d-flex align-items-center gap-2'>";
                    echo "<div class='progress progress-sm flex-grow-1'><div class='progress-bar bg-" . $color . "' "
                        . "role='progressbar' style='width:{$width}%' aria-valuenow='{$width}' aria-valuemin='0' aria-valuemax='100'></div></div>";
                    echo "<span class='text-nowrap'>" . (int)$row['avg'] . "%</span>";
                    echo "</div></td>";

                    [$driftTone, $driftGlyph, $driftLabel] = self::driftStyle((string)($row['direction'] ?? 'stable'));
                    echo "<td><div class='d-flex align-items-center gap-2'>";
                    self::renderLoadSparkline($row['loads'] ?? [], $sparkMax);
                    echo "<span class='text-nowrap' title='" . htmlescape($driftLabel) . "'>"
                        . "<span class='fw-bold sprint-delta " . $driftTone . "' aria-hidden='true'>" . $driftGlyph . "</span> "
                        . "<span class='text-muted'>" . htmlescape(self::formatDrift((float)($row['drift'] ?? 0))) . "</span>"
                        . "</span>";
                    echo "</div></td>";

                    echo "<td class='text-end'>" . (int)$row['peak'] . "%</td>";
                    echo "<td class='text-end'>"
                        . ($row['over'] > 0
                            ? "<span class='badge bg-red-lt'>" . (int)$row['over'] . "</span>"
                            : "<span class='text-muted'>0</span>")
                        . "</td>";
                    echo "</tr>";
                }
                echo "</tbody></table></div>";
            }
        );
    }

    /** Keep the charts to the most recent sprints; `dropped` counts the rest. */
    private static function trimToChartWindow(array $data): array
    {
        $data['dropped'] = max(0, count($data['labels']) - self::CHART_SPRINTS);
        if ($data['dropped'] === 0) {
            return $data;
        }

        $data['labels'] = array_slice($data['labels'], -self::CHART_SPRINTS);
        $data['links'] = array_slice($data['links'] ?? [], -self::CHART_SPRINTS);
        $data['sprints'] = array_slice($data['sprints'] ?? [], -self::CHART_SPRINTS);
        foreach ($data['series'] as $key => $values) {
            $data['series'][$key] = array_slice($values, -self::CHART_SPRINTS);
        }
        return $data;
    }

    private static function renderPeriodSelector(string $period, array $filters = []): void
    {
        $self = Plugin::getWebDir('sprint') . '/front/sprint.php';
        echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'>";
        echo "<span class='text-muted'>" . __('Period', 'sprint') . ":</span>";
        echo "<div class='btn-group' role='group' aria-label='" . __s('Period', 'sprint') . "'>";
        foreach (self::getPeriods() as $key => $label) {
            $class = 'btn btn-sm ' . ($key === $period ? 'btn-primary' : 'btn-outline-secondary');
            $url   = $self . '?' . http_build_query(array_merge(
                ['section' => self::SECTION_OVERVIEW, 'period' => $key],
                array_filter($filters, static fn($value) => $value !== '' && $value !== 0)
            ));
            echo "<a class='" . $class . "' href='" . htmlescape($url) . "'" . ($key === $period ? " aria-current='true'" : '') . ">" . htmlescape($label) . "</a>";
        }
        echo "</div></div>";
    }

    private static function filtersFromRequest(): array
    {
        $text = static fn(string $key) => trim((string)($_GET[$key] ?? ''));
        $int  = static fn(string $key) => max(0, (int)($_GET[$key] ?? 0));
        return [
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $text('from')) ? $text('from') : '',
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $text('to')) ? $text('to') : '',
            'entities_id' => $int('entities_id'),
            'scrum_master' => $int('scrum_master'),
            'member_id' => $int('member_id'),
            'projects_id' => $int('projects_id'),
            'template_id' => $int('template_id'),
            'epic_id' => $int('epic_id'),
            'category_id' => $int('category_id'),
            'itemtype' => in_array($text('itemtype'), ['', 'Ticket', 'Change', 'Problem', 'ProjectTask'], true) ? $text('itemtype') : '',
            'tag' => mb_substr($text('tag'), 0, 255),
            'blocked_only' => $int('blocked_only') > 0 ? 1 : 0,
            'over_only' => $int('over_only') > 0 ? 1 : 0,
            'predictability_below' => min(100, $int('predictability_below')),
            'compare_period' => array_key_exists($text('compare_period'), self::getPeriods()) ? $text('compare_period') : '',
            'compare_scrum_master' => $int('compare_scrum_master'),
            'view' => $text('view') === 'compact' ? 'compact' : 'expanded',
        ];
    }

    /** Filter keys that narrow the set of completed sprints (dates, view and comparison excluded). */
    private static function narrowingFilterKeys(array $filters): array
    {
        $keys = [];
        foreach ($filters as $key => $value) {
            if (in_array($key, ['from', 'to', 'view', 'compare_period', 'compare_scrum_master'], true)) {
                continue;
            }
            if ($value !== '' && $value !== 0) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /** Overview URL for the given period and filters (empty values dropped). */
    private static function overviewUrl(string $period, array $filters): string
    {
        return Plugin::getWebDir('sprint') . '/front/sprint.php?' . http_build_query(array_merge(
            ['section' => self::SECTION_OVERVIEW, 'period' => $period],
            array_filter($filters, static fn($value) => $value !== '' && $value !== 0 && $value !== 'expanded')
        ));
    }

    /** Completed sprints in the date window (period, or the from/to dates) the user can see, before any other filter. */
    private static function countCompletedInWindow(string $period, array $filters): int
    {
        [$from, $to] = self::periodRange($period, false);
        if (!empty($filters['from']) || !empty($filters['to'])) {
            $from = $filters['from'] ?: null;
            $to   = $filters['to'] ?: null;
        }
        $criteria = ['status' => Sprint::STATUS_COMPLETED];
        if ($from !== null) {
            $criteria[] = ['date_end' => ['>=', $from]];
        }
        if ($to !== null) {
            $criteria[] = ['date_end' => ['<=', $to]];
        }
        $criteria = array_merge($criteria, getEntitiesRestrictCriteria(Sprint::getTable(), '', '', true));
        return countElementsInTable(Sprint::getTable(), $criteria);
    }

    /**
     * Distinct empty states: no access, nothing completed yet, nothing in
     * this period, or everything hidden by the filters — each with the
     * matching next step.
     */
    private static function renderEmptyState(string $period, array $filters, int $inWindow): void
    {
        $icon = static fn(string $i) => "<i class='" . $i . " me-2' aria-hidden='true'></i>";
        if (!Sprint::canView()) {
            echo "<div class='alert alert-warning sprint-overview-empty'>" . $icon('fas fa-lock')
                . __s('You do not have access to sprint statistics.', 'sprint') . "</div>";
            return;
        }
        $narrowing = self::narrowingFilterKeys($filters);
        if ($inWindow > 0 && $narrowing) {
            echo "<div class='alert alert-info sprint-overview-empty'>" . $icon('fas fa-filter')
                . htmlescape(sprintf(
                    _n(
                        'The %d completed sprint in this period does not match the filters.',
                        'None of the %d completed sprints in this period match the filters.',
                        $inWindow,
                        'sprint'
                    ),
                    $inWindow
                ))
                . " <a class='alert-link sprint-overview-reset' href='" . htmlescape(self::overviewUrl($period, [])) . "'>" . __s('Reset view', 'sprint') . "</a></div>";
            return;
        }
        $everCompleted = countElementsInTable(Sprint::getTable(), array_merge(
            ['status' => Sprint::STATUS_COMPLETED],
            getEntitiesRestrictCriteria(Sprint::getTable(), '', '', true)
        ));
        if ($everCompleted === 0) {
            echo "<div class='alert alert-info sprint-overview-empty'>" . $icon('fas fa-seedling')
                . __s('No sprint has been completed yet. The statistics appear here once the first sprint is completed.', 'sprint');
            $anySprint = countElementsInTable(Sprint::getTable(), getEntitiesRestrictCriteria(Sprint::getTable(), '', '', true)) > 0;
            if (!$anySprint && Sprint::canCreate()) {
                echo " <a class='alert-link' href='" . htmlescape(Sprint::getFormURL()) . "'>" . __s('Create a sprint', 'sprint') . "</a>";
            } else {
                echo " <a class='alert-link' href='" . htmlescape(Plugin::getWebDir('sprint') . '/front/sprint.php?section=' . self::SECTION_LIST) . "'>" . __s('Open the sprint list', 'sprint') . "</a>";
            }
            echo "</div>";
            return;
        }
        echo "<div class='alert alert-info sprint-overview-empty'>" . $icon('fas fa-calendar-xmark')
            . __s('No completed sprints in this period.', 'sprint');
        if ($period !== self::PERIOD_ALL || !empty($filters['from']) || !empty($filters['to'])) {
            $wider = $filters;
            $wider['from'] = '';
            $wider['to']   = '';
            echo " <a class='alert-link' href='" . htmlescape(self::overviewUrl(self::PERIOD_ALL, $wider)) . "'>" . __s('Show all time', 'sprint') . "</a>";
        }
        echo "</div>";
    }

    /**
     * Filter form (collapsed unless filters are active), plus an always
     * visible row with removable filter chips, the "12 of 48" result label
     * and the single reset.
     */
    private static function renderFilterBar(array $filters, string $period, int $shown, int $inWindow): void
    {
        global $DB;
        $self = Plugin::getWebDir('sprint') . '/front/sprint.php';
        $sprints = array_values((new Sprint())->find(getEntitiesRestrictCriteria(Sprint::getTable(), '', '', true), ['name ASC']));
        $masters = $members = $projects = $templates = $entities = [];
        $sprintIds = [];
        foreach ($sprints as $sprint) {
            $sprintIds[] = (int)$sprint['id'];
            $uid = (int)$sprint['users_id'];
            if ($uid > 0) $masters[$uid] = SprintCache::userName($uid);
            $pid = (int)$sprint['projects_id'];
            if ($pid > 0) $projects[$pid] = Dropdown::getDropdownName('glpi_projects', $pid);
            $tid = (int)($sprint['plugin_sprint_sprinttemplates_id'] ?? 0);
            if ($tid > 0) $templates[$tid] = Dropdown::getDropdownName(SprintTemplate::getTable(), $tid);
            $eid = (int)$sprint['entities_id'];
            $entities[$eid] = Dropdown::getDropdownName('glpi_entities', $eid);
        }
        if ($sprintIds) {
            foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => SprintMember::getTable(), 'WHERE' => ['plugin_sprint_sprints_id' => $sprintIds]]) as $row) {
                $uid = (int)$row['users_id'];
                if ($uid > 0) $members[$uid] = SprintCache::userName($uid);
            }
        }
        natcasesort($masters);
        natcasesort($members);
        natcasesort($projects);
        natcasesort($templates);
        natcasesort($entities);
        $tags = Config::getDefinedTags();
        $epics = [];
        if ($DB->tableExists('glpi_plugin_sprint_sprintepics')) {
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_plugin_sprint_sprintepics', 'ORDER' => ['name ASC']]) as $row) $epics[(int)$row['id']] = (string)$row['name'];
        }
        // Include deactivated categories: completed sprints may still carry them.
        $categories = [];
        foreach (SprintCategory::getAll(false) as $cid => $cat) {
            $categories[(int)$cid] = (((int)($cat['level'] ?? 0) > 0) ? '— ' : '') . (string)$cat['name'];
        }

        $select = static function (string $name, array $options, $selected, string $empty): string {
            $html = "<select class='form-select form-select-sm' id='sprint-ovf-" . htmlescape($name) . "' name='" . htmlescape($name) . "'><option value=''>" . htmlescape($empty) . '</option>';
            foreach ($options as $value => $label) $html .= "<option value='" . htmlescape((string)$value) . "'" . ((string)$selected === (string)$value ? ' selected' : '') . '>' . htmlescape((string)$label) . '</option>';
            return $html . '</select>';
        };

        $narrowing = self::narrowingFilterKeys($filters);
        $open = array_filter($filters, static fn($v, $k) => $k !== 'view' && $v !== '' && $v !== 0, ARRAY_FILTER_USE_BOTH);
        echo "<details class='card mb-2 sprint-overview-filters'" . ($open ? ' open' : '') . "><summary class='card-header cursor-pointer'><strong><i class='fas fa-filter me-2' aria-hidden='true'></i>" . __('Filters and comparison', 'sprint') . "</strong>"
            . ($narrowing ? " <span class='sprint-chip sprint-chip-info ms-2'>" . count($narrowing) . "</span>" : '') . "</summary><div class='card-body'>";
        echo "<form method='get' action='" . htmlescape($self) . "' class='row g-2'>" . Html::hidden('section', ['value' => self::SECTION_OVERVIEW]) . Html::hidden('period', ['value' => (string)($_GET['period'] ?? self::PERIOD_YEAR)]);
        echo "<div class='col-md-2'><label class='form-label' for='sprint-ovf-from'>" . __('From') . "</label><input class='form-control form-control-sm' type='date' id='sprint-ovf-from' name='from' value='" . htmlescape($filters['from']) . "'></div>";
        echo "<div class='col-md-2'><label class='form-label' for='sprint-ovf-to'>" . __('To') . "</label><input class='form-control form-control-sm' type='date' id='sprint-ovf-to' name='to' value='" . htmlescape($filters['to']) . "'></div>";
        echo "<div class='col-md-2'><label class='form-label' for='sprint-ovf-entities_id'>" . __('Entity') . "</label>" . $select('entities_id', $entities, $filters['entities_id'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label' for='sprint-ovf-scrum_master'>" . __('Scrum Master', 'sprint') . "</label>" . $select('scrum_master', $masters, $filters['scrum_master'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label' for='sprint-ovf-member_id'>" . __('Team member', 'sprint') . "</label>" . $select('member_id', $members, $filters['member_id'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label' for='sprint-ovf-projects_id'>" . __('Project') . "</label>" . $select('projects_id', $projects, $filters['projects_id'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label' for='sprint-ovf-template_id'>" . __('Template', 'sprint') . "</label>" . $select('template_id', $templates, $filters['template_id'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label' for='sprint-ovf-epic_id'>" . __('Epic or theme', 'sprint') . "</label>" . $select('epic_id', $epics, $filters['epic_id'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label' for='sprint-ovf-category_id'>" . __('Category', 'sprint') . "</label>" . $select('category_id', $categories, $filters['category_id'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label' for='sprint-ovf-itemtype'>" . __('Linked type', 'sprint') . "</label>" . $select('itemtype', array_combine(['Ticket', 'Change', 'Problem', 'ProjectTask'], ['Ticket', 'Change', 'Problem', 'Project task']), $filters['itemtype'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label' for='sprint-ovf-tag'>" . __('Tag', 'sprint') . "</label>" . $select('tag', array_combine($tags, $tags), $filters['tag'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label' for='sprint-ovf-compare_period'>" . __('Compare period', 'sprint') . "</label>" . $select('compare_period', self::getPeriods(), $filters['compare_period'], __('None', 'sprint')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label' for='sprint-ovf-compare_scrum_master'>" . __('Compare Scrum Master', 'sprint') . "</label>" . $select('compare_scrum_master', $masters, $filters['compare_scrum_master'], __('None', 'sprint')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label' for='sprint-ovf-view'>" . __('View', 'sprint') . "</label>" . $select('view', ['expanded' => __('Expanded', 'sprint'), 'compact' => __('Compact', 'sprint')], $filters['view'], __('Expanded', 'sprint')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label' for='sprint-ovf-predictability_below'>" . __('Predictability below', 'sprint') . "</label><div class='input-group input-group-sm'><input class='form-control' id='sprint-ovf-predictability_below' type='number' min='0' max='100' name='predictability_below' value='" . ($filters['predictability_below'] ?: '') . "'><span class='input-group-text'>%</span></div></div>";
        echo "<div class='col-12 d-flex flex-wrap gap-3 mt-3'><label class='form-check'><input class='form-check-input' type='checkbox' name='blocked_only' value='1'" . ($filters['blocked_only'] ? ' checked' : '') . "><span class='form-check-label'>" . __('Only sprints with blocked work', 'sprint') . "</span></label><label class='form-check'><input class='form-check-input' type='checkbox' name='over_only' value='1'" . ($filters['over_only'] ? ' checked' : '') . "><span class='form-check-label'>" . __('Only sprints over capacity', 'sprint') . "</span></label></div>";
        echo "<div class='col-12 mt-3 d-flex flex-wrap gap-2 align-items-center'><button class='btn btn-primary btn-sm'><i class='fas fa-check me-1' aria-hidden='true'></i>" . __('Apply filters', 'sprint') . "</button>"
            . "<button type='button' class='btn btn-outline-primary btn-sm' id='sprint-save-filter'><i class='fas fa-bookmark me-1' aria-hidden='true'></i>" . __('Save filter', 'sprint') . "</button>"
            . "<select id='sprint-saved-filters' class='form-select form-select-sm' style='width:auto' aria-label='" . __s('Saved filters', 'sprint') . "'><option value=''>" . __('Saved filters', 'sprint') . "</option></select>"
            . "<button type='button' class='btn btn-outline-secondary btn-sm' id='sprint-rename-filter' hidden><i class='fas fa-pen me-1' aria-hidden='true'></i>" . __('Rename', 'sprint') . "</button>"
            . "<button type='button' class='btn btn-outline-danger btn-sm' id='sprint-delete-filter' hidden><i class='fas fa-trash me-1' aria-hidden='true'></i>" . __('Delete') . "</button></div>";
        Html::closeForm();
        echo '</div></details>';

        // --- Active filters as removable chips + result label + reset -------
        $periods = self::getPeriods();
        $display = [
            'from'                 => static fn($v) => Html::convDate((string)$v),
            'to'                   => static fn($v) => Html::convDate((string)$v),
            'entities_id'          => static fn($v) => $entities[(int)$v] ?? (string)$v,
            'scrum_master'         => static fn($v) => $masters[(int)$v] ?? (string)$v,
            'member_id'            => static fn($v) => $members[(int)$v] ?? (string)$v,
            'projects_id'          => static fn($v) => $projects[(int)$v] ?? (string)$v,
            'template_id'          => static fn($v) => $templates[(int)$v] ?? (string)$v,
            'epic_id'              => static fn($v) => $epics[(int)$v] ?? (string)$v,
            'category_id'          => static fn($v) => (string)preg_replace('/^— /u', '', $categories[(int)$v] ?? (string)$v),
            'itemtype'             => static fn($v) => ['Ticket' => __('Ticket'), 'Change' => __('Change'), 'Problem' => __('Problem'), 'ProjectTask' => __('Project task')][$v] ?? (string)$v,
            'tag'                  => static fn($v) => (string)$v,
            'blocked_only'         => null,
            'over_only'            => null,
            'predictability_below' => static fn($v) => (int)$v . '%',
            'compare_period'       => static fn($v) => $periods[$v] ?? (string)$v,
            'compare_scrum_master' => static fn($v) => $masters[(int)$v] ?? (string)$v,
            'view'                 => null,
        ];
        $chipLabels = [
            'from' => __('From'), 'to' => __('To'), 'entities_id' => __('Entity'), 'scrum_master' => __('Scrum Master', 'sprint'),
            'member_id' => __('Team member', 'sprint'), 'projects_id' => __('Project'), 'template_id' => __('Template', 'sprint'),
            'epic_id' => __('Epic or theme', 'sprint'), 'category_id' => __('Category', 'sprint'),
            'itemtype' => __('Linked type', 'sprint'), 'tag' => __('Tag', 'sprint'),
            'blocked_only' => __('Only sprints with blocked work', 'sprint'), 'over_only' => __('Only sprints over capacity', 'sprint'),
            'predictability_below' => __('Predictability below', 'sprint'),
            'compare_period' => __('Compare period', 'sprint'), 'compare_scrum_master' => __('Compare Scrum Master', 'sprint'),
            'view' => __('Compact view', 'sprint'),
        ];
        $chips = [];
        foreach ($chipLabels as $key => $label) {
            $value = $filters[$key] ?? '';
            if ($value === '' || $value === 0 || ($key === 'view' && $value !== 'compact')) {
                continue;
            }
            $text = $display[$key] !== null ? $label . ': ' . ($display[$key])($value) : $label;
            $without = $filters;
            $without[$key] = is_int($value) ? 0 : '';
            $chips[] = "<a class='sprint-chip sprint-chip-info sprint-filter-chip' href='" . htmlescape(self::overviewUrl($period, $without)) . "' "
                . "aria-label='" . htmlescape(sprintf(__('Remove filter: %s', 'sprint'), $text)) . "'>"
                . htmlescape($text) . " <i class='fas fa-xmark ms-1' aria-hidden='true'></i></a>";
        }

        echo "<div class='sprint-overview-resultbar mb-3' aria-live='polite'>";
        echo "<span class='sprint-overview-result'>";
        if ($narrowing) {
            echo htmlescape(sprintf(__('%1$d of %2$d completed sprints', 'sprint'), $shown, $inWindow));
        } else {
            echo htmlescape(sprintf(_n('%d completed sprint', '%d completed sprints', $inWindow, 'sprint'), $inWindow));
        }
        echo " <span class='text-muted'>· " . htmlescape($periods[$period] ?? '') . "</span></span>";
        if ($chips) {
            echo "<span class='sprint-filter-chips'>" . implode('', $chips) . "</span>";
        }
        echo "<a class='btn btn-sm btn-outline-secondary ms-auto sprint-overview-reset' href='" . htmlescape(self::overviewUrl(self::PERIOD_YEAR, [])) . "' "
            . "title='" . __s('Clears all filters and shows every section expanded again', 'sprint') . "'>"
            . "<i class='fas fa-rotate-left me-1' aria-hidden='true'></i>" . __s('Reset view', 'sprint') . "</a>";
        echo "</div>";
    }

    private static function renderLiveSprints(array $filters): void
    {
        global $DB;
        $criteria = ['status' => Sprint::STATUS_ACTIVE];
        $entityCriteria = getEntitiesRestrictCriteria(Sprint::getTable(), '', '', true);
        if ($entityCriteria) $criteria = array_merge($criteria, $entityCriteria);
        if (!empty($filters['entities_id'])) $criteria['entities_id'] = (int)$filters['entities_id'];
        if (!empty($filters['scrum_master'])) $criteria['users_id'] = (int)$filters['scrum_master'];
        if (!empty($filters['projects_id'])) $criteria['projects_id'] = (int)$filters['projects_id'];
        if (!empty($filters['template_id'])) $criteria['plugin_sprint_sprinttemplates_id'] = (int)$filters['template_id'];
        if (!empty($filters['member_id'])) {
            $ids = [];
            foreach ((new SprintMember())->find(['users_id' => (int)$filters['member_id']]) as $member) $ids[] = (int)$member['plugin_sprint_sprints_id'];
            $criteria['id'] = $ids ?: [-1];
        }
        $sprints = array_values((new Sprint())->find($criteria, ['date_end ASC']));
        if (!$sprints) {
            echo "<div class='text-muted sprint-small mb-3'><i class='fas fa-heart-pulse me-1' aria-hidden='true'></i>"
                . __s('No active sprint right now.', 'sprint')
                . " <a href='" . htmlescape(Plugin::getWebDir('sprint') . '/front/sprint.php?section=' . self::SECTION_LIST) . "'>" . __s('Open the sprint list', 'sprint') . "</a></div>";
            return;
        }
        // Items, members and the next meeting for every live sprint in one query each.
        $sprintIds     = array_map(static fn($s) => (int)$s['id'], $sprints);
        $itemsBySprint = [];
        foreach (self::filterItems(self::fetchItems($sprintIds), $filters) as $item) $itemsBySprint[(int)$item['plugin_sprint_sprints_id']][] = $item;
        $membersBySprint = [];
        foreach ((new SprintMember())->find(['plugin_sprint_sprints_id' => $sprintIds]) as $member) $membersBySprint[(int)$member['plugin_sprint_sprints_id']][] = $member;
        $nextMeetings = [];
        foreach ($DB->request(['SELECT' => ['plugin_sprint_sprints_id', 'MIN' => 'date_meeting AS next_meeting'], 'FROM' => SprintMeeting::getTable(), 'WHERE' => ['plugin_sprint_sprints_id' => $sprintIds, ['date_meeting' => ['>=', date('Y-m-d H:i:s')]]], 'GROUPBY' => 'plugin_sprint_sprints_id']) as $meeting) $nextMeetings[(int)$meeting['plugin_sprint_sprints_id']] = (string)$meeting['next_meeting'];

        echo "<div class='d-flex align-items-center justify-content-between mt-3 mb-2'><h3 class='m-0'><i class='fas fa-heart-pulse me-2 text-red' aria-hidden='true'></i>" . __('Live sprint status', 'sprint') . "</h3><span class='text-muted sprint-small'>" . __('Kept separate from completed sprint statistics', 'sprint') . '</span></div>';
        echo "<div class='sprint-live-list mb-4'>";
        foreach ($sprints as $sprint) {
            $sid = (int)$sprint['id'];
            self::renderLiveSprintCard($sprint, $itemsBySprint[$sid] ?? [], $membersBySprint[$sid] ?? [], $nextMeetings[$sid] ?? '');
        }
        echo '</div>';
    }

    /** Workflow statuses in board order with their status colour token (css/sprint.css). */
    private static function liveStatusColors(): array
    {
        return [
            SprintItem::STATUS_DONE        => 'var(--sprint-done, #198754)',
            SprintItem::STATUS_IN_PROGRESS => 'var(--sprint-progress, #0d6efd)',
            SprintItem::STATUS_REVIEW      => 'var(--sprint-review, #6f42c1)',
            SprintItem::STATUS_DEPENDENCY  => 'var(--sprint-dependency, #20c997)',
            SprintItem::STATUS_BLOCKED     => 'var(--sprint-blocked, #dc3545)',
            SprintItem::STATUS_TODO        => 'var(--sprint-todo, #6c757d)',
            SprintItem::STATUS_SCHEDULED   => 'var(--sprint-scheduled, #087990)',
        ];
    }

    /**
     * One full-width card per live sprint: the headline (progress against the
     * time gone), a status mix, a burndown sparkline and the facts that need
     * attention today.
     */
    private static function renderLiveSprintCard(array $sprint, array $items, array $members, string $nextMeeting): void
    {
        $sid    = (int)$sprint['id'];
        $colors = self::liveStatusColors();
        $labels = SprintItem::getAllStatuses();
        $count  = array_fill_keys(array_keys($colors), 0);
        $weight = array_fill_keys(array_keys($colors), 0.0);
        $total = $totalPoints = $donePoints = $blocked = $dependency = $adhoc = $unpointed = 0;
        foreach ($items as $item) {
            $status = (string)$item['status'];
            if (!isset($count[$status])) $status = SprintItem::STATUS_TODO;
            $total++;
            $count[$status]++;
            // Same weighting as the dashboard bar: a heavy item counts more
            // than a trivial one, an unestimated item still gets a slice.
            $weight[$status] += max((float)($item['capacity'] ?? 0), 1.0);
            $points = max(0, (int)$item['story_points']);
            $totalPoints += $points;
            if ($points === 0) $unpointed++;
            if ($status === SprintItem::STATUS_DONE) $donePoints += $points;
            if ($status === SprintItem::STATUS_BLOCKED || (int)$item['is_blocked'] === 1) $blocked++;
            if ($status === SprintItem::STATUS_DEPENDENCY) $dependency++;
            if ((int)($item['is_adhoc'] ?? 0) === 1) $adhoc++;
        }
        $remainingPoints = $totalPoints - $donePoints;
        $limits      = SprintAgility::getWipLimits($sprint);
        $wipBreaches = 0;
        foreach ($limits as $status => $limit) if ($limit > 0 && ($count[$status] ?? 0) > $limit) $wipBreaches++;

        // Time: where the sprint stands on the calendar.
        $now      = time();
        $start    = strtotime((string)$sprint['date_start']) ?: $now;
        $end      = strtotime((string)$sprint['date_end']) ?: $start + DAY_TIMESTAMP;
        if ($end <= $start) $end = $start + DAY_TIMESTAMP;
        $elapsed  = max(0, min(100, (int)round(100 * ($now - $start) / ($end - $start))));
        $daysLeft = (int)ceil(($end - $now) / DAY_TIMESTAMP);
        $progress = $totalPoints > 0
            ? (int)round(100 * $donePoints / $totalPoints)
            : ($total > 0 ? (int)round(100 * $count[SprintItem::STATUS_DONE] / $total) : 0);
        // The ideal line says how much should be done by now; the forecast
        // extrapolates the pace so far to the end of the sprint.
        $expectedNow = (int)round($totalPoints * $elapsed / 100);
        $forecast    = ($elapsed >= 10 && $totalPoints > 0) ? min($totalPoints, (int)round($donePoints * 100 / $elapsed)) : null;
        $gap         = $progress - $elapsed;
        if ($total === 0) {
            $pace = ['label' => __('No work planned', 'sprint'), 'color' => '#6c757d', 'icon' => 'fas fa-circle-minus'];
        } elseif ($progress >= 100) {
            $pace = ['label' => __('All work done', 'sprint'), 'color' => '#198754', 'icon' => 'fas fa-circle-check'];
        } elseif ($gap >= 5) {
            $pace = ['label' => __('Ahead of schedule', 'sprint'), 'color' => '#198754', 'icon' => 'fas fa-arrow-trend-up'];
        } elseif ($gap >= -10) {
            $pace = ['label' => __('On track', 'sprint'), 'color' => '#0d6efd', 'icon' => 'fas fa-check'];
        } elseif ($gap >= -25) {
            $pace = ['label' => __('Behind schedule', 'sprint'), 'color' => '#fd7e14', 'icon' => 'fas fa-triangle-exclamation'];
        } else {
            $pace = ['label' => __('At risk', 'sprint'), 'color' => '#dc3545', 'icon' => 'fas fa-fire'];
        }

        // Team load: allocated capacity against what the members bring.
        $used = SprintMember::usedCapacityBySprint($sid);
        $capTotal = $usedTotal = 0.0;
        $over = 0;
        foreach ($members as $member) {
            $uid = (int)$member['users_id'];
            $cap = SprintAgility::effectiveCapacity($sid, $uid, (float)$member['capacity_percent']);
            $u   = (float)($used[$uid]['total'] ?? 0.0);
            $capTotal  += $cap;
            $usedTotal += $u;
            if ($cap > 0 && $u > $cap) $over++;
        }
        $load = $capTotal > 0 ? (int)round(100 * $usedTotal / $capTotal) : null;

        $url = Sprint::getFormURLWithID($sid);
        $fmtDate = static fn(int $ts) => Html::convDate(date('Y-m-d', $ts));
        echo "<div class='card sprint-live-card' style='--sprint-pace:" . $pace['color'] . ";'><div class='card-body sprint-live-grid'>";

        // --- Headline -------------------------------------------------------
        echo "<div class='sprint-live-head'>";
        echo "<a class='sprint-live-name' href='" . htmlescape($url) . "'>" . htmlescape((string)$sprint['name']) . "</a>";
        echo "<div class='sprint-live-dates text-muted sprint-small'>" . htmlescape($fmtDate($start) . ' – ' . $fmtDate($end)) . " · ";
        if ($daysLeft > 0) {
            echo htmlescape(sprintf(_n('%d day left', '%d days left', $daysLeft, 'sprint'), $daysLeft));
        } elseif ($daysLeft === 0) {
            echo htmlescape(__('Ends today', 'sprint'));
        } else {
            echo "<span class='text-danger'>" . htmlescape(sprintf(_n('%d day overdue', '%d days overdue', -$daysLeft, 'sprint'), -$daysLeft)) . '</span>';
        }
        echo "</div>";
        // Name the unit: story points when the sprint has them, else items.
        $progressLabel = $totalPoints > 0 ? __('Story points delivered', 'sprint') : __('Items done', 'sprint');
        echo "<div class='sprint-live-hero'><span class='sprint-live-hero-value'>{$progress}%</span><span class='sprint-live-hero-label'>" . htmlescape($progressLabel) . "</span></div>";
        echo "<div class='sprint-live-pace'><i class='" . $pace['icon'] . " me-1' aria-hidden='true'></i>" . htmlescape($pace['label']) . "</div>";
        echo "<div class='sprint-small text-muted'>" . htmlescape($totalPoints > 0
            ? sprintf(__('%1$d of %2$d points done', 'sprint'), $donePoints, $totalPoints)
            : sprintf(__('%1$d of %2$d items done', 'sprint'), $count[SprintItem::STATUS_DONE], $total));
        if ($totalPoints > 0) {
            echo ' · ' . htmlescape(sprintf(__('expected by now %d', 'sprint'), $expectedNow));
            if ($forecast !== null) echo ' · ' . htmlescape(sprintf(__('forecast %d', 'sprint'), $forecast));
        }
        if ($totalPoints > 0 && $unpointed > 0) {
            echo "<br>" . htmlescape(sprintf(_n('%d item without story points is not counted.', '%d items without story points are not counted.', $unpointed, 'sprint'), $unpointed));
        }
        echo "</div>";
        if ($totalPoints > 0) {
            echo "<details class='sprint-live-forecast-help sprint-small'><summary>" . __s('How is the forecast made?', 'sprint') . "</summary>"
                . htmlescape(__('The forecast extrapolates this sprint\'s own pace: story points done so far divided by the share of the sprint time that has passed, capped at the planned points. It appears once 10% of the sprint has passed. It uses no history from earlier sprints and ignores blocked work, scope changes and days off.', 'sprint'))
                . "</details>";
        }
        echo "</div>";

        // --- Meters ---------------------------------------------------------
        echo "<div class='sprint-live-meters'>";
        echo "<div class='sprint-live-meter'><div class='sprint-live-meter-head'><span>" . htmlescape($progressLabel) . "</span><span class='text-muted'>" . htmlescape(sprintf(__('time elapsed %d%%', 'sprint'), $elapsed)) . "</span></div>";
        echo "<div class='sprint-live-track' title='" . htmlescape(sprintf(__('Progress %1$d%% · time elapsed %2$d%%', 'sprint'), $progress, $elapsed)) . "'><span class='sprint-live-fill' style='width:" . min(100, $progress) . "%;'></span><i class='sprint-live-tick' style='left:{$elapsed}%;'></i></div></div>";

        echo "<div class='sprint-live-meter'><div class='sprint-live-meter-head'><span>" . __('Status mix', 'sprint') . " <span class='text-muted'>(" . __s('weighted by capacity', 'sprint') . ")</span></span><span class='text-muted'>" . htmlescape(sprintf(_n('%d item', '%d items', $total, 'sprint'), $total)) . ($adhoc > 0 ? ' · ' . htmlescape(sprintf(__('%d adhoc', 'sprint'), $adhoc)) : '') . "</span></div>";
        $weightSum = max(array_sum($weight), 1.0);
        echo "<div class='sprint-live-track sprint-live-stack'>";
        foreach ($colors as $status => $color) {
            if ($weight[$status] <= 0) continue;
            $pct = round(100 * $weight[$status] / $weightSum, 2);
            echo "<span style='width:{$pct}%;background:{$color};' title='" . htmlescape(($labels[$status] ?? $status) . ': ' . $count[$status]) . "'></span>";
        }
        if ($total === 0) echo "<span class='sprint-live-empty'></span>";
        echo "</div><div class='sprint-live-legend'>";
        foreach ($colors as $status => $color) {
            $muted = $count[$status] === 0 ? ' is-zero' : '';
            echo "<span class='sprint-live-legend-item{$muted}'><i class='sprint-live-dot' style='background:{$color};' aria-hidden='true'></i>" . htmlescape($labels[$status] ?? $status) . " <b>" . $count[$status] . "</b></span>";
        }
        echo "</div></div></div>";

        // --- Burndown -------------------------------------------------------
        echo "<div class='sprint-live-burndown'><div class='sprint-live-meter-head'><span>" . __('Burndown', 'sprint') . "</span><span class='text-muted'>" . __('remaining vs. ideal', 'sprint') . "</span></div>";
        self::renderLiveBurndown($sprint, $items, $start, $end);
        echo "</div>";

        // --- Facts ----------------------------------------------------------
        $facts = [
            ['icon' => 'fas fa-star', 'value' => (string)$remainingPoints, 'label' => __('Points remaining', 'sprint'), 'tone' => ''],
            ['icon' => 'fas fa-hand-paper', 'value' => (string)$blocked, 'label' => __('Blocked', 'sprint'), 'tone' => $blocked > 0 ? 'is-bad' : ''],
            ['icon' => 'fas fa-link', 'value' => (string)$dependency, 'label' => __('Dependencies', 'sprint'), 'tone' => $dependency > 0 ? 'is-note' : ''],
            ['icon' => 'fas fa-layer-group', 'value' => (string)$wipBreaches, 'label' => __('WIP breaches', 'sprint'), 'tone' => $wipBreaches > 0 ? 'is-warn' : ''],
            [
                'icon'  => 'fas fa-users',
                'value' => $load === null ? '—' : $load . '%',
                'label' => __('Team load', 'sprint') . ($over > 0 ? ' · ' . sprintf(_n('%d over', '%d over', $over, 'sprint'), $over) : ''),
                'tone'  => $load === null ? '' : ($over > 0 || $load > 100 ? 'is-bad' : ($load >= 90 ? 'is-warn' : '')),
            ],
            [
                'icon'  => 'fas fa-calendar',
                'value' => $nextMeeting !== '' ? Html::convDateTime($nextMeeting) : '—',
                'label' => __('Next meeting', 'sprint'),
                'tone'  => $nextMeeting !== '' ? 'is-date' : '',
            ],
        ];
        echo "<div class='sprint-live-facts'>";
        foreach ($facts as $fact) {
            echo "<div class='sprint-live-fact " . $fact['tone'] . "'><i class='" . $fact['icon'] . "' aria-hidden='true'></i><div><div class='sprint-live-fact-value'>" . htmlescape($fact['value']) . "</div><div class='sprint-live-fact-label'>" . htmlescape($fact['label']) . "</div></div></div>";
        }
        echo "</div>";

        echo "</div></div>";
    }

    /**
     * Burndown sparkline: remaining points per day against the ideal line.
     * Actuals come from the audit log in one query (SprintAudit::getItemStatusTimeline()).
     */
    private static function renderLiveBurndown(array $sprint, array $items, int $start, int $end): void
    {
        $w = 320; $h = 78;
        $padL = 6; $padR = 30; $padT = 8; $padB = 16;
        $plotW = $w - $padL - $padR;
        $plotH = $h - $padT - $padB;

        $points = $current = [];
        $scope  = 0;
        foreach ($items as $item) {
            $id = (int)$item['id'];
            $p  = max(0, (int)$item['story_points']);
            $points[$id]  = $p;
            $current[$id] = (string)$item['status'];
            $scope += $p;
        }
        if ($scope <= 0) {
            echo "<div class='sprint-live-placeholder text-muted sprint-small'>" . __('No story points to burn down yet', 'sprint') . "</div>";
            return;
        }

        $days   = [];
        $cursor = strtotime('today', $start);
        $last   = strtotime('today', $end);
        $guard  = 0;
        while ($cursor <= $last && $guard++ < 120) {
            $days[] = $cursor;
            $cursor = strtotime('+1 day', $cursor);
        }
        if (count($days) < 2) $days = [$start, $end];
        $nDays = count($days);
        $today = strtotime('today');

        $stamps = [];
        foreach ($days as $i => $day) if ($day <= $today) $stamps[$i] = date('Y-m-d', $day) . ' 23:59:59';
        $timeline   = SprintAudit::getItemStatusTimeline(array_keys($points), array_values($stamps), $current);
        $statuses   = SprintItem::getAllStatuses();
        $doneTokens = [SprintItem::STATUS_DONE, (string)($statuses[SprintItem::STATUS_DONE] ?? '')];
        $actual     = [];
        foreach ($stamps as $i => $stamp) {
            $remaining = 0;
            foreach ($points as $id => $p) {
                if (!in_array((string)($timeline[$stamp][$id] ?? $current[$id]), $doneTokens, true)) $remaining += $p;
            }
            $actual[$i] = $remaining;
        }

        $fmt   = [SprintChart::class, 'num'];
        $xAt   = static fn(int $i) => $padL + $plotW * $i / max(1, $nDays - 1);
        $yAt   = static fn(float $v) => $padT + $plotH - $plotH * min(1.0, $v / $scope);
        $color = htmlescape(SprintChart::seriesColor(0));
        $lastI = $actual ? array_key_last($actual) : null;

        echo SprintChart::svgOpen($lastI === null
            ? __('Burndown', 'sprint')
            : sprintf(__('Burndown: %1$d of %2$d points remaining.', 'sprint'), $actual[$lastI], $scope), $w, $h, 'sprint-live-spark');
        $base = $fmt($yAt(0));
        $top  = $fmt($yAt($scope));
        $xEnd = $padL + $plotW;
        echo "<line class='sprint-chart-axis' x1='{$padL}' y1='{$base}' x2='{$xEnd}' y2='{$base}'/>";
        echo "<line class='sprint-chart-grid' x1='{$padL}' y1='{$top}' x2='{$xEnd}' y2='{$top}'/>";
        echo "<text class='sprint-chart-label sprint-chart-label-sub' x='" . ($xEnd + 4) . "' y='" . $fmt($yAt($scope) + 3.5) . "'>{$scope}</text>";
        echo "<text class='sprint-chart-label sprint-chart-label-sub' x='" . ($xEnd + 4) . "' y='" . $fmt($yAt(0) + 3.5) . "'>0</text>";
        // Ideal: scope to zero, a reference so it may dash.
        echo "<line class='sprint-chart-ideal' x1='" . $fmt($xAt(0)) . "' y1='{$top}' x2='" . $fmt($xAt($nDays - 1)) . "' y2='{$base}'/>";
        if (count($actual) > 1) {
            $line = [];
            foreach ($actual as $i => $v) $line[] = $fmt($xAt($i)) . ',' . $fmt($yAt($v));
            $area = $fmt($xAt(array_key_first($actual))) . ',' . $base . ' ' . implode(' ', $line) . ' ' . $fmt($xAt($lastI)) . ',' . $base;
            echo "<polygon class='sprint-chart-area' points='{$area}' fill='{$color}'/>";
            echo "<polyline class='sprint-chart-line' points='" . implode(' ', $line) . "' stroke='{$color}'/>";
        }
        if ($lastI !== null) {
            $when = Html::convDate(date('Y-m-d', $days[$lastI]));
            $left = sprintf(__('%d points remaining', 'sprint'), $actual[$lastI]);
            echo "<circle class='sprint-chart-point' cx='" . $fmt($xAt($lastI)) . "' cy='" . $fmt($yAt($actual[$lastI])) . "' r='4' fill='{$color}'"
                . SprintChart::tipAttr($when, [[$left, '', SprintChart::seriesColor(0)]]) . ">"
                . "<title>" . htmlescape($when . ' — ' . $left) . "</title></circle>";
        }
        echo "<text class='sprint-chart-label sprint-chart-label-sub' x='" . $fmt($xAt(0)) . "' y='" . ($h - 4) . "' text-anchor='start'>" . htmlescape(date('d/m', $days[0])) . "</text>";
        echo "<text class='sprint-chart-label sprint-chart-label-sub' x='" . $fmt($xAt($nDays - 1)) . "' y='" . ($h - 4) . "' text-anchor='end'>" . htmlescape(date('d/m', $days[$nDays - 1])) . "</text>";
        echo "</svg>";
    }

    /**
     * Key figures in two tiers. Four main tiles carry how delivery is going:
     * the figure, its change against the previous period and its course over
     * the sprints. The rest is a compact row. Tiles are neutral, colour is
     * only spent on the direction of a change.
     */
    private static function renderTiles(array $data, array $previous, string $period): void
    {
        $showDelta = $period !== self::PERIOD_ALL && $previous['sprint_count'] > 0;
        $series    = $data['series'] ?? [];
        $delta     = static fn(string $key, bool $lowerIsBetter = false): string => $showDelta
            ? self::delta((float)$data[$key], (float)($previous[$key] ?? 0), $lowerIsBetter)
            : '';
        $note      = static fn(string $text): string => "<div class='sprint-small text-muted'>" . htmlescape($text) . "</div>";

        $main = [
            [
                'icon'  => 'fas fa-gauge-high',
                'value' => number_format($data['avg_velocity'], 1),
                'label' => __('Avg velocity per sprint', 'sprint'),
                'delta' => $delta('avg_velocity'),
                'spark' => $series['pts_done'] ?? [],
            ],
            [
                'icon'  => 'fas fa-bullseye',
                'value' => $data['predictability'] . '%',
                'label' => __('Predictability (story points delivered / planned)', 'sprint'),
                'delta' => $delta('predictability'),
                'spark' => $series['predictability'] ?? [],
            ],
            [
                'icon'  => SprintIcon::CARRYOVER,
                'value' => (string)$data['carryover'],
                'label' => __('Carry-over items', 'sprint'),
                'delta' => $delta('carryover', true),
                'spark' => $series['carryover'] ?? [],
            ],
            [
                'icon'  => 'fas fa-plus-circle',
                'value' => (string)$data['adhoc'],
                'label' => __('Adhoc items', 'sprint'),
                'delta' => $delta('adhoc', true),
                'spark' => $series['adhoc'] ?? [],
            ],
        ];
        $more = [
            ['value' => (string)$data['sprint_count'], 'label' => __('Completed sprints', 'sprint'), 'delta' => $delta('sprint_count')],
            ['value' => (string)$data['pts_done'], 'label' => __('Story points delivered', 'sprint'), 'delta' => $delta('pts_done')],
            ['value' => $data['items_done'] . '/' . $data['items_total'], 'label' => __('Items done', 'sprint'), 'delta' => ''],
            ['value' => $data['consistency'] . '%', 'label' => __('Velocity consistency', 'sprint'), 'delta' => ''],
            ['value' => SprintMember::formatCapacity($data['avg_fl_cap']) . '%', 'label' => __('Avg fastlane capacity', 'sprint'), 'delta' => ''],
            ['value' => (string)max($data['blocked'], $data['flow']['blocked_items']), 'label' => __('Blocked items', 'sprint'), 'delta' => ''],
            [
                'value' => number_format($data['flow']['cycle_days'], 1),
                'label' => __('Avg cycle time (days)', 'sprint'),
                'delta' => $data['flow']['measured'] > 0 ? $note(sprintf(__('over %d items', 'sprint'), $data['flow']['measured'])) : '',
            ],
            ['value' => number_format($data['flow']['blocked_days'], 1), 'label' => __('Days spent blocked', 'sprint'), 'delta' => ''],
            ['value' => $data['flow']['rework_pct'] . '%', 'label' => __('Rework rate', 'sprint'), 'delta' => ''],
            [
                'value' => number_format($data['requests']['wait_days'], 1),
                'label' => __('Avg approval wait (days)', 'sprint'),
                'delta' => $data['requests']['total'] > 0
                    ? $note(sprintf(__('%1$d requests, %2$d%% accepted', 'sprint'), $data['requests']['total'], $data['requests']['accepted_pct']))
                    : '',
            ],
        ];

        echo "<div class='row g-2 mb-2'>";
        foreach ($main as $tile) {
            echo "<div class='col-12 col-sm-6 col-xl-3'>";
            echo "<div class='card card-sm h-100'><div class='card-body d-flex align-items-center sprint-overview-tile'>";
            echo "<span class='avatar me-3' aria-hidden='true'>" . SprintIcon::html($tile['icon']) . "</span>";
            echo "<div class='sprint-overview-tile-content'>";
            echo "<div class='text-muted sprint-small'>" . htmlescape($tile['label']) . "</div>";
            echo "<div class='sprint-overview-tile-value'>" . htmlescape($tile['value']) . "</div>";
            echo $tile['delta'];
            echo "</div>";
            // The course over the sprints of this period, oldest left.
            $spark = self::sparkline($tile['spark']);
            if ($spark !== '') {
                echo "<div class='sprint-overview-tile-spark'>{$spark}</div>";
            }
            echo "</div></div></div>";
        }
        echo "</div>";

        echo "<div class='sprint-overview-figures mb-3'>";
        foreach ($more as $tile) {
            echo "<div class='sprint-overview-figure'>";
            echo "<div class='sprint-overview-figure-value'>" . htmlescape($tile['value']) . "</div>";
            echo "<div class='sprint-overview-figure-label'>" . htmlescape($tile['label']) . "</div>";
            echo $tile['delta'];
            echo "</div>";
        }
        echo "</div>";
    }

    /** Signed change vs. the previous period; `$lowerIsBetter` flips the colour. */
    private static function delta(float $now, float $before, bool $lowerIsBetter = false): string
    {
        if ($before <= 0) {
            return '';
        }
        $pct = (($now - $before) / $before) * 100;
        if (abs($pct) < 1) {
            return "<div class='sprint-small text-muted'>" . __('unchanged vs. previous period', 'sprint') . "</div>";
        }
        $up    = $pct > 0;
        $good  = $lowerIsBetter ? !$up : $up;
        $arrow = $up ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down';
        return "<div class='sprint-small sprint-delta " . ($good ? 'is-good' : 'is-bad') . "'>"
            . "<i class='fas " . $arrow . "' aria-hidden='true'></i> " . sprintf('%+.0f%%', $pct)
            . " <span class='text-muted'>" . __('vs. previous period', 'sprint') . "</span></div>";
    }

    private static function renderSprintTable(array $data): void
    {
        self::renderCard('sprint-details',
            __('Sprint details', 'sprint'),
            'fas fa-table',
            '',
            function () use ($data) {
                echo "<div class='table-responsive'>";
                echo "<table class='table table-vcenter mb-0'>";
                echo "<thead><tr>";
                echo "<th scope='col'>" . Sprint::getTypeName(1) . "</th>";
                echo "<th scope='col'>" . __('Ended', 'sprint') . "</th>";
                foreach ([
                    __('Planned points', 'sprint'), __('Completed points', 'sprint'), __('Predictability', 'sprint'),
                    __('Items', 'sprint'), __('Fastlane', 'sprint'), __('Adhoc', 'sprint'), __('Carry-over items', 'sprint'),
                ] as $head) {
                    echo "<th scope='col' class='text-end'>" . $head . "</th>";
                }
                echo "</tr></thead><tbody>";
                foreach ($data['sprints'] as $row) {
                    echo "<tr>";
                    echo "<td><a href='" . Sprint::getFormURLWithID((int)$row['id']) . "'>"
                        . htmlescape((string)$row['name']) . "</a></td>";
                    echo "<td>" . Html::convDate((string)($row['date_end'] ?? '')) . "</td>";
                    echo "<td class='text-end'>" . (int)$row['pts_planned'] . "</td>";
                    echo "<td class='text-end'>" . (int)$row['pts_done'] . "</td>";
                    echo "<td class='text-end'>" . (int)$row['predictability'] . "%</td>";
                    echo "<td class='text-end'>" . (int)$row['items_done'] . "/" . (int)$row['items_total'] . "</td>";
                    echo "<td class='text-end'>" . (int)$row['fl_done'] . "/" . (int)$row['fl_items'] . "</td>";
                    echo "<td class='text-end'>" . (int)$row['adhoc'] . "</td>";
                    echo "<td class='text-end'>" . (int)$row['carryover'] . "</td>";
                    echo "</tr>";
                }
                echo "</tbody></table></div>";
            }
        );
    }

    // =========================================================================
    // Data
    // =========================================================================

    /**
     * Aggregate every completed sprint whose end date falls in the period.
     * `$shift` moves the window one full period back for the delta badges.
     */
    private static function collect(string $period, bool $shift = false, array $filters = []): array
    {
        [$from, $to] = self::periodRange($period, $shift);
        if (!empty($filters['from']) || !empty($filters['to'])) {
            $from = $filters['from'] ?: null;
            $to   = $filters['to'] ?: null;
            if ($shift && $from !== null && $to !== null) {
                $days = max(1, (int)((strtotime($to) - strtotime($from)) / DAY_TIMESTAMP) + 1);
                $from = date('Y-m-d', strtotime($from) - $days * DAY_TIMESTAMP);
                $to   = date('Y-m-d', strtotime($to) - $days * DAY_TIMESTAMP);
            }
        }

        $criteria = ['status' => Sprint::STATUS_COMPLETED];
        $range    = [];
        if ($from !== null) {
            $range[] = ['date_end' => ['>=', $from]];
        }
        if ($to !== null) {
            $range[] = ['date_end' => ['<=', $to]];
        }
        if (!empty($range)) {
            $criteria[] = ['AND' => $range];
        }
        if (!empty($filters['entities_id'])) $criteria['entities_id'] = (int)$filters['entities_id'];
        if (!empty($filters['scrum_master'])) $criteria['users_id'] = (int)$filters['scrum_master'];
        if (!empty($filters['projects_id'])) $criteria['projects_id'] = (int)$filters['projects_id'];
        if (!empty($filters['template_id'])) $criteria['plugin_sprint_sprinttemplates_id'] = (int)$filters['template_id'];
        if (!empty($filters['member_id'])) {
            $memberSprintIds = [];
            foreach ((new SprintMember())->find(['users_id' => (int)$filters['member_id']]) as $member) $memberSprintIds[] = (int)$member['plugin_sprint_sprints_id'];
            $criteria['id'] = $memberSprintIds ?: [-1];
        }
        // find() has no visibility layer: restrict to the caller's entities the
        // way Sprint::dropdown() does, or the page aggregates the whole install.
        $entityCriteria = getEntitiesRestrictCriteria(Sprint::getTable(), '', '', true);
        if (!empty($entityCriteria)) {
            $criteria = array_merge($criteria, $entityCriteria);
        }

        $sprints = array_values((new Sprint())->find($criteria, ['date_end ASC', 'id ASC']));
        $out = [
            'sprint_count'   => count($sprints),
            'sprints'        => [],
            'labels'         => [],
            'links'          => [],
            'series'         => [
                'pts_done'       => [],
                'pts_planned'    => [],
                'predictability' => [],
                'carryover'      => [],
                'fl_done'        => [],
                'adhoc'          => [],
                'fl_cap'         => [],
                'initial_scope'  => [],
                'removed_scope'  => [],
                'dependency'     => [],
                'cycle_days'     => [],
                'blocked_days'   => [],
                'rework_pct'     => [],
            ],
            'pts_done'       => 0,
            'pts_planned'    => 0,
            'items_total'    => 0,
            'items_done'     => 0,
            'adhoc'          => 0,
            'blocked'        => 0,
            'carryover'      => 0,
            'avg_velocity'   => 0.0,
            'avg_fl_cap'     => 0.0,
            'predictability' => 0,
            'consistency'    => 0,
            'per_member'     => [],
            'per_type'       => [],
            'per_category'   => [],
            'per_member_category' => [],
            'category_trend' => [],
            'workload'       => [],
            'workload_trend' => ['labels' => [], 'team' => [], 'over' => [], 'members' => 0, 'drift' => 0.0, 'direction' => 'stable'],
            'flow'           => ['cycle_days' => 0.0, 'blocked_days' => 0.0, 'rework_pct' => 0, 'measured' => 0, 'blocked_items' => 0],
            'requests'       => ['total' => 0, 'accepted_pct' => 0, 'wait_days' => 0.0],
            'dependencies'   => ['open' => 0, 'resolved' => 0, 'avg_age' => 0.0, 'multi_sprint' => 0, 'without_owner' => 0],
        ];
        if ($out['sprint_count'] === 0) {
            return $out;
        }

        $sprintIds = array_map(static fn($s) => (int)$s['id'], $sprints);
        $items     = self::fetchItems($sprintIds);
        $items     = self::filterItems($items, $filters);
        if (!empty($filters['itemtype']) || !empty($filters['tag']) || !empty($filters['epic_id']) || !empty($filters['category_id']) || !empty($filters['blocked_only']) || !empty($filters['over_only'])) {
            $matchingSprints = array_fill_keys(array_unique(array_map(static fn($item) => (int)$item['plugin_sprint_sprints_id'], $items)), true);
            $sprints = array_values(array_filter($sprints, static fn($sprint) => isset($matchingSprints[(int)$sprint['id']])));
            $sprintIds = array_map(static fn($sprint) => (int)$sprint['id'], $sprints);
            $out['sprint_count'] = count($sprints);
            if (!$sprints) return $out;
        }
        $flByUser  = self::fetchAllocations(SprintFastlaneMember::getTable(), $items, true);
        $depByUser = self::fetchAllocations(SprintItemDependency::getTable(), $items, false);

        // Item totals reuse the per-user rows.
        $flCap = [];
        foreach ($flByUser as $itemId => $perUser) {
            $flCap[$itemId] = array_sum($perUser);
        }

        $bySprint    = [];
        $perMember   = [];
        $perType     = [];
        $perCategory = [];
        // [sprint][category] => capacity (planned/actual) and points, for the
        // per-category trend chart. Fastlane items count their member
        // allocations, regular items their own capacity plus dependencies.
        $catBySprint = [];
        foreach ($items as $item) {
            $sid  = (int)$item['plugin_sprint_sprints_id'];
            $done = (string)$item['status'] === SprintItem::STATUS_DONE;
            $pts  = (int)$item['story_points'];

            $cid = (int)($item['plugin_sprint_sprintcategories_id'] ?? 0);
            $perCategory[$cid] ??= ['pts_planned' => 0, 'pts_done' => 0, 'items_total' => 0, 'items_done' => 0];
            $perCategory[$cid]['pts_planned'] += $pts;
            $perCategory[$cid]['items_total']++;
            if ($done) {
                $perCategory[$cid]['pts_done'] += $pts;
                $perCategory[$cid]['items_done']++;
            }

            $catBySprint[$sid][$cid] ??= ['cap_planned' => 0.0, 'pts_planned' => 0, 'pts_done' => 0];
            $depCap = array_sum($depByUser[(int)$item['id']] ?? []);
            if ((int)($item['is_fastlane'] ?? 0) === 1) {
                $catBySprint[$sid][$cid]['cap_planned'] += (float)($flCap[(int)$item['id']] ?? 0);
            } else {
                $catBySprint[$sid][$cid]['cap_planned'] += (float)($item['capacity'] ?? 0) + $depCap;
            }
            $catBySprint[$sid][$cid]['pts_planned'] += $pts;
            if ($done) {
                $catBySprint[$sid][$cid]['pts_done'] += $pts;
            }

            $bySprint[$sid] ??= [
                'pts_planned' => 0, 'pts_done' => 0, 'items_total' => 0, 'items_done' => 0,
                'fl_items' => 0, 'fl_done' => 0, 'adhoc' => 0, 'blocked' => 0, 'fl_cap' => 0.0, 'dependencies' => 0,
            ];
            $bucket = &$bySprint[$sid];
            $bucket['items_total']++;
            $bucket['pts_planned'] += $pts;
            if ($done) {
                $bucket['items_done']++;
                $bucket['pts_done'] += $pts;
            }
            if ((int)($item['is_fastlane'] ?? 0) === 1) {
                $bucket['fl_items']++;
                $bucket['fl_cap'] += (float)($flCap[(int)$item['id']] ?? 0);
                if ($done) {
                    $bucket['fl_done']++;
                }
            }
            if ((int)($item['is_adhoc'] ?? 0) === 1) {
                $bucket['adhoc']++;
            }
            // In a sprint blocked lives in `status`; `is_blocked` is the
            // backlog flag, cleared on assignment.
            if ((string)$item['status'] === SprintItem::STATUS_BLOCKED
                || (int)($item['is_blocked'] ?? 0) === 1) {
                $bucket['blocked']++;
            }
            if ((string)$item['status'] === SprintItem::STATUS_DEPENDENCY) $bucket['dependencies']++;
            unset($bucket);

            if ($done) {
                $uid = (int)$item['users_id'];
                $perMember[$uid] = ($perMember[$uid] ?? 0) + $pts;
                $type = (string)($item['itemtype'] ?? '');
                $perType[$type] = ($perType[$type] ?? 0) + 1;
            }
        }

        if (!empty($filters['predictability_below'])) {
            $threshold = (int)$filters['predictability_below'];
            $sprints = array_values(array_filter($sprints, static function ($sprint) use ($bySprint, $threshold) {
                $bucket = $bySprint[(int)$sprint['id']] ?? ['pts_planned' => 0, 'pts_done' => 0];
                $predictability = $bucket['pts_planned'] > 0 ? (int)round(100 * $bucket['pts_done'] / $bucket['pts_planned']) : 0;
                return $predictability < $threshold;
            }));
            $sprintIds = array_map(static fn($sprint) => (int)$sprint['id'], $sprints);
            $items = array_values(array_filter($items, static fn($item) => in_array((int)$item['plugin_sprint_sprints_id'], $sprintIds, true)));
            $perMember = $perType = $perCategory = [];
            foreach ($items as $item) {
                $done = (string)$item['status'] === SprintItem::STATUS_DONE;
                $pts  = (int)$item['story_points'];
                $cid  = (int)($item['plugin_sprint_sprintcategories_id'] ?? 0);
                $perCategory[$cid] ??= ['pts_planned' => 0, 'pts_done' => 0, 'items_total' => 0, 'items_done' => 0];
                $perCategory[$cid]['pts_planned'] += $pts;
                $perCategory[$cid]['items_total']++;
                if (!$done) continue;
                $perCategory[$cid]['pts_done'] += $pts;
                $perCategory[$cid]['items_done']++;
                $uid = (int)$item['users_id'];
                $perMember[$uid] = ($perMember[$uid] ?? 0) + $pts;
                $type = (string)$item['itemtype'];
                $perType[$type] = ($perType[$type] ?? 0) + 1;
            }
            $out['sprint_count'] = count($sprints);
            if (!$sprints) return $out;
        }

        $velocities = [];
        $flowStart = max(0, count($sprints) - self::CHART_SPRINTS);
        // One walk through the status history for every item; the per-sprint
        // figures and the period total aggregate the same per-item results.
        $flowItems       = $shift ? [] : self::flowPerItem($items);
        $itemIdsBySprint = [];
        foreach ($items as $item) {
            $itemIdsBySprint[(int)$item['plugin_sprint_sprints_id']][] = (int)$item['id'];
        }
        foreach ($sprints as $sprintIndex => $sprint) {
            $sid    = (int)$sprint['id'];
            $bucket = $bySprint[$sid] ?? [
                'pts_planned' => 0, 'pts_done' => 0, 'items_total' => 0, 'items_done' => 0,
                'fl_items' => 0, 'fl_done' => 0, 'adhoc' => 0, 'blocked' => 0, 'fl_cap' => 0.0, 'dependencies' => 0,
            ];
            $predict = $bucket['pts_planned'] > 0
                ? (int)round(($bucket['pts_done'] / $bucket['pts_planned']) * 100)
                : 0;
            $carry = max(0, $bucket['items_total'] - $bucket['items_done']);

            $out['sprints'][] = array_merge($bucket, [
                'id'             => $sid,
                'name'           => (string)$sprint['name'],
                'date_end'       => (string)($sprint['date_end'] ?? ''),
                'predictability' => $predict,
                'carryover'      => $carry,
            ]);
            $out['labels'][] = (string)$sprint['name'];
            $out['links'][] = Sprint::getFormURLWithID($sid);
            $out['series']['pts_done'][]       = $bucket['pts_done'];
            $out['series']['pts_planned'][]    = $bucket['pts_planned'];
            $out['series']['predictability'][] = $predict;
            $out['series']['carryover'][]      = $carry;
            $out['series']['fl_done'][]        = $bucket['fl_done'];
            $out['series']['adhoc'][]          = $bucket['adhoc'];
            $out['series']['fl_cap'][]         = (int)round($bucket['fl_cap']);
            $baselineItems = max(0, (int)($sprint['scope_baseline_items'] ?? 0));
            if ($baselineItems === 0) $baselineItems = max(0, $bucket['items_total'] - $bucket['adhoc']);
            $out['series']['initial_scope'][]  = $baselineItems;
            $out['series']['removed_scope'][]  = max(0, $baselineItems + $bucket['adhoc'] - $bucket['items_total']);
            $out['series']['dependency'][]     = $bucket['dependencies'];

            if (!$shift && $sprintIndex >= $flowStart) {
                $sprintFlow = self::flowAggregate($flowItems, $itemIdsBySprint[$sid] ?? []);
                $out['series']['cycle_days'][] = round($sprintFlow['cycle_days'], 1);
                $out['series']['blocked_days'][] = round($sprintFlow['blocked_days'], 1);
                $out['series']['rework_pct'][] = $sprintFlow['rework_pct'];
            } elseif (!$shift) {
                $out['series']['cycle_days'][] = 0;
                $out['series']['blocked_days'][] = 0;
                $out['series']['rework_pct'][] = 0;
            }

            $out['pts_done']    += $bucket['pts_done'];
            $out['pts_planned'] += $bucket['pts_planned'];
            $out['items_total'] += $bucket['items_total'];
            $out['items_done']  += $bucket['items_done'];
            $out['adhoc']       += $bucket['adhoc'];
            $out['blocked']     += $bucket['blocked'];
            $out['carryover']   += $carry;
            $out['avg_fl_cap']  += $bucket['fl_cap'];
            $velocities[]        = $bucket['pts_done'];
        }

        $out['category_trend'] = self::categoryTrend($sprints, $catBySprint);

        $count = $out['sprint_count'];
        $out['avg_velocity']   = $out['pts_done'] / $count;
        $out['avg_fl_cap']     = $out['avg_fl_cap'] / $count;
        $out['predictability'] = $out['pts_planned'] > 0
            ? (int)round(($out['pts_done'] / $out['pts_planned']) * 100)
            : 0;
        $out['consistency']    = self::consistency($velocities, $out['avg_velocity']);
        $out['per_member']     = self::rankMembers($perMember);
        $out['per_type']       = self::rankTypes($perType);
        $out['per_category']   = $perCategory;
        $out['per_member_category'] = self::memberCategoryUsage($items, $flByUser, $depByUser);
        $out['workload']       = self::workload($sprints, $items, $flByUser, $depByUser);
        $out['workload_trend'] = self::workloadTrend($out['labels'], $out['workload']);

        // The delta badges don't use flow data, so skip the log work there.
        if (!$shift) {
            $out['flow']     = self::flowAggregate($flowItems);
            $out['requests'] = self::requestStats($sprintIds);
            $out['dependencies'] = self::dependencyMetrics($items);
        }

        return $out;
    }

    /** @return array<int,array> item rows for the given sprints */
    private static function fetchItems(array $sprintIds): array
    {
        global $DB;

        if (empty($sprintIds)) {
            return [];
        }
        $rows = [];
        foreach ($DB->request([
            'SELECT' => [
                'id', 'plugin_sprint_sprints_id', 'status', 'story_points', 'capacity',
                'users_id', 'itemtype', 'items_id', 'is_fastlane', 'is_adhoc', 'is_blocked',
                'plugin_sprint_sprintepics_id', 'plugin_sprint_sprintcategories_id',
                'date_creation', 'date_mod', 'name',
            ],
            'FROM'   => SprintItem::getTable(),
            'WHERE'  => ['plugin_sprint_sprints_id' => $sprintIds],
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    private static function filterItems(array $items, array $filters): array
    {
        global $DB;
        if (!$items) return [];
        $allowedIds = null;
        if (!empty($filters['tag']) && $DB->tableExists('glpi_plugin_sprint_sprintitemtags')) {
            $allowedIds = [];
            foreach ($DB->request(['SELECT' => ['plugin_sprint_sprintitems_id'], 'FROM' => 'glpi_plugin_sprint_sprintitemtags', 'WHERE' => ['tag' => $filters['tag']]]) as $row) $allowedIds[(int)$row['plugin_sprint_sprintitems_id']] = true;
        }
        $cohortSprints = null;
        if (!empty($filters['blocked_only'])) {
            $cohortSprints = [];
            foreach ($items as $item) if ((string)$item['status'] === SprintItem::STATUS_BLOCKED || (int)$item['is_blocked'] === 1) $cohortSprints[(int)$item['plugin_sprint_sprints_id']] = true;
        }
        if (!empty($filters['over_only'])) {
            $over = [];
            $sprintIds = array_values(array_unique(array_map(static fn($item) => (int)$item['plugin_sprint_sprints_id'], $items)));
            foreach ((new SprintMember())->find(['plugin_sprint_sprints_id' => $sprintIds]) as $member) {
                $sid = (int)$member['plugin_sprint_sprints_id'];
                $uid = (int)$member['users_id'];
                $capacity = SprintAgility::effectiveCapacity($sid, $uid, (float)$member['capacity_percent']);
                $used = (float)(SprintMember::usedCapacityBySprint($sid)[$uid]['total'] ?? 0.0);
                if ($capacity > 0 && $used > $capacity) $over[$sid] = true;
            }
            $cohortSprints = $cohortSprints === null ? $over : array_intersect_key($cohortSprints, $over);
        }
        // Filtering on a parent category includes its subcategories.
        $categoryIds = null;
        if (!empty($filters['category_id'])) {
            $categoryIds = array_fill_keys(
                array_merge([(int)$filters['category_id']], SprintCategory::getChildrenOf((int)$filters['category_id'], false)),
                true
            );
        }
        return array_values(array_filter($items, static function ($item) use ($filters, $allowedIds, $cohortSprints, $categoryIds) {
            if ($cohortSprints !== null && !isset($cohortSprints[(int)$item['plugin_sprint_sprints_id']])) return false;
            if ($allowedIds !== null && !isset($allowedIds[(int)$item['id']])) return false;
            if (!empty($filters['itemtype']) && (string)$item['itemtype'] !== $filters['itemtype']) return false;
            if (!empty($filters['epic_id']) && (int)$item['plugin_sprint_sprintepics_id'] !== (int)$filters['epic_id']) return false;
            if ($categoryIds !== null && !isset($categoryIds[(int)($item['plugin_sprint_sprintcategories_id'] ?? 0)])) return false;
            return true;
        }));
    }

    private static function dependencyMetrics(array $items): array
    {
        global $DB;
        $out = ['open' => 0, 'resolved' => 0, 'avg_age' => 0.0, 'multi_sprint' => 0, 'without_owner' => 0];
        $ids = array_map(static fn($item) => (int)$item['id'], $items);
        if (!$ids || !$DB->tableExists(SprintItemDependency::getTable())) return $out;
        $itemMap = [];
        foreach ($items as $item) $itemMap[(int)$item['id']] = $item;
        $age = 0.0;
        foreach ($DB->request(['FROM' => SprintItemDependency::getTable(), 'WHERE' => ['plugin_sprint_sprintitems_id' => $ids]]) as $row) {
            if ((int)$row['is_resolved'] === 1) {
                $out['resolved']++;
                continue;
            }
            $out['open']++;
            if ((int)$row['users_id'] <= 0) $out['without_owner']++;
            $created = strtotime((string)$row['date_creation']);
            if ($created) $age += max(0, time() - $created) / DAY_TIMESTAMP;
        }
        $out['avg_age'] = $out['open'] > 0 ? $age / $out['open'] : 0.0;
        $linked = [];
        foreach ($items as $item) {
            if (empty($item['itemtype']) || empty($item['items_id'])) continue;
            $key = $item['itemtype'] . ':' . $item['items_id'];
            $linked[$key][(int)$item['plugin_sprint_sprints_id']] = true;
        }
        $out['multi_sprint'] = count(array_filter($linked, static fn($sprints) => count($sprints) > 1));
        return $out;
    }

    /**
     * Capacity from a junction table; `$fastlaneOnly` limits it to fastlane items.
     *
     * @return array<int,array<int,float>> [item id => [user id => capacity %]]
     */
    private static function fetchAllocations(string $table, array $items, bool $fastlaneOnly): array
    {
        global $DB;

        $ids = [];
        foreach ($items as $item) {
            if (!$fastlaneOnly || (int)($item['is_fastlane'] ?? 0) === 1) {
                $ids[] = (int)$item['id'];
            }
        }
        if (empty($ids)) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['plugin_sprint_sprintitems_id', 'users_id', 'capacity'],
            'FROM'   => $table,
            'WHERE'  => ['plugin_sprint_sprintitems_id' => $ids],
        ]) as $row) {
            $itemId = (int)$row['plugin_sprint_sprintitems_id'];
            $userId = (int)$row['users_id'];
            $out[$itemId][$userId] = ($out[$itemId][$userId] ?? 0) + (float)$row['capacity'];
        }
        return $out;
    }

    /**
     * Regular, fastlane and dependency capacity against availability, averaged
     * over the sprints a member took part in — same sum as the sprint dashboard.
     *
     * `loads` is the per-sprint load in $sprints order, null where the member did
     * not take part.
     */
    /**
     * Where each member's time went: allocated capacity per backlog category
     * over the collected items, plus the points and items they owned. Regular
     * items count their capacity on the owner; fastlane and dependency work
     * counts on the member the allocation names, so shared work lands on
     * everyone involved.
     *
     * @return array<int,array<int,array>> [user][category] => capacity/points bucket
     */
    private static function memberCategoryUsage(array $items, array $flByUser, array $depByUser): array
    {
        $blank = [
            'cap_planned' => 0.0,
            'pts_planned' => 0, 'pts_done' => 0, 'items_total' => 0, 'items_done' => 0,
        ];
        $out   = [];
        $catOf = [];
        $addCap = static function (int $uid, int $cid, float $planned) use (&$out, $blank): void {
            if ($uid <= 0 || $planned <= 0) {
                return;
            }
            $out[$uid][$cid] ??= $blank;
            $out[$uid][$cid]['cap_planned'] += $planned;
        };

        foreach ($items as $item) {
            $cid = (int)($item['plugin_sprint_sprintcategories_id'] ?? 0);
            $uid = (int)$item['users_id'];
            $catOf[(int)$item['id']] = $cid;
            // A fastlane item carries no capacity of its own; its member rows do.
            if ((int)($item['is_fastlane'] ?? 0) !== 1) {
                $addCap($uid, $cid, (float)($item['capacity'] ?? 0));
            }
            if ($uid > 0) {
                $out[$uid][$cid] ??= $blank;
                $out[$uid][$cid]['items_total']++;
                $out[$uid][$cid]['pts_planned'] += (int)$item['story_points'];
                if ((string)$item['status'] === SprintItem::STATUS_DONE) {
                    $out[$uid][$cid]['items_done']++;
                    $out[$uid][$cid]['pts_done'] += (int)$item['story_points'];
                }
            }
        }
        foreach ([$flByUser, $depByUser] as $source) {
            foreach ($source as $itemId => $perUser) {
                if (!isset($catOf[(int)$itemId])) {
                    continue;
                }
                foreach ($perUser as $uid => $capacity) {
                    $addCap((int)$uid, $catOf[(int)$itemId], (float)$capacity, (float)$capacity);
                }
            }
        }
        return $out;
    }

    /**
     * Per-category series over the sprints (chart window): allocated capacity
     * planned/actual and completed-vs-planned %. Subcategories are rolled up
     * into their parent, so one line per top-level category.
     *
     * @return array{labels:array<int,string>,rows:array<int,array>}
     */
    private static function categoryTrend(array $sprints, array $catBySprint): array
    {
        $cats     = SprintCategory::getAll(false);
        $parentOf = [];
        foreach ($cats as $cid => $cat) {
            $pid = (int)($cat['plugin_sprint_sprintcategories_id'] ?? 0);
            $parentOf[(int)$cid] = ((int)($cat['level'] ?? 0) > 0 && $pid > 0 && isset($cats[$pid])) ? $pid : (int)$cid;
        }
        $window = array_slice($sprints, -self::CHART_SPRINTS);
        $labels = [];
        $rows   = [];   // rolled up into the top-level category
        $detail = [];   // every category on its own (parent = own items only)
        $blank  = static fn(int $id, string $label, string $color, bool $sub) => [
            'id'          => $id,
            'label'       => $label,
            'color'       => $color,
            'sub'         => $sub,
            'cap_planned' => array_fill(0, count($window), 0.0),
            'pts_planned' => array_fill(0, count($window), 0),
            'pts_done'    => array_fill(0, count($window), 0),
        ];
        foreach ($window as $i => $sprint) {
            $sid      = (int)$sprint['id'];
            $labels[] = (string)$sprint['name'];
            foreach ($catBySprint[$sid] ?? [] as $cid => $bucket) {
                $cid = (int)$cid;
                $top = $parentOf[$cid] ?? 0;
                if ($top > 0 && !isset($cats[$top])) {
                    $top = 0;
                }
                if (!isset($rows[$top])) {
                    $rows[$top] = $blank(
                        $top,
                        $top > 0 ? (string)$cats[$top]['name'] : __('No category', 'sprint'),
                        $top > 0 ? (string)$cats[$top]['color'] : '#6c757d',
                        false
                    );
                }
                $own = isset($cats[$cid]) ? $cid : 0;
                if (!isset($detail[$own])) {
                    $isSub = $own > 0 && $top !== $own;
                    $detail[$own] = $blank(
                        $own,
                        $own > 0
                            ? ($isSub ? $cats[$top]['name'] . ' › ' . $cats[$own]['name'] : (string)$cats[$own]['name'])
                            : __('No category', 'sprint'),
                        $own > 0 ? (string)$cats[$own]['color'] : '#6c757d',
                        $isSub
                    );
                }
                foreach ([&$rows[$top], &$detail[$own]] as &$target) {
                    $target['cap_planned'][$i] += (float)$bucket['cap_planned'];
                    $target['pts_planned'][$i] += (int)$bucket['pts_planned'];
                    $target['pts_done'][$i]    += (int)$bucket['pts_done'];
                }
                unset($target);
            }
        }
        // Category order as configured (tree order: parent, then its
        // children), "no category" last.
        $order = static function (array $set) use ($cats): array {
            $ordered = [];
            foreach ($cats as $cid => $cat) {
                if (isset($set[(int)$cid])) {
                    $ordered[] = $set[(int)$cid];
                }
            }
            if (isset($set[0])) {
                $ordered[] = $set[0];
            }
            return $ordered;
        };
        return ['labels' => $labels, 'rows' => $order($rows), 'detail' => $order($detail)];
    }

    /**
     * Line chart per category over the sprints. Metric picker: planned
     * capacity % or completed-vs-planned %. Level picker: main categories or
     * with subcategories. Every combination is one pane. The pickers (and the
     * remembered choice) are wired page-wide in js/sprint.js, block "overview".
     */
    private static function renderCategoryTrend(array $data): void
    {
        $trend = $data['category_trend'] ?? [];
        $rows  = $trend['rows'] ?? [];
        self::renderCard('category-trend',
            __('Category trend', 'sprint'),
            'fas fa-chart-line',
            __('Per backlog category across the completed sprints in this period: allocated capacity % per sprint, or the completed-vs-planned share. Subcategories count towards their parent, or switch to the subcategory view to see them as separate lines. Click a legend entry to isolate a category.', 'sprint'),
            function () use ($trend, $rows) {
                $labels = array_values($trend['labels'] ?? []);
                $n      = count($labels);
                if (!$rows || $n === 0) {
                    echo SprintChart::emptyState(
                        'fas fa-chart-line',
                        __('No categorised work in this selection.', 'sprint'),
                        __('Give sprint items a backlog category, or choose a longer period.', 'sprint')
                    );
                    return;
                }
                $detail  = $trend['detail'] ?? [];
                $hasSubs = (bool)array_filter($detail, static fn($r) => !empty($r['sub']));
                $levels  = ['top' => $rows];
                if ($hasSubs) {
                    $levels['sub'] = $detail;
                }
                $metrics = [
                    'cap_planned' => __('Planned capacity %', 'sprint'),
                    'done_pct'    => __('Completed vs planned %', 'sprint'),
                ];

                // One dataset per level × metric; the pickers swap the visible one.
                $datasets = [];
                foreach ($levels as $level => $levelRows) {
                    foreach (array_keys($metrics) as $metric) {
                        $series = [];
                        $max    = 0.0;
                        foreach ($levelRows as $row) {
                            $values = [];
                            for ($i = 0; $i < $n; $i++) {
                                if ($metric === 'done_pct') {
                                    $planned = (int)$row['pts_planned'][$i];
                                    $v = $planned > 0 ? round(100 * (int)$row['pts_done'][$i] / $planned) : null;
                                } else {
                                    $v = round((float)$row[$metric][$i], 1);
                                }
                                $values[] = $v;
                                if ($v !== null && $v > $max) {
                                    $max = $v;
                                }
                            }
                            $series[] = ['label' => $row['label'], 'color' => $row['color'], 'values' => $values, 'sub' => !empty($row['sub'])];
                        }
                        $datasets[] = ['level' => $level, 'metric' => $metric, 'series' => $series, 'max' => $max];
                    }
                }

                echo "<div class='sprint-category-trend'>";
                echo "<div class='d-flex justify-content-end gap-2 mb-2'>";
                if ($hasSubs) {
                    echo "<select class='form-select form-select-sm sprint-category-trend-level' aria-label='" . __s('Category level', 'sprint') . "'>";
                    echo "<option value='top'>" . __s('Main categories', 'sprint') . "</option>";
                    echo "<option value='sub'>" . __s('With subcategories', 'sprint') . "</option>";
                    echo "</select>";
                }
                echo "<select class='form-select form-select-sm sprint-category-trend-metric' aria-label='" . __s('Metric', 'sprint') . "'>";
                foreach ($metrics as $key => $label) {
                    echo "<option value='" . htmlescape($key) . "'>" . htmlescape($label) . "</option>";
                }
                echo "</select></div>";
                foreach ($datasets as $set) {
                    $visible = $set['metric'] === 'cap_planned' && $set['level'] === 'top';
                    echo "<div class='sprint-category-trend-pane' data-chart-scope data-metric='" . htmlescape($set['metric']) . "' data-level='" . htmlescape($set['level']) . "'"
                        . ($visible ? '' : ' hidden') . ">";
                    self::renderCategoryLines(
                        $labels,
                        $set['series'],
                        // Completed-vs-planned always shows the 100% it is measured against.
                        $set['metric'] === 'done_pct' ? max(100.0, (float)$set['max']) : (float)$set['max'],
                        (string)$metrics[$set['metric']]
                    );
                    echo "</div>";
                }
                echo "</div>";
            }
        );
    }

    /**
     * SVG line chart, one line per category, y axis in %. Subcategories are
     * dashed, every series has its own marker shape, and the legend isolates a
     * line (shared charts script, scope = the surrounding `[data-chart-scope]`).
     *
     * @param array<int,string> $labels
     * @param array<int,array{label:string,color:string,values:array<int,float|null>,sub?:bool}> $series
     * @param float  $max    largest value the axis has to cover
     * @param string $metric name of the plotted metric, for the summary and the table
     */
    private static function renderCategoryLines(array $labels, array $series, float $max, string $metric): void
    {
        $labels = array_values($labels);
        $series = array_values($series);
        $n      = count($labels);
        $num    = [SprintChart::class, 'num'];
        $w      = 900;
        $padL   = 52;
        $padR   = 24;
        $padT   = 20;
        $slot   = ($w - $padL - $padR) / max(1, $n);
        $axis   = SprintChart::axisLabels($labels, $slot);
        $yB     = $padT + 200;
        $h      = (int)ceil($yB + $axis['height'] + 8);
        $scale  = SprintChart::niceScale($max);
        $xAt    = static fn(int $i): float => $padL + $slot * ($i + 0.5);
        $yAt    = static fn(float $v): float => SprintChart::yAt($v, $scale, $padT, $yB);
        $pct    = static fn(float $v): string => SprintMember::formatCapacity($v) . '%';

        echo "<div class='sprint-chart-box'>";
        echo SprintChart::svgOpen(sprintf(
            _n(
                '%1$s per backlog category over %2$d sprint, one line per category.',
                '%1$s per backlog category over %2$d sprints, one line per category.',
                $n,
                'sprint'
            ),
            $metric,
            $n
        ), $w, $h, 'sprint-overview-chart');
        echo SprintChart::yAxis($scale, $padL, $w - $padR, $padT, $yB, $pct);
        for ($i = 0; $i < $n; $i++) {
            echo SprintChart::axisLabel($axis, $i, $xAt($i), $yB);
        }

        $tips = array_fill(0, $n, []);
        foreach ($series as $idx => $s) {
            $color = htmlescape((string)$s['color']);
            // A null (no planned points that sprint) breaks the line rather
            // than drawing a misleading zero.
            $path = '';
            $prev = null;
            foreach ($s['values'] as $i => $v) {
                if ($v === null) {
                    continue;
                }
                $path .= ($prev === null || $prev !== $i - 1 ? 'M' : 'L') . $num($xAt($i)) . ',' . $num($yAt((float)$v)) . ' ';
                $prev  = $i;
            }
            echo "<g class='sprint-chart-series' data-series='c" . (int)$idx . "'>";
            if ($path !== '') {
                echo "<path class='sprint-chart-line" . (!empty($s['sub']) ? ' sprint-chart-line-sub' : '') . "' d='" . trim($path) . "' stroke='{$color}'/>";
            }
            foreach ($s['values'] as $i => $v) {
                if ($v === null) {
                    continue;
                }
                $y = $yAt((float)$v);
                echo SprintChart::marker($idx, $xAt($i), $y, 3.4, "class='sprint-chart-point' fill='{$color}'");
                // Shown once this category is isolated.
                echo "<text class='sprint-chart-value sprint-chart-pointlabel' x='" . $num($xAt($i)) . "' y='" . $num(max($y - 8, $padT + 9))
                    . "' text-anchor='middle'>" . htmlescape($pct((float)$v)) . "</text>";
                $tips[$i][] = ['v' => (float)$v, 'row' => [(string)$s['label'], $pct((float)$v), (string)$s['color']]];
            }
            echo "</g>";
        }

        for ($i = 0; $i < $n; $i++) {
            usort($tips[$i], static fn($a, $b) => $b['v'] <=> $a['v']);
            $rows = array_map(static fn($t) => $t['row'], $tips[$i]);
            echo self::chartSlot($padL + $slot * $i, $slot, $padT, $yB, $labels[$i], $rows ?: [[__('No data for this period.', 'sprint'), '']]);
        }
        echo "</svg></div>";

        $legend = [];
        foreach ($series as $idx => $s) {
            $nonNull  = array_filter($s['values'], static fn($v) => $v !== null);
            $avg      = $nonNull ? array_sum($nonNull) / count($nonNull) : 0;
            $legend[] = [
                'label' => (string)$s['label'],
                'color' => (string)$s['color'],
                'mark'  => !empty($s['sub']) ? 'ref' : 'line',
                'shape' => $idx,
                'key'   => 'c' . (int)$idx,
                'value' => sprintf(__('avg. %s%%', 'sprint'), SprintMember::formatCapacity($avg)),
                'muted' => !$nonNull,
            ];
        }
        echo SprintChart::legend($legend, count($series) > 1);

        $head = [Sprint::getTypeName(1)];
        foreach ($series as $s) {
            $head[] = (string)$s['label'];
        }
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $row = [$labels[$i]];
            foreach ($series as $s) {
                $v     = $s['values'][$i] ?? null;
                $row[] = $v === null ? '—' : $pct((float)$v);
            }
            $rows[] = $row;
        }
        echo self::chartTable($metric, $head, $rows);
    }

    private static function workload(array $sprints, array $items, array $flByUser, array $depByUser): array
    {
        global $DB;

        $sprintIds = array_map(static fn($s) => (int)$s['id'], $sprints);

        $used     = [];   // [sprint][user] => allocated %
        $sprintOf = [];
        foreach ($items as $item) {
            $sid = (int)$item['plugin_sprint_sprints_id'];
            $sprintOf[(int)$item['id']] = $sid;
            $uid = (int)$item['users_id'];
            if ($uid > 0 && (int)($item['is_fastlane'] ?? 0) !== 1) {
                $used[$sid][$uid] = ($used[$sid][$uid] ?? 0) + (float)($item['capacity'] ?? 0);
            }
        }
        foreach ([$flByUser, $depByUser] as $source) {
            foreach ($source as $itemId => $perUser) {
                $sid = $sprintOf[$itemId] ?? 0;
                foreach ($perUser as $uid => $capacity) {
                    if ($sid > 0 && $uid > 0) {
                        $used[$sid][$uid] = ($used[$sid][$uid] ?? 0) + $capacity;
                    }
                }
            }
        }

        $totals = [];
        foreach ($DB->request([
            'SELECT' => ['plugin_sprint_sprints_id', 'users_id', 'capacity_percent'],
            'FROM'   => SprintMember::getTable(),
            'WHERE'  => ['plugin_sprint_sprints_id' => $sprintIds],
        ]) as $row) {
            $sid = (int)$row['plugin_sprint_sprints_id'];
            $uid = (int)$row['users_id'];
            $available = SprintAgility::effectiveCapacity($sid, $uid, (float)$row['capacity_percent']);
            if ($available <= 0) {
                continue;
            }
            $load = (($used[$sid][$uid] ?? 0) / $available) * 100;

            $totals[$uid] ??= ['sum' => 0.0, 'sprints' => 0, 'peak' => 0.0, 'over' => 0, 'by_sprint' => []];
            $totals[$uid]['sum'] += $load;
            $totals[$uid]['sprints']++;
            $totals[$uid]['peak'] = max($totals[$uid]['peak'], $load);
            $totals[$uid]['by_sprint'][$sid] = $load;
            if ($load > 100) {
                $totals[$uid]['over']++;
            }
        }

        $out = [];
        foreach ($totals as $uid => $t) {
            $loads = [];
            foreach ($sprintIds as $sid) {
                $loads[] = $t['by_sprint'][$sid] ?? null;
            }
            [$drift, $direction] = self::loadDrift($loads);

            $out[] = [
                'label'     => SprintCache::userName($uid),
                'sprints'   => $t['sprints'],
                'avg'       => (int)round($t['sum'] / max(1, $t['sprints'])),
                'peak'      => (int)round($t['peak']),
                'over'      => $t['over'],
                'loads'     => $loads,
                'drift'     => $drift,
                'direction' => $direction,
            ];
        }
        usort($out, static fn($a, $b) => $b['avg'] <=> $a['avg']);
        return $out;
    }

    /** Average load per sprint over the members who took part, plus how many were over capacity. */
    private static function workloadTrend(array $labels, array $rows): array
    {
        $out = ['labels' => $labels, 'team' => [], 'over' => [], 'members' => count($rows), 'drift' => 0.0, 'direction' => 'stable'];
        if (empty($rows) || empty($labels)) {
            return $out;
        }

        foreach (array_keys($labels) as $i) {
            $loads = [];
            $over  = 0;
            foreach ($rows as $row) {
                $load = $row['loads'][$i] ?? null;
                if ($load === null) {
                    continue;
                }
                $loads[] = (float)$load;
                if ($load > 100) {
                    $over++;
                }
            }
            $out['team'][] = $loads === [] ? null : round(array_sum($loads) / count($loads), 1);
            $out['over'][] = $over;
        }

        [$out['drift'], $out['direction']] = self::loadDrift($out['team']);
        return $out;
    }

    /**
     * Least-squares trend over a member's loads → [drift in %-points across the
     * window, improving|worsening|stable]. A regression beats last-minus-first,
     * which two noisy sprints can flip on their own.
     *
     * The verdict is judged against the 100% line: only someone over capacity can
     * improve by dropping, and only a rise ending over capacity is worsening.
     */
    private static function loadDrift(array $loads): array
    {
        $points = [];
        foreach ($loads as $index => $load) {
            if ($load !== null) {
                $points[] = [(float)$index, (float)$load];
            }
        }
        $n = count($points);
        if ($n < 2) {
            return [0.0, 'stable'];
        }

        $meanX = array_sum(array_column($points, 0)) / $n;
        $meanY = array_sum(array_column($points, 1)) / $n;
        $num   = 0.0;
        $den   = 0.0;
        foreach ($points as [$x, $y]) {
            $num += ($x - $meanX) * ($y - $meanY);
            $den += ($x - $meanX) ** 2;
        }
        if ($den <= 0.0) {
            return [0.0, 'stable'];
        }

        $span  = $points[$n - 1][0] - $points[0][0];
        $drift = round(($num / $den) * $span, 1);

        // Over two sprints a "trend" is just last-minus-first: report the number,
        // but withhold the verdict until there is a third point to reject noise.
        if ($n < 3) {
            return [$drift, 'stable'];
        }

        $first = $points[0][1] + 0.0;
        $last  = $points[$n - 1][1];
        // 5 %-points of movement is the noise floor for a half-percent capacity grid.
        if ($drift <= -5.0 && ($meanY > 100 || $first > 100)) {
            return [$drift, 'improving'];
        }
        if ($drift >= 5.0 && ($meanY > 100 || $last > 100)) {
            return [$drift, 'worsening'];
        }
        return [$drift, 'stable'];
    }

    /**
     * Cycle time, blocked time and rework per item, from one read of the
     * status history. Aggregate with flowAggregate(); never re-read the log
     * per sprint.
     *
     * @return array<int,array{cycle:?float,blocked_seconds:int,done:bool,reopened:bool,ever_blocked:bool}>
     */
    private static function flowPerItem(array $items): array
    {
        global $DB;

        $out = [];
        foreach ($items as $item) {
            $out[(int)$item['id']] = [
                'cycle'           => null,
                'blocked_seconds' => 0,
                'done'            => false,
                'reopened'        => false,
                // Still blocked at close; the log walk adds the ones that
                // only passed through blocked.
                'ever_blocked'    => (string)($item['status'] ?? '') === SprintItem::STATUS_BLOCKED
                    || (int)($item['is_blocked'] ?? 0) === 1,
            ];
        }
        $ids = array_keys($out);
        if (empty($ids) || !$DB->tableExists('glpi_logs')) {
            return $out;
        }

        // History logs either the stored key or its translated label.
        $statuses = SprintItem::getAllStatuses();
        $token    = static fn(string $key) => [$key, (string)($statuses[$key] ?? '')];
        $done     = $token(SprintItem::STATUS_DONE);
        $blocked  = $token(SprintItem::STATUS_BLOCKED);
        $todo     = $token(SprintItem::STATUS_TODO);

        $logs = [];
        foreach ($DB->request([
            'SELECT' => ['items_id', 'date_mod', 'old_value', 'new_value'],
            'FROM'   => 'glpi_logs',
            'WHERE'  => [
                'itemtype'         => SprintItem::class,
                'items_id'         => $ids,
                'id_search_option' => 3,
            ],
            'ORDER'  => ['items_id ASC', 'date_mod ASC', 'id ASC'],
        ]) as $row) {
            $logs[(int)$row['items_id']][] = $row;
        }

        foreach ($logs as $itemId => $itemLogs) {
            $started    = null;
            $finished   = null;
            $blockedAt  = null;
            $wasDone    = false;
            $isReopened = false;
            $blockedSeconds = 0;

            foreach ($itemLogs as $log) {
                $ts    = strtotime((string)$log['date_mod']);
                $value = (string)$log['new_value'];

                if ($started === null && !in_array($value, $todo, true)) {
                    $started = $ts;
                }
                if (in_array($value, $done, true)) {
                    $finished = $ts;
                    $wasDone  = true;
                } elseif ($wasDone) {
                    $isReopened = true;
                }

                if (in_array($value, $blocked, true)) {
                    $blockedAt = $ts;
                    $out[$itemId]['ever_blocked'] = true;
                } elseif ($blockedAt !== null) {
                    $blockedSeconds += max(0, $ts - $blockedAt);
                    $blockedAt = null;
                }
            }

            if ($started !== null && $finished !== null && $finished >= $started) {
                $out[$itemId]['cycle'] = ($finished - $started) / 86400;
            }
            $out[$itemId]['blocked_seconds'] = $blockedSeconds;
            $out[$itemId]['done']            = $wasDone;
            $out[$itemId]['reopened']        = $wasDone && $isReopened;
        }
        return $out;
    }

    /**
     * Average cycle time, blocked days and rework share over a set of items
     * (all of them when $itemIds is null).
     *
     * @param array<int,array<string,mixed>> $perItem from flowPerItem()
     * @param int[]|null $itemIds
     * @return array{cycle_days:float,blocked_days:float,rework_pct:int,measured:int,blocked_items:int}
     */
    private static function flowAggregate(array $perItem, ?array $itemIds = null): array
    {
        $cycleSum = 0.0;
        $cycleN   = 0;
        $blockedSeconds = 0;
        $doneItems = 0;
        $reopened  = 0;
        $blockedItems = 0;
        $ids = $itemIds === null ? array_keys($perItem) : $itemIds;
        foreach ($ids as $id) {
            $f = $perItem[$id] ?? null;
            if ($f === null) {
                continue;
            }
            if ($f['cycle'] !== null) {
                $cycleSum += $f['cycle'];
                $cycleN++;
            }
            $blockedSeconds += $f['blocked_seconds'];
            if ($f['done']) {
                $doneItems++;
                if ($f['reopened']) {
                    $reopened++;
                }
            }
            if ($f['ever_blocked']) {
                $blockedItems++;
            }
        }
        return [
            'cycle_days'    => $cycleN > 0 ? $cycleSum / $cycleN : 0.0,
            'blocked_days'  => $blockedSeconds / 86400,
            'rework_pct'    => $doneItems > 0 ? (int)round(($reopened / $doneItems) * 100) : 0,
            'measured'      => $cycleN,
            'blocked_items' => $blockedItems,
        ];
    }

    /**
     * Approval queue throughput for the period's sprints.
     *
     * @return array{total:int,accepted_pct:int,wait_days:float}
     */
    private static function requestStats(array $sprintIds): array
    {
        $out = ['total' => 0, 'accepted_pct' => 0, 'wait_days' => 0.0];
        if (!SprintRequest::ensureTable()) {
            return $out;
        }

        $accepted = 0;
        $waitSum  = 0.0;
        $waitN    = 0;
        foreach ((new SprintRequest())->find(['plugin_sprint_sprints_id' => $sprintIds]) as $row) {
            $out['total']++;
            if ((string)$row['status'] === SprintRequest::STATUS_ACCEPTED) {
                $accepted++;
            }
            $created  = strtotime((string)($row['date_creation'] ?? ''));
            $handled  = strtotime((string)($row['date_mod'] ?? ''));
            if ((string)$row['status'] !== SprintRequest::STATUS_PENDING && $created && $handled && $handled >= $created) {
                $waitSum += ($handled - $created) / 86400;
                $waitN++;
            }
        }

        $out['accepted_pct'] = $out['total'] > 0 ? (int)round(($accepted / $out['total']) * 100) : 0;
        $out['wait_days']    = $waitN > 0 ? $waitSum / $waitN : 0.0;
        return $out;
    }

    /** Spread of the per-sprint velocity as a 0-100 stability score. */
    private static function consistency(array $velocities, float $avg): int
    {
        if (count($velocities) < 2 || $avg <= 0) {
            return 0;
        }
        $variance = 0.0;
        foreach ($velocities as $value) {
            $variance += ($value - $avg) ** 2;
        }
        $stdDev = sqrt($variance / count($velocities));
        return (int)round(max(0, min(100, 100 - (($stdDev / $avg) * 100))));
    }

    /** @return array<int,array{label:string,value:float}> top members by points */
    private static function rankMembers(array $perMember): array
    {
        arsort($perMember);
        $out = [];
        foreach (array_slice($perMember, 0, 10, true) as $userId => $points) {
            if ($points <= 0) {
                continue;
            }
            $out[] = [
                'label' => $userId > 0 ? SprintCache::userName($userId) : __('Unassigned', 'sprint'),
                'value' => (float)$points,
                'filter_key' => 'member_id',
                'filter_value' => (int)$userId,
            ];
        }
        return $out;
    }

    /** @return array<int,array{label:string,value:float}> item counts per linked type */
    private static function rankTypes(array $perType): array
    {
        $labels = [
            'Ticket'      => __('Ticket'),
            'Change'      => __('Change'),
            'Problem'     => __('Problem'),
            'ProjectTask' => __('Project task'),
            ''            => __('Manual item', 'sprint'),
        ];
        arsort($perType);
        $out = [];
        foreach ($perType as $type => $count) {
            $out[] = [
                'label' => $labels[$type] ?? $type,
                'value' => (float)$count,
                'filter_key' => 'itemtype',
                'filter_value' => (string)$type,
            ];
        }
        return $out;
    }

    /**
     * Start/end bounds for a period key, both inclusive; null means unbounded.
     * `$shift` returns the equally long window right before it.
     *
     * @return array{0:?string,1:?string}
     */
    private static function periodRange(string $period, bool $shift = false): array
    {
        if ($period === self::PERIOD_ALL) {
            return [null, null];
        }

        $now = new \DateTimeImmutable($_SESSION['glpi_currenttime'] ?? 'now');
        switch ($period) {
            case self::PERIOD_DAY:
                $start = $now->setTime(0, 0)->modify($shift ? '-1 day' : 'today');
                $end   = $start->modify('+1 day');
                break;
            case self::PERIOD_WEEK:
                $start = $now->modify('monday this week')->setTime(0, 0);
                $start = $shift ? $start->modify('-1 week') : $start;
                $end   = $start->modify('+1 week');
                break;
            case self::PERIOD_MONTH:
                $start = $now->modify('first day of this month')->setTime(0, 0);
                $start = $shift ? $start->modify('-1 month') : $start;
                $end   = $start->modify('+1 month');
                break;
            case self::PERIOD_HALFYEAR:
                $end   = $now->setTime(0, 0)->modify('+1 day');
                $end   = $shift ? $end->modify('-6 months') : $end;
                $start = $end->modify('-6 months');
                break;
            case self::PERIOD_YEAR:
            default:
                $start = $now->setDate((int)$now->format('Y') - ($shift ? 1 : 0), 1, 1)->setTime(0, 0);
                $end   = $start->modify('+1 year');
                break;
        }

        return [
            $start->format('Y-m-d H:i:s'),
            $end->modify('-1 second')->format('Y-m-d H:i:s'),
        ];
    }

    // =========================================================================
    // Rendering helpers
    // =========================================================================

    private static function renderScopeStability(array $chart): void
    {
        self::renderCard('scope', __('Scope stability', 'sprint'), 'fas fa-arrows-left-right', __('Shows baseline scope, work added after kick-off, completed work and carry-over per sprint.', 'sprint'), function () use ($chart) {
            self::renderGroupedBars($chart['labels'], [
                ['label' => __('Baseline items', 'sprint'), 'color' => SprintChart::metricColor('planned'), 'values' => $chart['series']['initial_scope']],
                ['label' => __('Added after kick-off', 'sprint'), 'color' => SprintChart::metricColor('adhoc'), 'values' => $chart['series']['adhoc']],
                ['label' => __('Removed during sprint', 'sprint'), 'color' => SprintChart::seriesColor(3), 'values' => $chart['series']['removed_scope']],
                ['label' => __('Completed items', 'sprint'), 'color' => SprintChart::metricColor('completed'), 'values' => array_map(static fn($row) => $row['items_done'], array_slice($chart['sprints'], -count($chart['labels'])))],
                ['label' => __('Carry-over items', 'sprint'), 'color' => SprintChart::metricColor('carryover'), 'values' => $chart['series']['carryover']],
            ], null, null, $chart['links'], __('Scope stability', 'sprint'));
        });
    }

    private static function renderFlowHealth(array $data): void
    {
        self::renderCard('flow', __('Flow health', 'sprint'), 'fas fa-water', __('Compares cycle time, blocked time and rework across completed sprints.', 'sprint'), function () use ($data) {
            $chart = self::trimToChartWindow($data);
            self::renderGroupedBars($chart['labels'], [
                ['label' => __('Cycle time (days)', 'sprint'), 'color' => SprintChart::seriesColor(2), 'values' => $chart['series']['cycle_days']],
                ['label' => __('Blocked time (days)', 'sprint'), 'color' => SprintChart::metricColor('blocked'), 'values' => $chart['series']['blocked_days']],
            ], ['label' => __('Rework rate', 'sprint'), 'color' => SprintChart::seriesColor(3), 'values' => $chart['series']['rework_pct'], 'suffix' => '%', 'max' => max(25, (int)ceil(max(array_merge([0], $chart['series']['rework_pct'])) / 25) * 25)], null, $chart['links'], __('Flow health', 'sprint'));
        });
    }

    /**
     * Bubble per sprint: team load (x) against predictability (y). A blocked
     * sprint is a filled bubble, a healthy one a ring, so the state reads
     * without colour. The size is the adhoc work.
     */
    private static function renderCapacityDelivery(array $data): void
    {
        self::renderCard('capacity-delivery', __('Capacity versus delivery', 'sprint'), 'fas fa-circle-nodes', __('Each bubble is a sprint. Position compares team load with predictability. Colour shows blocked work and size shows adhoc work.', 'sprint'), function () use ($data) {
            $loads = $data['workload_trend']['team'] ?? [];
            // A sprint without a measured load has no place on the x-axis.
            $bubbles = [];
            $table   = [];
            foreach ($data['sprints'] as $i => $row) {
                $load    = $loads[$i] ?? null;
                $table[] = [
                    (string)$row['name'],
                    $load === null ? '—' : (int)round((float)$load) . '%',
                    (float)$row['predictability'] . '%',
                    (string)(int)$row['blocked'],
                    (string)(int)$row['adhoc'],
                ];
                if ($load !== null) {
                    $bubbles[] = ['row' => $row, 'load' => (float)$load, 'radius' => 6 + min(16, (int)$row['adhoc'] * 2)];
                }
            }
            if (!$bubbles) {
                echo SprintChart::emptyState(
                    'fas fa-circle-nodes',
                    __('No team load to compare yet.', 'sprint'),
                    __('Load is measured from the capacity allocated to sprint members. Choose a longer period or clear a filter.', 'sprint')
                );
                return;
            }

            $num  = [SprintChart::class, 'num'];
            $w    = 900;
            $l    = 58;
            $r    = 24;
            $t    = 18;
            $yB   = $t + 230;
            $h    = $yB + 44;
            $pw   = $w - $l - $r;
            $xMax = max(125, (int)ceil(max(array_column($bubbles, 'load')) / 25) * 25);
            $xAt  = static fn(float $v): float => $l + $pw * min($xMax, max(0.0, $v)) / $xMax;
            // Both axes are percentages on multiples of 25.
            $scale = ['max' => 100.0, 'step' => 25.0, 'ticks' => [0.0, 25.0, 50.0, 75.0, 100.0]];
            $ok      = htmlescape(SprintChart::seriesColor(2));
            $blocked = htmlescape(SprintChart::metricColor('blocked'));
            $blockedCount = count(array_filter($bubbles, static fn($b) => (int)$b['row']['blocked'] > 0));

            echo "<div data-chart-scope>";
            echo "<div class='sprint-chart-box'>";
            echo SprintChart::svgOpen(sprintf(
                _n(
                    'Team load against predictability for %1$d sprint, %2$d with blocked work.',
                    'Team load against predictability for %1$d sprints, %2$d with blocked work.',
                    count($bubbles),
                    'sprint'
                ),
                count($bubbles),
                $blockedCount
            ), $w, $h, 'sprint-overview-chart');
            echo SprintChart::yAxis($scale, $l, $w - $r, $t, $yB, static fn(float $v): string => (int)$v . '%');
            for ($pct = 0; $pct <= $xMax; $pct += 25) {
                echo "<text class='sprint-chart-label' x='" . $num($xAt($pct)) . "' y='" . ($yB + 14) . "' text-anchor='middle'>{$pct}%</text>";
            }
            // Reference line: 100% load = fully committed team.
            $x100 = $num($xAt(100));
            echo "<line class='sprint-chart-limit' x1='{$x100}' y1='{$t}' x2='{$x100}' y2='{$yB}'/>";
            echo "<text class='sprint-chart-label sprint-chart-label-sub' x='" . $num($xAt(100) + 4) . "' y='" . ($t + 9) . "'>"
                . htmlescape(__('100% load', 'sprint')) . "</text>";
            echo "<text class='sprint-chart-label' x='" . $num($l + $pw / 2) . "' y='" . ($h - 6) . "' text-anchor='middle'>"
                . htmlescape(__('Average team load', 'sprint')) . "</text>";
            echo "<text class='sprint-chart-label' transform='translate(13 " . $num(($t + $yB) / 2) . ") rotate(-90)' text-anchor='middle'>"
                . htmlescape(__('Predictability', 'sprint')) . "</text>";

            // Large bubbles first so small ones stay clickable on top.
            usort($bubbles, static fn($a, $b2) => $b2['radius'] <=> $a['radius']);
            foreach ($bubbles as $bubble) {
                $row       = $bubble['row'];
                $predict   = (float)$row['predictability'];
                $cx        = $num($xAt($bubble['load']));
                $cy        = $num(SprintChart::yAt($predict, $scale, $t, $yB));
                $radius    = $bubble['radius'];
                $isBlocked = (int)$row['blocked'] > 0;
                $color     = $isBlocked ? $blocked : $ok;
                $rows      = [
                    [__('Average team load', 'sprint'), (int)round($bubble['load']) . '%'],
                    [__('Predictability', 'sprint'), $predict . '%'],
                    [__('Blocked items', 'sprint'), (string)(int)$row['blocked'], $isBlocked ? SprintChart::metricColor('blocked') : ''],
                    [__('Adhoc items', 'sprint'), (string)(int)$row['adhoc']],
                ];
                $plain = $row['name'] . ': ' . implode(', ', array_map(static fn($r) => $r[0] . ' ' . $r[1], $rows));
                echo "<a class='sprint-bubble' href='" . htmlescape(Sprint::getFormURLWithID((int)$row['id'])) . "'"
                    . SprintChart::tipAttr((string)$row['name'], $rows) . ">"
                    . "<title>" . htmlescape($plain) . "</title>"
                    . "<circle class='sprint-bubble-halo' cx='{$cx}' cy='{$cy}' r='" . ($radius + 1.5) . "'/>"
                    . "<circle cx='{$cx}' cy='{$cy}' r='{$radius}' fill='{$color}' stroke='{$color}' stroke-width='2' fill-opacity='"
                    . ($isBlocked ? '.6' : '.16') . "'/></a>";
            }
            echo "</svg></div>";

            // Ring against filled is its own swatch, in the shared legend frame.
            echo "<div class='sprint-chart-legend'>";
            echo "<span class='sprint-chart-legend-item is-static'><span class='sprint-bubble-swatch' style='--sprint-bubble:{$ok}' aria-hidden='true'></span>"
                . __s('No blocked work', 'sprint') . "</span>";
            echo "<span class='sprint-chart-legend-item is-static'><span class='sprint-bubble-swatch is-filled' style='--sprint-bubble:{$blocked}' aria-hidden='true'></span>"
                . __s('Blocked work', 'sprint') . "</span>";
            echo "<span class='sprint-chart-legend-item is-static'>" . __s('Bubble size = adhoc items', 'sprint') . "</span>";
            echo "</div>";

            echo self::chartTable(
                __('Capacity versus delivery', 'sprint'),
                [Sprint::getTypeName(1), __('Average team load', 'sprint'), __('Predictability', 'sprint'), __('Blocked items', 'sprint'), __('Adhoc items', 'sprint')],
                $table
            );
            echo "</div>";
        });
    }

    private static function renderDependencyHealth(array $data): void
    {
        $dep = $data['dependencies'];
        self::renderCard('dependencies', __('Dependency health', 'sprint'), 'fas fa-link', __('Tracks open and resolved dependencies, waiting time and linked work that spans several sprints.', 'sprint'), function () use ($dep) {
            echo "<div class='row g-2'>";
            foreach ([
                [__('Open dependencies', 'sprint'), $dep['open'], 'red'],
                [__('Resolved dependencies', 'sprint'), $dep['resolved'], 'green'],
                [__('Average waiting time', 'sprint'), number_format($dep['avg_age'], 1) . ' ' . __('days', 'sprint'), 'orange'],
                [__('Linked work across sprints', 'sprint'), $dep['multi_sprint'], 'blue'],
                [__('Without owner', 'sprint'), $dep['without_owner'], 'purple'],
            ] as [$label, $value, $color]) echo "<div class='col-6 col-lg'><div class='border rounded p-3 h-100'><div class='h2 text-{$color} mb-1'>" . htmlescape((string)$value) . "</div><div class='text-muted sprint-small'>" . htmlescape($label) . '</div></div></div>';
            echo '</div>';
        });
    }

    private static function renderComparison(array $current, string $period, array $filters): void
    {
        if (empty($filters['compare_period']) && empty($filters['compare_scrum_master'])) return;
        $compareFilters = $filters;
        $comparePeriod = $filters['compare_period'] ?: $period;
        if (!empty($filters['compare_period'])) {
            $compareFilters['from'] = '';
            $compareFilters['to'] = '';
        }
        if (!empty($filters['compare_scrum_master'])) $compareFilters['scrum_master'] = (int)$filters['compare_scrum_master'];
        $compareFilters['compare_period'] = '';
        $compareFilters['compare_scrum_master'] = 0;
        $other = self::collect($comparePeriod, false, $compareFilters);
        self::renderCard('comparison', __('Comparison', 'sprint'), 'fas fa-code-compare', __('Compare the current selection with another period or Scrum Master.', 'sprint'), function () use ($current, $other) {
            echo "<div class='table-responsive'><table class='table table-sm'><thead><tr><th>" . __('Metric', 'sprint') . "</th><th>" . __('Current selection', 'sprint') . "</th><th>" . __('Comparison', 'sprint') . "</th><th>" . __('Difference', 'sprint') . "</th></tr></thead><tbody>";
            foreach ([
                __('Completed sprints', 'sprint') => ['sprint_count', ''],
                __('Completed points', 'sprint') => ['pts_done', ''],
                __('Avg velocity per sprint', 'sprint') => ['avg_velocity', ''],
                __('Predictability', 'sprint') => ['predictability', '%'],
                __('Carry-over items', 'sprint') => ['carryover', ''],
                __('Adhoc items', 'sprint') => ['adhoc', ''],
            ] as $label => [$key, $suffix]) { $a = (float)$current[$key]; $b = (float)$other[$key]; echo '<tr><td>' . htmlescape($label) . '</td><td>' . round($a, 1) . $suffix . '</td><td>' . round($b, 1) . $suffix . "</td><td class='" . ($a >= $b ? 'text-success' : 'text-danger') . "'>" . ($a - $b > 0 ? '+' : '') . round($a - $b, 1) . $suffix . '</td></tr>'; }
            echo '</tbody></table></div>';
        });
    }

    /** Collapsed overview sections for this request: every section in compact view, else the user's stored list. */
    private static function collapsedSections(): array
    {
        static $ids = null;
        if ($ids === null) {
            $ids = self::isCompactView() ? ['*'] : UserPref::overviewCollapsed();
        }
        return $ids;
    }

    private static function isCompactView(): bool
    {
        return ($_GET['view'] ?? '') === 'compact';
    }

    /**
     * Page behaviour: collapsible sections (state stored per user under a
     * stable section id, so adding a card never shifts another card's state),
     * expand/collapse all, saved filters scoped to the logged-in user, and the
     * single reset that restores filters and section layout.
     */
    private static function renderOverviewBehaviour(): void
    {
        $base = Plugin::getWebDir('sprint') . '/ajax/';
        $cfg = [
            'tokenUrl'   => $base . 'csrftoken.php',
            'prefUrl'    => $base . 'userpref.php',
            'prefName'   => UserPref::OVERVIEW_COLLAPSED,
            'compact'    => self::isCompactView(),
            'filtersKey' => 'sprint-overview-saved-filters-u' . (int)Session::getLoginUserID(),
            'i18n'       => [
                'name'          => __('Name this filter', 'sprint'),
                'rename'        => __('New name for this filter', 'sprint'),
                'confirmDelete' => __('Delete this saved filter?', 'sprint'),
                'saveTitle'     => __('Save filter', 'sprint'),
                'renameTitle'   => __('Rename', 'sprint'),
                'save'          => __('Save'),
                'delete'        => __('Delete'),
            ],
        ];
        echo "<script>(function(){"
            . "var cfg=" . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP) . ";"
            . "var root=document.querySelector('.sprint-overview-page');if(!root||root.getAttribute('data-wired'))return;root.setAttribute('data-wired','1');"
            . "function post(value,done){var finish=function(){if(done)done();};"
            . "\$.ajax({url:cfg.tokenUrl,type:'GET',dataType:'json'}).done(function(t){"
            . "\$.ajax({url:cfg.prefUrl,type:'POST',dataType:'json',data:{name:cfg.prefName,value:value,_glpi_csrf_token:t&&t.token}}).always(finish);"
            . "}).fail(finish);}"
            . "function collapsedIds(){var out=[];root.querySelectorAll('.sprint-overview-collapsible.is-collapsed').forEach(function(c){out.push(c.getAttribute('data-section-id'));});return out.join(',');}"
            . "var timer=null;function persist(){if(cfg.compact)return;clearTimeout(timer);timer=setTimeout(function(){post(collapsedIds());},400);}"
            . "function setCollapsed(card,collapsed){var btn=card.querySelector('.sprint-overview-toggle');var body=btn?document.getElementById(btn.getAttribute('aria-controls')):null;card.classList.toggle('is-collapsed',collapsed);if(body)body.hidden=collapsed;if(btn)btn.setAttribute('aria-expanded',collapsed?'false':'true');}"
            . "root.addEventListener('click',function(e){"
            . "var btn=e.target.closest('.sprint-overview-toggle');"
            . "if(btn){var card=btn.closest('.sprint-overview-collapsible');if(card){setCollapsed(card,!card.classList.contains('is-collapsed'));persist();}return;}"
            . "var all=e.target.closest('[data-overview-all]');"
            . "if(all){var collapse=all.getAttribute('data-overview-all')==='collapse';root.querySelectorAll('.sprint-overview-collapsible').forEach(function(c){setCollapsed(c,collapse);});persist();return;}"
            . "var reset=e.target.closest('.sprint-overview-reset');"
            . "if(reset){e.preventDefault();var href=reset.getAttribute('href');post('',function(){location.href=href;});}"
            . "});"
            // Saved filters: per user so a shared browser never shows someone else's selection.
            . "var select=document.getElementById('sprint-saved-filters'),save=document.getElementById('sprint-save-filter'),ren=document.getElementById('sprint-rename-filter'),del=document.getElementById('sprint-delete-filter');"
            . "function read(){try{return JSON.parse(localStorage.getItem(cfg.filtersKey)||'{}')||{};}catch(e){return {};}}"
            . "function write(all){try{localStorage.setItem(cfg.filtersKey,JSON.stringify(all));}catch(e){}}"
            . "function current(){if(!select)return '';var o=select.options[select.selectedIndex];return o&&o.value?o.getAttribute('data-name'):'';}"
            . "function fill(){if(!select)return;var all=read();while(select.options.length>1)select.remove(1);Object.keys(all).sort().forEach(function(name){var o=document.createElement('option');o.value=all[name];o.textContent=name;o.setAttribute('data-name',name);if(all[name]===location.href)o.selected=true;select.appendChild(o);});var has=!!current();if(ren)ren.hidden=!has;if(del)del.hidden=!has;}"
            // The plugin dialogs follow the theme. The browser's own are the fallback when sprint.js is missing.
            . "function ask(title,label,value,done){var use=function(text){text=String(text||'').replace(/\\s+/g,' ').trim();if(text)done(text);};"
            . "if(typeof window.sprintPrompt==='function'){window.sprintPrompt({title:title,label:label,value:value||'',required:true,maxLength:80,rows:1,okLabel:cfg.i18n.save},use);}"
            . "else{use(window.prompt(label,value||''));}}"
            . "function sure(message,done){if(typeof window.sprintConfirm==='function'){window.sprintConfirm(message,done,{danger:true,okLabel:cfg.i18n['delete']});}"
            . "else if(window.confirm(message)){done();}}"
            . "if(save)save.addEventListener('click',function(){ask(cfg.i18n.saveTitle,cfg.i18n.name,'',function(name){var all=read();all[name]=location.href;write(all);fill();});});"
            . "if(ren)ren.addEventListener('click',function(){var old=current();if(!old)return;ask(cfg.i18n.renameTitle,cfg.i18n.rename,old,function(name){if(name===old)return;var all=read();all[name]=all[old];delete all[old];write(all);fill();});});"
            . "if(del)del.addEventListener('click',function(){var old=current();if(!old)return;sure(cfg.i18n.confirmDelete,function(){var all=read();delete all[old];write(all);fill();});});"
            . "if(select)select.addEventListener('change',function(){if(this.value&&this.value!==location.href)location.href=this.value;});"
            . "fill();"
            . "})();</script>";
    }

    /**
     * One overview section. `$id` is a stable section id: it keys the
     * per-user collapse state and the anchor (#sprint-overview-<id>).
     */
    private static function renderCard(string $id, string $title, string $icon, string $subtitle, callable $body, string $wrapper = ''): void
    {
        $collapsedIds = self::collapsedSections();
        $collapsed    = in_array('*', $collapsedIds, true) || in_array($id, $collapsedIds, true);
        $bodyId       = 'sprint-overview-' . $id . '-body';

        if ($wrapper !== '') {
            echo "<div class='" . htmlescape($wrapper) . "'>";
        }
        // h-100 only for cards sharing a row: on a full-width card the
        // percentage resolves against the whole stretched content column.
        $classes = ($wrapper !== '' ? 'card h-100' : 'card mb-3') . ' sprint-overview-collapsible' . ($collapsed ? ' is-collapsed' : '');
        echo "<section class='" . $classes . "' id='sprint-overview-" . htmlescape($id) . "' data-section-id='" . htmlescape($id) . "'><div class='card-body'>";
        echo "<h3 class='card-title'><button type='button' class='sprint-overview-toggle' aria-expanded='" . ($collapsed ? 'false' : 'true') . "' aria-controls='" . htmlescape($bodyId) . "'>"
            . "<i class='fas fa-chevron-down sprint-overview-chevron' aria-hidden='true'></i>"
            . "<i class='" . htmlescape($icon) . " me-2' aria-hidden='true'></i>" . htmlescape($title) . "</button></h3>";
        echo "<div class='sprint-overview-section-body' id='" . htmlescape($bodyId) . "'" . ($collapsed ? ' hidden' : '') . ">";
        if ($subtitle !== '') {
            echo "<div class='sprint-card-hint'>" . htmlescape($subtitle) . "</div>";
        }
        $body();
        echo "</div></div></section>";
        if ($wrapper !== '') {
            echo "</div>";
        }
    }

    /** One hover and focus target over a full chart slot, with its guide line. */
    private static function chartSlot(float $x, float $width, float $yTop, float $yBottom, string $title, array $rows, string $href = ''): string
    {
        $num   = [SprintChart::class, 'num'];
        $plain = $title;
        if ($rows) {
            $plain .= ': ' . implode(', ', array_map(static fn($r) => trim((string)$r[0] . ' ' . (string)($r[1] ?? '')), $rows));
        }
        $cx   = $num($x + $width / 2);
        $rect = "<rect class='sprint-chart-hit' x='" . $num($x) . "' y='" . $num($yTop) . "' width='" . $num($width)
            . "' height='" . $num($yBottom - $yTop) . "'";
        $tip  = SprintChart::tipAttr($title, $rows);
        $out  = "<g class='sprint-chart-slot'><line class='sprint-chart-guide' x1='{$cx}' y1='" . $num($yTop)
            . "' x2='{$cx}' y2='" . $num($yBottom) . "'/>";
        if ($href !== '') {
            // The link is the focus target, so the slot stays one tab stop.
            $out .= "<a href='" . htmlescape($href) . "'{$tip}><title>" . htmlescape($plain) . "</title>{$rect}/></a>";
        } else {
            $out .= $rect . " tabindex='0'{$tip}><title>" . htmlescape($plain) . "</title></rect>";
        }
        return $out . "</g>";
    }

    /**
     * The figures behind a chart as a table, for screen readers and for
     * copying. The first cell of every row is its header.
     *
     * @param string[]            $head column headers
     * @param array<int,string[]> $rows
     */
    private static function chartTable(string $caption, array $head, array $rows): string
    {
        $out = "<details class='sprint-chart-table'><summary>" . htmlescape(__('Show as table', 'sprint')) . "</summary>"
            . "<div class='table-responsive'><table class='table table-sm align-middle mb-0'>"
            . "<caption class='visually-hidden'>" . htmlescape($caption) . "</caption><thead><tr>";
        foreach (array_values($head) as $i => $cell) {
            $out .= "<th scope='col'" . ($i > 0 ? " class='text-end'" : '') . ">" . htmlescape((string)$cell) . "</th>";
        }
        $out .= "</tr></thead><tbody>";
        foreach ($rows as $row) {
            $out .= "<tr>";
            foreach (array_values($row) as $i => $cell) {
                $out .= $i === 0
                    ? "<th scope='row' class='fw-normal'>" . htmlescape((string)$cell) . "</th>"
                    : "<td class='text-end'>" . htmlescape((string)$cell) . "</td>";
            }
            $out .= "</tr>";
        }
        return $out . "</tbody></table></div></details>";
    }

    /** The block shown by a per-sprint chart that has no sprint to draw. */
    private static function chartEmptyState(string $icon = 'fas fa-chart-column'): string
    {
        return SprintChart::emptyState(
            $icon,
            __('No sprints to show yet.', 'sprint'),
            __('There is no completed sprint in this selection. Choose a longer period or clear a filter.', 'sprint')
        );
    }

    /**
     * Grouped bars per sprint, a dashed reference on the same scale, and an
     * optional second measure. That second measure has its own unit, so it is
     * not drawn over the bars: it gets its own strip below them, on the same
     * x-axis with its own labelled scale.
     *
     * @param array<int,string> $labels
     * @param array<int,array{label:string,color:string,values:array}> $series
     * @param array{label:string,color:string,values:array,suffix:string,max:int}|null $line
     * @param array{label:string,value:float,color:string}|null $reference
     * @param array<int,string> $links  sprint URL per slot
     * @param string            $title  chart title for the accessible summary
     */
    private static function renderGroupedBars(array $labels, array $series, ?array $line = null, ?array $reference = null, array $links = [], string $title = ''): void
    {
        $labels = array_values($labels);
        $series = array_values($series);
        $n      = count($labels);
        if ($n === 0) {
            echo self::chartEmptyState();
            return;
        }

        $num    = [SprintChart::class, 'num'];
        $w      = 900;
        $padL   = 48;
        $padR   = 20;
        $padT   = 20;
        // With a strip below, the bars give up some height to it.
        $plotH  = $line !== null ? 150 : 190;
        $slot   = ($w - $padL - $padR) / $n;
        $axis   = SprintChart::axisLabels($labels, $slot);
        $yB     = $padT + $plotH;
        // Strip of the second measure: title, then its own plot.
        $sTop   = $yB + 42;
        $sBot   = $sTop + 40;
        $axisY  = $line !== null ? $sBot : $yB;
        $h      = (int)ceil($axisY + $axis['height'] + 8);
        $xAt    = static fn(int $i): float => $padL + $slot * ($i + 0.5);

        // Counts and story points get whole steps, days keep one decimal.
        $maxLeft  = 0.0;
        $decimals = false;
        foreach ($series as $set) {
            foreach ($set['values'] as $value) {
                $value    = (float)$value;
                $maxLeft  = max($maxLeft, $value);
                $decimals = $decimals || abs($value - round($value)) > 0.001;
            }
        }
        if ($reference !== null) {
            $maxLeft = max($maxLeft, (float)$reference['value']);
        }
        $scale = SprintChart::niceScale($maxLeft, 4, !$decimals);
        $fmtV  = static fn(float $v): string => $decimals
            ? rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.')
            : (string)(int)round($v);
        $yAt   = static fn(float $v): float => SprintChart::yAt($v, $scale, $padT, $yB);

        $count   = count($series);
        $gap     = 3;
        $barW    = max(2.0, min(26.0, ($slot * 0.72 - ($count - 1) * $gap) / max(1, $count)));
        $groupW  = $count * $barW + ($count - 1) * $gap;
        // A figure on every bar only while it fits the bar.
        $labelled = $barW >= 13;

        $suffix  = $line !== null ? (string)($line['suffix'] ?? '') : '';
        $lineMax = $line !== null ? max(1.0, (float)$line['max']) : 1.0;
        $lineVal = static fn(int $i): float => (float)($line['values'][$i] ?? 0);
        $lineFmt = static fn(float $v): string => round($v) . $suffix;

        $last    = $n - 1;
        $summary = [];
        foreach ($series as $set) {
            $summary[] = $set['label'] . ' ' . $fmtV((float)($set['values'][$last] ?? 0));
        }
        if ($line !== null) {
            $summary[] = $line['label'] . ' ' . $lineFmt($lineVal($last));
        }

        echo "<div data-chart-scope>";
        echo "<div class='sprint-chart-box'>";
        echo SprintChart::svgOpen(sprintf(
            __('%1$s. Latest sprint %2$s: %3$s.', 'sprint'),
            $title !== '' ? $title : (string)($series[0]['label'] ?? ''),
            $labels[$last],
            implode(', ', $summary)
        ), $w, $h, 'sprint-overview-chart');
        echo SprintChart::yAxis($scale, $padL, $w - $padR, $padT, $yB, $fmtV);
        for ($i = 0; $i < $n; $i++) {
            echo SprintChart::axisLabel($axis, $i, $xAt($i), $axisY);
        }

        foreach ($series as $index => $set) {
            $color = htmlescape((string)$set['color']);
            echo "<g class='sprint-chart-series' data-series='b" . (int)$index . "'>";
            for ($i = 0; $i < $n; $i++) {
                $value = (float)($set['values'][$i] ?? 0);
                if ($value <= 0) {
                    continue;
                }
                $x = $xAt($i) - $groupW / 2 + $index * ($barW + $gap);
                $y = $yAt($value);
                echo "<rect x='" . $num($x) . "' y='" . $num($y) . "' width='" . $num($barW) . "' height='"
                    . $num(max(1.0, $yB - $y)) . "' rx='2' fill='{$color}'/>";
                if ($labelled) {
                    echo "<text class='sprint-chart-value' x='" . $num($x + $barW / 2) . "' y='" . $num($y - 4)
                        . "' text-anchor='middle'>" . htmlescape($fmtV($value)) . "</text>";
                }
            }
            echo "</g>";
        }

        if ($reference !== null && (float)$reference['value'] > 0) {
            $y = $num($yAt((float)$reference['value']));
            echo "<line class='sprint-chart-refline' x1='{$padL}' y1='{$y}' x2='" . ($w - $padR) . "' y2='{$y}' stroke='"
                . htmlescape((string)$reference['color']) . "'/>";
        }

        if ($line !== null) {
            $color   = htmlescape((string)$line['color']);
            $stripAt = static fn(float $v): float => $sBot - ($sBot - $sTop) * (max(0.0, min($v, $lineMax)) / $lineMax);
            echo "<text class='sprint-chart-label sprint-chart-striptitle' x='{$padL}' y='" . $num($yB + 17) . "'>"
                . htmlescape((string)$line['label']) . "</text>";
            foreach ([0.0, $lineMax / 2, $lineMax] as $tick) {
                $y = $stripAt($tick);
                echo "<line class='" . ($tick === 0.0 ? 'sprint-chart-axis' : 'sprint-chart-grid') . "' x1='{$padL}' y1='"
                    . $num($y) . "' x2='" . ($w - $padR) . "' y2='" . $num($y) . "'/>";
                if ($tick !== $lineMax / 2) {
                    echo "<text class='sprint-chart-label' x='" . ($padL - 8) . "' y='" . $num($y + 3.5)
                        . "' text-anchor='end'>" . htmlescape($lineFmt($tick)) . "</text>";
                }
            }
            $path = '';
            for ($i = 0; $i < $n; $i++) {
                $path .= ($i === 0 ? 'M' : 'L') . $num($xAt($i)) . ',' . $num($stripAt($lineVal($i))) . ' ';
            }
            echo "<g class='sprint-chart-series' data-series='line'>";
            if ($n > 1) {
                echo "<path class='sprint-chart-line' d='" . trim($path) . "' stroke='{$color}'/>";
            }
            for ($i = 0; $i < $n; $i++) {
                $y = $stripAt($lineVal($i));
                echo SprintChart::marker($count, $xAt($i), $y, 3.4, "class='sprint-chart-point' fill='{$color}'");
                // Every point while there is room, else the two ends.
                if ($slot >= 34 || $i === 0 || $i === $last) {
                    echo "<text class='sprint-chart-value' x='" . $num($xAt($i)) . "' y='" . $num($y - 7)
                        . "' text-anchor='middle'>" . htmlescape($lineFmt($lineVal($i))) . "</text>";
                }
            }
            echo "</g>";
        }

        // One hover and focus target per sprint over the full slot, on top.
        for ($i = 0; $i < $n; $i++) {
            $rows = [];
            foreach ($series as $set) {
                $rows[] = [(string)$set['label'], $fmtV((float)($set['values'][$i] ?? 0)), (string)$set['color']];
            }
            if ($line !== null) {
                $rows[] = [(string)$line['label'], $lineFmt($lineVal($i)), (string)$line['color']];
            }
            echo self::chartSlot($padL + $slot * $i, $slot, $padT, $axisY, $labels[$i], $rows, (string)($links[$i] ?? ''));
        }
        echo "</svg></div>";

        $legend = [];
        foreach ($series as $index => $set) {
            $legend[] = ['label' => (string)$set['label'], 'color' => (string)$set['color'], 'mark' => 'bar', 'key' => 'b' . (int)$index];
        }
        if ($line !== null) {
            $legend[] = ['label' => (string)$line['label'], 'color' => (string)$line['color'], 'mark' => 'line', 'shape' => $count, 'key' => 'line'];
        }
        if ($reference !== null) {
            $legend[] = ['label' => (string)$reference['label'], 'color' => (string)$reference['color'], 'mark' => 'ref'];
        }
        // Isolating needs something to isolate from.
        echo SprintChart::legend($legend, $count + ($line !== null ? 1 : 0) > 1);

        $head = [Sprint::getTypeName(1)];
        foreach ($series as $set) {
            $head[] = (string)$set['label'];
        }
        if ($line !== null) {
            $head[] = (string)$line['label'];
        }
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $row = [$labels[$i]];
            foreach ($series as $set) {
                $row[] = $fmtV((float)($set['values'][$i] ?? 0));
            }
            if ($line !== null) {
                $row[] = $lineFmt($lineVal($i));
            }
            $rows[] = $row;
        }
        echo self::chartTable($title !== '' ? $title : (string)($series[0]['label'] ?? ''), $head, $rows);
        echo "</div>";
    }

    /** Horizontal ranking bars, largest first. */
    private static function renderRankedBars(array $rows, string $color, string $unit): void
    {
        if (empty($rows)) {
            echo "<div class='text-muted py-3'>" . __('No data for this period.', 'sprint') . "</div>";
            return;
        }

        $max = 1.0;
        foreach ($rows as $row) {
            $max = max($max, (float)$row['value']);
        }

        echo "<table class='table table-vcenter mb-0 sprint-overview-ranked'><tbody>";
        foreach ($rows as $row) {
            $share = number_format(((float)$row['value'] / $max) * 100, 1, '.', '');
            $url = '';
            if (!empty($row['filter_key']) && $row['filter_value'] !== '') {
                $query = $_GET;
                $query['section'] = self::SECTION_OVERVIEW;
                $query[$row['filter_key']] = $row['filter_value'];
                $url = Plugin::getWebDir('sprint') . '/front/sprint.php?' . http_build_query($query);
            }
            echo "<tr>";
            echo "<td class='sprint-overview-ranked-label'>" . ($url !== '' ? "<a href='" . htmlescape($url) . "'>" : '') . htmlescape($row['label']) . ($url !== '' ? '</a>' : '') . "</td>";
            echo "<td><div class='progress progress-sm'><div class='progress-bar bg-" . $color . "' "
                . "role='progressbar' style='width:{$share}%' aria-valuenow='{$share}' aria-valuemin='0' aria-valuemax='100'></div></div></td>";
            echo "<td class='text-end text-nowrap'>" . (int)$row['value']
                . " <span class='text-muted sprint-small'>" . htmlescape($unit) . "</span></td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }
}
