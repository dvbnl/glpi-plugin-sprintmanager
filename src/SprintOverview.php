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

    /** Two-column layout: navigation left, page content right. Close with {@see navEnd()}. */
    public static function navStart(string $active): void
    {
        echo "<div class='row g-3 sprint-nav-layout'>";
        echo "<div class='col-12 col-md-3 col-xl-2'>";
        echo "<div class='card'><div class='list-group list-group-flush'>";
        foreach (self::navItems() as $key => $item) {
            $class = 'list-group-item list-group-item-action' . ($key === $active ? ' active' : '');
            echo "<a href='" . htmlescape($item['url']) . "' class='" . $class . "'>"
                . "<i class='" . htmlescape($item['icon']) . " me-2'></i>" . htmlescape($item['label']) . "</a>";
        }
        echo "</div></div></div>";
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
        $filters  = self::filtersFromRequest();
        $data     = self::collect($period, false, $filters);
        $previous = $period === self::PERIOD_ALL ? ['sprint_count' => 0] : self::collect($period, true, $filters);

        echo "<h2 class='mb-1'><i class='" . self::getIcon() . " me-2'></i>"
            . __('Sprint overview', 'sprint') . "</h2>";
        echo "<div class='text-muted mb-3'>"
            . __('Trends across completed sprints, filtered on their end date.', 'sprint') . "</div>";

        self::renderPeriodSelector($period, $filters);
        self::renderFilterBar($filters);
        self::renderLiveSprints($filters);
        self::renderTiles($data, $previous, $period);

        if ($data['sprint_count'] === 0) {
            echo "<div class='alert alert-info'><i class='fas fa-info-circle me-2'></i>"
                . __('No completed sprints in this period.', 'sprint') . "</div>";
            self::renderComparison($data, $period, $filters);
            self::renderOverviewBehaviour();
            return;
        }

        $chart = self::trimToChartWindow($data);
        if ($chart['dropped'] > 0) {
            echo "<div class='text-muted small mb-2'><i class='fas fa-info-circle me-1'></i>"
                . sprintf(
                    __('Charts show the last %1$d of %2$d sprints in this period. The tiles cover all of them.', 'sprint'),
                    self::CHART_SPRINTS,
                    $data['sprint_count']
                ) . "</div>";
        }

        self::renderCard(
            __('Velocity trend', 'sprint'),
            'fas fa-chart-column',
            __('Completed story points per sprint, with predictability % (line).', 'sprint'),
            function () use ($chart, $data) {
                self::renderGroupedBars(
                    $chart['labels'],
                    [['label' => __('Completed points', 'sprint'), 'color' => '#0d6efd', 'values' => $chart['series']['pts_done']]],
                    [
                        'label'  => __('Predictability %', 'sprint'),
                        'color'  => '#20c997',
                        'values' => $chart['series']['predictability'],
                        'suffix' => '%',
                        'max'    => 100,
                    ],
                    [
                        'label' => sprintf(__('Avg points: %s', 'sprint'), number_format($data['avg_velocity'], 1)),
                        'value' => $data['avg_velocity'],
                        'color' => '#0d6efd',
                    ],
                    $chart['links']
                );
            }
        );

        self::renderCard(
            __('Planned vs. delivered', 'sprint'),
            'fas fa-bullseye',
            __('Story points taken into the sprint against the points actually completed.', 'sprint'),
            function () use ($chart) {
                self::renderGroupedBars($chart['labels'], [
                    ['label' => __('Planned points', 'sprint'), 'color' => '#adb5bd', 'values' => $chart['series']['pts_planned']],
                    ['label' => __('Completed points', 'sprint'), 'color' => '#198754', 'values' => $chart['series']['pts_done']],
                    ['label' => __('Carry-over items', 'sprint'), 'color' => '#dc3545', 'values' => $chart['series']['carryover']],
                ], null, null, $chart['links']);
            }
        );

        self::renderCategoryDelivery($data);
        self::renderCategoryTrend($data);

        self::renderCard(
            __('Fastlane & adhoc', 'sprint'),
            'fas fa-bolt',
            __('Interrupt work per sprint: completed fastlane items, adhoc items and the allocated fastlane capacity % (line).', 'sprint'),
            function () use ($chart) {
                self::renderGroupedBars(
                    $chart['labels'],
                    [
                        ['label' => __('Completed fastlane items', 'sprint'), 'color' => '#fd7e14', 'values' => $chart['series']['fl_done']],
                        ['label' => __('Adhoc items', 'sprint'), 'color' => '#d63384', 'values' => $chart['series']['adhoc']],
                    ],
                    [
                        'label'  => __('Fastlane capacity %', 'sprint'),
                        'color'  => '#fd7e14',
                        'values' => $chart['series']['fl_cap'],
                        'suffix' => '%',
                        'max'    => max(20, (int)ceil(max(array_merge([0], $chart['series']['fl_cap'])) / 20) * 20),
                    ],
                    null,
                    $chart['links']
                );
            }
        );

        echo "<div class='row g-3 mb-3'>";
        self::renderCard(
            __('Completed points per member', 'sprint'),
            'fas fa-users',
            '',
            function () use ($data) {
                self::renderRankedBars($data['per_member'], 'blue', __('points', 'sprint'));
            },
            'col-12 col-lg-6'
        );
        self::renderCard(
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

        self::renderCard(
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
                        ? "<i class='fas fa-turn-up fa-rotate-90 text-muted me-1' style='margin-left:18px;font-size:0.8em;'></i>"
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
                        . "<span class='text-nowrap small'>{$pct}%</span>"
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

    /** Colour + glyph for a load drift verdict; the glyph is what carries it under CVD. */
    private static function driftStyle(string $direction): array
    {
        return match ($direction) {
            'improving' => ['#198754', '↓', __('Improving', 'sprint')],
            'worsening' => ['#dc3545', '↑', __('Worsening', 'sprint')],
            default     => ['#6c757d', '→', __('Stable', 'sprint')],
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
        self::renderCard(
            __('Workload trend', 'sprint'),
            'fas fa-chart-line',
            __('Average allocated load across the members of each sprint. The dashed line is 100% capacity — above it the team is booked beyond availability.', 'sprint'),
            function () use ($trend) {
                $labels = $trend['labels'] ?? [];
                $team   = $trend['team'] ?? [];
                if (count(array_filter($team, static fn($v) => $v !== null)) < 2) {
                    echo "<div class='text-muted py-3'>" . __('Not enough sprints in this period to show a trend.', 'sprint') . "</div>";
                    return;
                }

                // Same window as the other charts, so the x-axis lines up.
                $labels = array_slice($labels, -self::CHART_SPRINTS);
                $team   = array_slice($team, -self::CHART_SPRINTS);
                $over   = array_slice($trend['over'] ?? [], -self::CHART_SPRINTS);

                [$color, $glyph, $verdict] = self::driftStyle((string)($trend['direction'] ?? 'stable'));
                echo "<div class='d-flex align-items-baseline gap-2 mb-3'>";
                echo "<span style='font-size:1.75rem;font-weight:600;color:" . $color . ";'>" . $glyph . "</span>";
                echo "<span style='font-size:1.5rem;font-weight:600;'>" . htmlescape(self::formatDrift((float)($trend['drift'] ?? 0))) . "</span>";
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

    /** Single-series load line; $over feeds the per-point tooltip. */
    private static function renderLoadLine(array $labels, array $values, array $over = []): void
    {
        $n = count($labels);
        if ($n === 0) {
            return;
        }

        $tilted = $n > 8;
        $width = 900; $height = $tilted ? 300 : 260;
        $padL = 44; $padR = 20; $padT = 18; $padB = $tilted ? 86 : 46;
        $plotW = $width - $padL - $padR;
        $plotH = $height - $padT - $padB;

        $peak     = max(100.0, max(array_map(static fn($v) => (float)$v, array_filter($values, static fn($v) => $v !== null)) ?: [0.0]));
        $tickStep = max(25, (int)ceil($peak / 4 / 25) * 25);
        $yMax     = $tickStep * 4;

        $slot = $plotW / max(1, $n);
        $fmt  = fn(float $v) => number_format($v, 2, '.', '');
        $yAt  = fn(float $v) => $padT + $plotH - ($plotH * ($v / $yMax));
        $halo = "paint-order:stroke;stroke:var(--tblr-bg-surface,#fff);stroke-width:3.5px;stroke-linejoin:round;";
        $grid = "var(--tblr-border-color,#e9ecef)";
        $ink  = "var(--tblr-secondary,#6c757d)";

        echo "<div style='overflow-x:auto;'>";
        echo "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$width} {$height}' role='img' "
            . "style='width:100%;height:auto;min-width:520px;font-family:sans-serif;font-size:11px;'>";

        for ($t = 0; $t <= 4; $t++) {
            $value = (int)round($yMax * $t / 4);
            $y     = $fmt($yAt($value));
            echo "<line x1='{$padL}' y1='{$y}' x2='" . ($padL + $plotW) . "' y2='{$y}' stroke='{$grid}' stroke-width='1' />";
            echo "<text x='" . ($padL - 6) . "' y='" . $fmt($yAt($value) + 3) . "' text-anchor='end' fill='{$ink}'>{$value}%</text>";
        }

        $refY = $fmt($yAt(100.0));
        echo "<line x1='{$padL}' y1='{$refY}' x2='" . ($padL + $plotW) . "' y2='{$refY}' stroke='#dc3545' "
            . "stroke-width='1.2' stroke-dasharray='5,4' opacity='0.7' />";

        $points = [];
        for ($i = 0; $i < $n; $i++) {
            $cx = $padL + ($slot * $i) + ($slot / 2);
            if ($values[$i] !== null) {
                $points[$i] = ['x' => $cx, 'y' => $yAt((float)$values[$i]), 'v' => (float)$values[$i]];
            }

            $label  = mb_strlen($labels[$i]) > 16 ? (mb_substr($labels[$i], 0, 15) . '…') : $labels[$i];
            $labelX = $fmt($cx);
            $labelY = $padT + $plotH + ($tilted ? 14 : 16);
            $rotate = $tilted ? " transform='rotate(-35 {$labelX} {$labelY})'" : '';
            echo "<text x='{$labelX}' y='{$labelY}' text-anchor='" . ($tilted ? 'end' : 'middle') . "' fill='{$ink}'{$rotate}>"
                . htmlescape($label) . "<title>" . htmlescape($labels[$i]) . "</title></text>";
        }

        // Nulls break the polyline into segments instead of bridging the gap.
        $segment = [];
        $flush   = function () use (&$segment, $fmt) {
            if (count($segment) > 1) {
                echo "<polyline points='" . implode(' ', $segment) . "' fill='none' stroke='#0d6efd' "
                    . "stroke-width='2' stroke-linejoin='round' stroke-linecap='round' />";
            }
            $segment = [];
        };
        for ($i = 0; $i < $n; $i++) {
            if (!isset($points[$i])) {
                $flush();
                continue;
            }
            $segment[] = $fmt($points[$i]['x']) . ',' . $fmt($points[$i]['y']);
        }
        $flush();

        $lastIndex = array_key_last($points);
        foreach ($points as $i => $point) {
            $tip = $labels[$i] . ' — ' . number_format($point['v'], 1, ',', '') . '%';
            if (($over[$i] ?? 0) > 0) {
                $tip .= ' · ' . sprintf(
                    _n('%d member over capacity', '%d members over capacity', (int)$over[$i], 'sprint'),
                    (int)$over[$i]
                );
            }
            // 2px surface ring so a marker on the reference line stays readable.
            echo "<circle cx='" . $fmt($point['x']) . "' cy='" . $fmt($point['y']) . "' r='4' fill='#0d6efd' "
                . "stroke='var(--tblr-bg-surface,#fff)' stroke-width='2'>"
                . "<title>" . htmlescape($tip) . "</title></circle>";
            // Direct-label the ends only — a number on every point is noise.
            if ($i === array_key_first($points) || $i === $lastIndex) {
                echo "<text x='" . $fmt($point['x']) . "' y='" . $fmt(max($point['y'] - 12, $padT + 10)) . "' "
                    . "text-anchor='middle' fill='#0d6efd' font-weight='600' style='{$halo}'>"
                    . round($point['v']) . "%</text>";
            }
        }

        echo "</svg></div>";
    }

    /** Inline sparkline for one member's per-sprint load, with the 100% line. */
    private static function renderLoadSparkline(array $values, float $yMax): void
    {
        $n = count($values);
        $filled = array_filter($values, static fn($v) => $v !== null);
        if ($n < 2 || count($filled) < 2) {
            echo "<span class='text-muted'>&mdash;</span>";
            return;
        }

        $w = 96; $h = 26; $pad = 3;
        $plotH = $h - ($pad * 2);
        $step  = $w / max(1, $n - 1);
        $fmt   = fn(float $v) => number_format($v, 2, '.', '');
        $yAt   = fn(float $v) => $pad + $plotH - ($plotH * (min($v, $yMax) / $yMax));

        echo "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$w} {$h}' aria-hidden='true' "
            . "style='width:{$w}px;height:{$h}px;overflow:visible;'>";
        $refY = $fmt($yAt(100.0));
        echo "<line x1='0' y1='{$refY}' x2='{$w}' y2='{$refY}' stroke='#dc3545' stroke-width='1' stroke-dasharray='3,3' opacity='0.55' />";

        $segment = [];
        $flush   = function () use (&$segment) {
            if (count($segment) > 1) {
                echo "<polyline points='" . implode(' ', $segment) . "' fill='none' stroke='#0d6efd' "
                    . "stroke-width='1.5' stroke-linejoin='round' stroke-linecap='round' />";
            }
            $segment = [];
        };
        $last = null;
        foreach ($values as $i => $value) {
            if ($value === null) {
                $flush();
                continue;
            }
            $x = $i * $step;
            $y = $yAt((float)$value);
            $segment[] = $fmt($x) . ',' . $fmt($y);
            $last = [$x, $y];
        }
        $flush();
        if ($last !== null) {
            echo "<circle cx='" . $fmt($last[0]) . "' cy='" . $fmt($last[1]) . "' r='2' fill='#0d6efd' />";
        }
        echo "</svg>";
    }

    /** Allocated capacity per member, with the overflow highlighted. */
    private static function renderWorkload(array $data): void
    {
        self::renderCard(
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

                    [$driftColor, $driftGlyph, $driftLabel] = self::driftStyle((string)($row['direction'] ?? 'stable'));
                    echo "<td><div class='d-flex align-items-center gap-2'>";
                    self::renderLoadSparkline($row['loads'] ?? [], $sparkMax);
                    echo "<span class='text-nowrap' title='" . htmlescape($driftLabel) . "'>"
                        . "<span style='color:" . $driftColor . ";font-weight:600;'>" . $driftGlyph . "</span> "
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
        echo "<div class='btn-group'>";
        foreach (self::getPeriods() as $key => $label) {
            $class = 'btn btn-sm ' . ($key === $period ? 'btn-primary' : 'btn-outline-secondary');
            $url   = $self . '?' . http_build_query(array_merge(
                ['section' => self::SECTION_OVERVIEW, 'period' => $key],
                array_filter($filters, static fn($value) => $value !== '' && $value !== 0)
            ));
            echo "<a class='" . $class . "' href='" . htmlescape($url) . "'>" . htmlescape($label) . "</a>";
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

    private static function renderFilterBar(array $filters): void
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
            $html = "<select class='form-select form-select-sm' name='" . htmlescape($name) . "'><option value=''>" . htmlescape($empty) . '</option>';
            foreach ($options as $value => $label) $html .= "<option value='" . htmlescape((string)$value) . "'" . ((string)$selected === (string)$value ? ' selected' : '') . '>' . htmlescape((string)$label) . '</option>';
            return $html . '</select>';
        };

        echo "<details class='card mb-3 sprint-overview-filters'" . (array_filter($filters, static fn($v, $k) => !in_array($k, ['view'], true) && $v !== '' && $v !== 0, ARRAY_FILTER_USE_BOTH) ? ' open' : '') . "><summary class='card-header cursor-pointer'><strong><i class='fas fa-filter me-2'></i>" . __('Filters and comparison', 'sprint') . "</strong></summary><div class='card-body'>";
        echo "<form method='get' action='" . htmlescape($self) . "' class='row g-2'>" . Html::hidden('section', ['value' => self::SECTION_OVERVIEW]) . Html::hidden('period', ['value' => (string)($_GET['period'] ?? self::PERIOD_YEAR)]);
        echo "<div class='col-md-2'><label class='form-label'>" . __('From') . "</label><input class='form-control form-control-sm' type='date' name='from' value='" . htmlescape($filters['from']) . "'></div>";
        echo "<div class='col-md-2'><label class='form-label'>" . __('To') . "</label><input class='form-control form-control-sm' type='date' name='to' value='" . htmlescape($filters['to']) . "'></div>";
        echo "<div class='col-md-2'><label class='form-label'>" . __('Entity') . "</label>" . $select('entities_id', $entities, $filters['entities_id'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label'>" . __('Scrum Master', 'sprint') . "</label>" . $select('scrum_master', $masters, $filters['scrum_master'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label'>" . __('Team member', 'sprint') . "</label>" . $select('member_id', $members, $filters['member_id'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label'>" . __('Project') . "</label>" . $select('projects_id', $projects, $filters['projects_id'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label'>" . __('Template', 'sprint') . "</label>" . $select('template_id', $templates, $filters['template_id'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label'>" . __('Epic or theme', 'sprint') . "</label>" . $select('epic_id', $epics, $filters['epic_id'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label'>" . __('Category', 'sprint') . "</label>" . $select('category_id', $categories, $filters['category_id'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label'>" . __('Linked type', 'sprint') . "</label>" . $select('itemtype', array_combine(['Ticket', 'Change', 'Problem', 'ProjectTask'], ['Ticket', 'Change', 'Problem', 'Project task']), $filters['itemtype'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label'>" . __('Tag', 'sprint') . "</label>" . $select('tag', array_combine($tags, $tags), $filters['tag'], __('All')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label'>" . __('Compare period', 'sprint') . "</label>" . $select('compare_period', self::getPeriods(), $filters['compare_period'], __('None', 'sprint')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label'>" . __('Compare Scrum Master', 'sprint') . "</label>" . $select('compare_scrum_master', $masters, $filters['compare_scrum_master'], __('None', 'sprint')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label'>" . __('View', 'sprint') . "</label>" . $select('view', ['expanded' => __('Expanded', 'sprint'), 'compact' => __('Compact', 'sprint')], $filters['view'], __('Expanded', 'sprint')) . '</div>';
        echo "<div class='col-md-2'><label class='form-label'>" . __('Predictability below', 'sprint') . "</label><div class='input-group input-group-sm'><input class='form-control' type='number' min='0' max='100' name='predictability_below' value='" . ($filters['predictability_below'] ?: '') . "'><span class='input-group-text'>%</span></div></div>";
        echo "<div class='col-12 d-flex flex-wrap gap-3 mt-3'><label class='form-check'><input class='form-check-input' type='checkbox' name='blocked_only' value='1'" . ($filters['blocked_only'] ? ' checked' : '') . "><span class='form-check-label'>" . __('Only sprints with blocked work', 'sprint') . "</span></label><label class='form-check'><input class='form-check-input' type='checkbox' name='over_only' value='1'" . ($filters['over_only'] ? ' checked' : '') . "><span class='form-check-label'>" . __('Only sprints over capacity', 'sprint') . "</span></label></div>";
        echo "<div class='col-12 mt-3 d-flex flex-wrap gap-2'><button class='btn btn-primary btn-sm'><i class='fas fa-check me-1'></i>" . __('Apply filters', 'sprint') . "</button><a class='btn btn-outline-secondary btn-sm' href='" . htmlescape($self . '?section=' . self::SECTION_OVERVIEW) . "'>" . __('Reset', 'sprint') . "</a><button type='button' class='btn btn-outline-primary btn-sm' id='sprint-save-filter'><i class='fas fa-bookmark me-1'></i>" . __('Save filter', 'sprint') . "</button><select id='sprint-saved-filters' class='form-select form-select-sm' style='width:auto'><option value=''>" . __('Saved filters', 'sprint') . '</option></select></div>';
        Html::closeForm();
        $active = [];
        $labels = [
            'from' => __('From'), 'to' => __('To'), 'entities_id' => __('Entity'), 'scrum_master' => __('Scrum Master', 'sprint'),
            'member_id' => __('Team member', 'sprint'), 'projects_id' => __('Project'), 'template_id' => __('Template', 'sprint'),
            'epic_id' => __('Epic or theme', 'sprint'), 'category_id' => __('Category', 'sprint'),
            'itemtype' => __('Linked type', 'sprint'), 'tag' => __('Tag', 'sprint'),
            'blocked_only' => __('Blocked work', 'sprint'), 'over_only' => __('Over capacity', 'sprint'),
            'predictability_below' => __('Predictability below', 'sprint'),
        ];
        foreach ($labels as $key => $label) if (($filters[$key] ?? '') !== '' && ($filters[$key] ?? 0) !== 0) $active[] = "<span class='badge bg-blue-lt'>" . htmlescape($label) . ': ' . htmlescape((string)$filters[$key]) . '</span>';
        if ($active) echo "<div class='d-flex flex-wrap gap-1 mt-3'>" . implode('', $active) . '</div>';
        echo '</div></details>';
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
        if (!$sprints) return;
        echo "<div class='d-flex align-items-center justify-content-between mt-3 mb-2'><h3 class='m-0'><i class='fas fa-heart-pulse me-2 text-red'></i>" . __('Live sprint status', 'sprint') . "</h3><span class='text-muted small'>" . __('Kept separate from completed sprint statistics', 'sprint') . '</span></div>';
        echo "<div class='row g-2 mb-4'>";
        foreach ($sprints as $sprint) {
            $sid = (int)$sprint['id'];
            $items = self::filterItems(self::fetchItems([$sid]), $filters);
            $total = $done = $blocked = $dependency = $remainingPoints = $totalPoints = 0;
            $byStatus = [];
            foreach ($items as $item) {
                $total++;
                $byStatus[(string)$item['status']] = ($byStatus[(string)$item['status']] ?? 0) + 1;
                $points = max(0, (int)$item['story_points']);
                $totalPoints += $points;
                if ((string)$item['status'] === SprintItem::STATUS_DONE) $done++; else $remainingPoints += $points;
                if ((string)$item['status'] === SprintItem::STATUS_BLOCKED || (int)$item['is_blocked'] === 1) $blocked++;
                if ((string)$item['status'] === SprintItem::STATUS_DEPENDENCY) $dependency++;
            }
            $limits = SprintAgility::getWipLimits($sprint);
            $wipBreaches = 0;
            foreach ($limits as $status => $limit) if ($limit > 0 && ($byStatus[$status] ?? 0) > $limit) $wipBreaches++;
            $start = strtotime((string)$sprint['date_start']) ?: time();
            $end = strtotime((string)$sprint['date_end']) ?: $start + DAY_TIMESTAMP;
            $elapsed = max(0, min(100, (int)round(100 * (time() - $start) / max(1, $end - $start))));
            $progress = $totalPoints > 0 ? (int)round(100 * ($totalPoints - $remainingPoints) / $totalPoints) : ($total > 0 ? (int)round(100 * $done / $total) : 0);
            $expected = $elapsed > 0 ? min($totalPoints, (int)round(($totalPoints - $remainingPoints) * 100 / $elapsed)) : 0;
            $nextMeeting = '';
            foreach ($DB->request(['SELECT' => ['date_meeting'], 'FROM' => SprintMeeting::getTable(), 'WHERE' => ['plugin_sprint_sprints_id' => $sid, ['date_meeting' => ['>=', date('Y-m-d H:i:s')]]], 'ORDER' => ['date_meeting ASC'], 'LIMIT' => 1]) as $meeting) $nextMeeting = (string)$meeting['date_meeting'];
            $url = Sprint::getFormURLWithID($sid);
            echo "<div class='col-12 col-xl-6'><a class='card card-sm h-100 text-reset text-decoration-none' href='" . htmlescape($url) . "'><div class='card-body'><div class='d-flex justify-content-between'><strong>" . htmlescape((string)$sprint['name']) . "</strong><span>" . $progress . "%</span></div>";
            echo "<div class='progress progress-sm my-2'><div class='progress-bar bg-blue' style='width:" . min(100, $progress) . "%'></div><span class='position-absolute border-start border-dark' style='left:" . $elapsed . "%'></span></div>";
            echo "<div class='small text-muted mb-2'>" . sprintf(__('Progress %1$d%% · time elapsed %2$d%% · expected %3$d story points', 'sprint'), $progress, $elapsed, $expected) . "</div><div class='d-flex flex-wrap gap-2'>";
            echo "<span class='badge bg-blue-lt'>" . sprintf(__('%d points remaining', 'sprint'), $remainingPoints) . "</span><span class='badge bg-red-lt'>" . sprintf(__('%d blocked', 'sprint'), $blocked) . "</span><span class='badge bg-teal-lt'>" . sprintf(__('%d dependencies', 'sprint'), $dependency) . "</span><span class='badge bg-orange-lt'>" . sprintf(__('%d WIP breaches', 'sprint'), $wipBreaches) . '</span>';
            if ($nextMeeting !== '') echo "<span class='badge bg-purple-lt'><i class='fas fa-calendar me-1'></i>" . Html::convDateTime($nextMeeting) . '</span>';
            echo '</div></div></a></div>';
        }
        echo '</div>';
    }

    /** KPI tiles with an optional delta against the previous period. */
    private static function renderTiles(array $data, array $previous, string $period): void
    {
        $showDelta = $period !== self::PERIOD_ALL && $previous['sprint_count'] > 0;

        $tiles = [
            [
                'icon'  => 'fas fa-flag-checkered',
                'color' => 'blue',
                'value' => (string)$data['sprint_count'],
                'label' => __('Completed sprints', 'sprint'),
                'delta' => $showDelta ? self::delta($data['sprint_count'], $previous['sprint_count']) : '',
            ],
            [
                'icon'  => 'fas fa-star',
                'color' => 'green',
                'value' => (string)$data['pts_done'],
                'label' => __('Completed points', 'sprint'),
                'delta' => $showDelta ? self::delta($data['pts_done'], $previous['pts_done']) : '',
            ],
            [
                'icon'  => 'fas fa-gauge-high',
                'color' => 'purple',
                'value' => number_format($data['avg_velocity'], 1),
                'label' => __('Avg velocity per sprint', 'sprint'),
                'delta' => $showDelta ? self::delta($data['avg_velocity'], $previous['avg_velocity']) : '',
            ],
            [
                'icon'  => 'fas fa-bullseye',
                'color' => 'teal',
                'value' => $data['predictability'] . '%',
                'label' => __('Predictability', 'sprint'),
                'delta' => $showDelta ? self::delta($data['predictability'], $previous['predictability']) : '',
            ],
            [
                'icon'  => 'fas fa-list-check',
                'color' => 'cyan',
                'value' => $data['items_done'] . '/' . $data['items_total'],
                'label' => __('Items completed', 'sprint'),
                'delta' => '',
            ],
            [
                'icon'  => 'fas fa-wave-square',
                'color' => 'yellow',
                'value' => $data['consistency'] . '%',
                'label' => __('Velocity consistency', 'sprint'),
                'delta' => '',
            ],
            [
                'icon'  => 'fas fa-bolt',
                'color' => 'orange',
                'value' => SprintMember::formatCapacity($data['avg_fl_cap']) . '%',
                'label' => __('Avg fastlane capacity', 'sprint'),
                'delta' => '',
            ],
            [
                'icon'  => 'fas fa-plus-circle',
                'color' => 'pink',
                'value' => (string)$data['adhoc'],
                'label' => __('Adhoc items', 'sprint'),
                'delta' => $showDelta ? self::delta($data['adhoc'], $previous['adhoc'], true) : '',
            ],
            [
                'icon'  => 'fas fa-arrow-right-arrow-left',
                'color' => 'red',
                'value' => (string)$data['carryover'],
                'label' => __('Carry-over items', 'sprint'),
                'delta' => $showDelta ? self::delta($data['carryover'], $previous['carryover'], true) : '',
            ],
            [
                'icon'  => 'fas fa-ban',
                'color' => 'secondary',
                'value' => (string)max($data['blocked'], $data['flow']['blocked_items']),
                'label' => __('Blocked items', 'sprint'),
                'delta' => '',
            ],
            [
                'icon'  => 'fas fa-stopwatch',
                'color' => 'azure',
                'value' => number_format($data['flow']['cycle_days'], 1),
                'label' => __('Avg cycle time (days)', 'sprint'),
                'delta' => $data['flow']['measured'] > 0
                    ? "<div class='small text-muted'>" . sprintf(__('over %d items', 'sprint'), $data['flow']['measured']) . "</div>"
                    : '',
            ],
            [
                'icon'  => 'fas fa-hourglass-half',
                'color' => 'indigo',
                'value' => number_format($data['flow']['blocked_days'], 1),
                'label' => __('Days spent blocked', 'sprint'),
                'delta' => '',
            ],
            [
                'icon'  => 'fas fa-rotate-left',
                'color' => 'red',
                'value' => $data['flow']['rework_pct'] . '%',
                'label' => __('Rework rate', 'sprint'),
                'delta' => '',
            ],
            [
                'icon'  => 'fas fa-inbox',
                'color' => 'lime',
                'value' => number_format($data['requests']['wait_days'], 1),
                'label' => __('Avg approval wait (days)', 'sprint'),
                'delta' => $data['requests']['total'] > 0
                    ? "<div class='small text-muted'>" . sprintf(
                        __('%1$d requests, %2$d%% accepted', 'sprint'),
                        $data['requests']['total'],
                        $data['requests']['accepted_pct']
                    ) . "</div>"
                    : '',
            ],
        ];

        echo "<div class='row g-2 mb-3'>";
        foreach ($tiles as $tile) {
            echo "<div class='col-12 col-sm-6 col-xl-3'>";
            echo "<div class='card card-sm h-100'><div class='card-body d-flex align-items-center'>";
            echo "<span class='avatar bg-" . $tile['color'] . "-lt me-3'><i class='" . $tile['icon'] . "'></i></span>";
            echo "<div class='sprint-overview-tile-content'>";
            echo "<div class='h3 m-0 text-nowrap'>" . htmlescape($tile['value']) . "</div>";
            echo "<div class='text-muted small'>" . htmlescape($tile['label']) . "</div>";
            echo $tile['delta'];
            echo "</div></div></div></div>";
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
            return "<div class='small text-muted'>" . __('unchanged vs. previous period', 'sprint') . "</div>";
        }
        $up    = $pct > 0;
        $good  = $lowerIsBetter ? !$up : $up;
        $arrow = $up ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down';
        return "<div class='small " . ($good ? 'text-success' : 'text-danger') . "'>"
            . "<i class='fas " . $arrow . "'></i> " . sprintf('%+.0f%%', $pct)
            . " <span class='text-muted'>" . __('vs. previous period', 'sprint') . "</span></div>";
    }

    private static function renderSprintTable(array $data): void
    {
        self::renderCard(
            __('Sprint details', 'sprint'),
            'fas fa-table',
            '',
            function () use ($data) {
                echo "<div style='overflow-x:auto;'>";
                echo "<table class='tab_cadre_fixe sprint-themed' style='margin:0;'>";
                echo "<tr class='tab_bg_2'>";
                echo "<th>" . Sprint::getTypeName(1) . "</th>";
                echo "<th>" . __('Ended', 'sprint') . "</th>";
                echo "<th>" . __('Planned points', 'sprint') . "</th>";
                echo "<th>" . __('Completed points', 'sprint') . "</th>";
                echo "<th>" . __('Predictability', 'sprint') . "</th>";
                echo "<th>" . __('Items', 'sprint') . "</th>";
                echo "<th>" . __('Fastlane', 'sprint') . "</th>";
                echo "<th>" . __('Adhoc', 'sprint') . "</th>";
                echo "<th>" . __('Carry-over items', 'sprint') . "</th>";
                echo "</tr>";
                foreach ($data['sprints'] as $row) {
                    echo "<tr class='tab_bg_1'>";
                    echo "<td><a href='" . Sprint::getFormURLWithID((int)$row['id']) . "'>"
                        . htmlescape((string)$row['name']) . "</a></td>";
                    echo "<td>" . Html::convDate((string)($row['date_end'] ?? '')) . "</td>";
                    echo "<td>" . (int)$row['pts_planned'] . "</td>";
                    echo "<td>" . (int)$row['pts_done'] . "</td>";
                    echo "<td>" . (int)$row['predictability'] . "%</td>";
                    echo "<td>" . (int)$row['items_done'] . "/" . (int)$row['items_total'] . "</td>";
                    echo "<td>" . (int)$row['fl_done'] . "/" . (int)$row['fl_items'] . "</td>";
                    echo "<td>" . (int)$row['adhoc'] . "</td>";
                    echo "<td>" . (int)$row['carryover'] . "</td>";
                    echo "</tr>";
                }
                echo "</table></div>";
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

            $catBySprint[$sid][$cid] ??= ['cap_planned' => 0.0, 'cap_actual' => 0.0, 'pts_planned' => 0, 'pts_done' => 0];
            $depCap = array_sum($depByUser[(int)$item['id']] ?? []);
            if ((int)($item['is_fastlane'] ?? 0) === 1) {
                $flItemCap = (float)($flCap[(int)$item['id']] ?? 0);
                $catBySprint[$sid][$cid]['cap_planned'] += $flItemCap;
                $catBySprint[$sid][$cid]['cap_actual']  += $flItemCap;
            } else {
                $catBySprint[$sid][$cid]['cap_planned'] += SprintItem::capacityFor($item, false) + $depCap;
                $catBySprint[$sid][$cid]['cap_actual']  += SprintItem::capacityFor($item, true) + $depCap;
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
                $sprintFlow = self::flowMetrics(array_values(array_filter($items, static fn($item) => (int)$item['plugin_sprint_sprints_id'] === $sid)));
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
        $out['workload']       = self::workload($sprints, $items, $flByUser, $depByUser);
        $out['workload_trend'] = self::workloadTrend($out['labels'], $out['workload']);

        // The delta badges don't use flow data, so skip the log work there.
        if (!$shift) {
            $out['flow']     = self::flowMetrics($items);
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
                'id', 'plugin_sprint_sprints_id', 'status', 'story_points', 'capacity', 'capacity_actual',
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
                if ($capacity > 0 && SprintMember::getUsedCapacityForUser($sid, $uid) > $capacity) $over[$sid] = true;
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
            'cap_actual'  => array_fill(0, count($window), 0.0),
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
                    $target['cap_actual'][$i]  += (float)$bucket['cap_actual'];
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
     * Line chart per category over the sprints — same interaction as the
     * team-activity chart (click a legend entry to isolate a line, hover to
     * preview). Metric picker: capacity % (planned, and actual when that
     * setting is on) or completed-vs-planned %.
     */
    private static function renderCategoryTrend(array $data): void
    {
        $trend = $data['category_trend'] ?? [];
        $rows  = $trend['rows'] ?? [];
        if (!$rows || count($trend['labels'] ?? []) === 0) {
            return;
        }
        $plannedActual = Config::isPlannedActualEnabled();

        self::renderCard(
            __('Category trend', 'sprint'),
            'fas fa-chart-line',
            __('Per backlog category across the completed sprints in this period: allocated capacity % per sprint (planned, or actual when that setting is on) or the completed-vs-planned share. Subcategories count towards their parent, or switch to the subcategory view to see them as separate lines. Click a legend entry to isolate a category.', 'sprint'),
            function () use ($trend, $rows, $plannedActual) {
                $labels  = $trend['labels'];
                $n       = count($labels);
                $detail  = $trend['detail'] ?? [];
                $hasSubs = (bool)array_filter($detail, static fn($r) => !empty($r['sub']));
                $levels  = ['top' => $rows];
                if ($hasSubs) {
                    $levels['sub'] = $detail;
                }
                $metrics = ['cap_planned' => __('Planned capacity %', 'sprint')];
                if ($plannedActual) {
                    $metrics['cap_actual'] = __('Actual capacity %', 'sprint');
                }
                $metrics['done_pct'] = __('Completed vs planned %', 'sprint');

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
                    echo "<select class='form-select form-select-sm sprint-category-trend-level' style='max-width:240px;'>";
                    echo "<option value='top'>" . __s('Main categories', 'sprint') . "</option>";
                    echo "<option value='sub'>" . __s('With subcategories', 'sprint') . "</option>";
                    echo "</select>";
                }
                echo "<select class='form-select form-select-sm sprint-category-trend-metric' style='max-width:240px;'>";
                foreach ($metrics as $key => $label) {
                    echo "<option value='" . htmlescape($key) . "'>" . htmlescape($label) . "</option>";
                }
                echo "</select></div>";
                foreach ($datasets as $set) {
                    $yMax = $set['metric'] === 'done_pct'
                        ? max(100.0, ceil($set['max'] / 20) * 20)
                        : max(20.0, ceil($set['max'] / 20) * 20);
                    $visible = $set['metric'] === 'cap_planned' && $set['level'] === 'top';
                    echo "<div class='sprint-category-trend-pane' data-metric='" . htmlescape($set['metric']) . "' data-level='" . htmlescape($set['level']) . "'"
                        . ($visible ? '' : " style='display:none;'") . ">";
                    self::renderCategoryLines($labels, $set['series'], (float)$yMax);
                    echo "</div>";
                }
                echo "</div>";
                echo "<script>(function(){var wrap=document.currentScript.previousElementSibling;if(!wrap)return;var sel=wrap.querySelector('.sprint-category-trend-metric');var lvl=wrap.querySelector('.sprint-category-trend-level');if(!sel)return;var key='sprint-overview-category-metric',lkey='sprint-overview-category-level';try{var saved=localStorage.getItem(key);if(saved&&sel.querySelector('option[value='+JSON.stringify(saved)+']'))sel.value=saved;if(lvl){var sl=localStorage.getItem(lkey);if(sl&&lvl.querySelector('option[value='+JSON.stringify(sl)+']'))lvl.value=sl;}}catch(e){}function apply(){var level=lvl?lvl.value:'top';wrap.querySelectorAll('.sprint-category-trend-pane').forEach(function(p){p.style.display=(p.getAttribute('data-metric')===sel.value&&p.getAttribute('data-level')===level)?'':'none';});}sel.addEventListener('change',function(){apply();try{localStorage.setItem(key,sel.value);}catch(e){}});if(lvl){lvl.addEventListener('change',function(){apply();try{localStorage.setItem(lkey,lvl.value);}catch(e){}});}apply();})();</script>";
            }
        );
    }

    /**
     * SVG line chart, one polyline per category, y axis in %. Reuses the
     * member-activity classes so sprint.js drives legend hover/click focus.
     *
     * @param array<int,string> $labels
     * @param array<int,array{label:string,color:string,values:array<int,float|null>}> $series
     */
    private static function renderCategoryLines(array $labels, array $series, float $yMax): void
    {
        $n      = count($labels);
        $width  = 820;
        $height = 240;
        $padL   = 44;
        $padR   = 20;
        $padT   = 16;
        $padB   = 40;
        $plotW  = $width - $padL - $padR;
        $plotH  = $height - $padT - $padB;
        $yMax   = max(1.0, $yMax);
        $xStep  = $n > 1 ? $plotW / ($n - 1) : 0;
        $xAt    = fn(int $i) => $n > 1 ? $padL + $xStep * $i : $padL + $plotW / 2;
        $yAt    = fn(float $v) => $padT + $plotH - $plotH * ($v / $yMax);
        $fmt    = fn(float $v) => number_format($v, 2, '.', '');

        echo "<div class='sprint-member-activity'>";
        echo "<div style='overflow-x:auto;'>";
        echo "<svg class='sprint-responsive-chart' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$width} {$height}' "
            . "preserveAspectRatio='xMinYMin meet' style='width:100%;height:auto;display:block;font-family:sans-serif;font-size:11px;'>";
        for ($t = 0; $t <= 4; $t++) {
            $yv = $yMax * $t / 4;
            $y  = $fmt($yAt($yv));
            echo "<line x1='{$padL}' y1='{$y}' x2='" . ($padL + $plotW) . "' y2='{$y}' stroke='#e9ecef' stroke-width='1' />";
            echo "<text x='" . ($padL - 6) . "' y='" . $fmt($yAt($yv) + 3) . "' text-anchor='end' fill='#6c757d'>"
                . SprintMember::formatCapacity($yv) . "%</text>";
        }
        $labelEvery = max(1, (int)ceil($n / 10));
        for ($i = 0; $i < $n; $i++) {
            if ($i % $labelEvery !== 0 && $i !== $n - 1) {
                continue;
            }
            $label = mb_strlen($labels[$i]) > 14 ? mb_substr($labels[$i], 0, 13) . '…' : $labels[$i];
            echo "<text x='" . $fmt($xAt($i)) . "' y='" . ($padT + $plotH + 16) . "' text-anchor='middle' fill='#6c757d'>"
                . "<title>" . htmlescape($labels[$i]) . "</title>" . htmlescape($label) . "</text>";
        }
        echo "<line x1='{$padL}' y1='{$padT}' x2='{$padL}' y2='" . ($padT + $plotH) . "' stroke='#adb5bd' stroke-width='1' />";
        echo "<line x1='{$padL}' y1='" . ($padT + $plotH) . "' x2='" . ($padL + $plotW) . "' y2='" . ($padT + $plotH) . "' stroke='#adb5bd' stroke-width='1' />";

        foreach ($series as $idx => $s) {
            $color = htmlescape($s['color']);
            $dash  = !empty($s['sub']) ? " stroke-dasharray='6 4'" : '';
            // A null (no planned points that sprint) breaks the line rather
            // than drawing a misleading zero.
            $segments = [[]];
            foreach ($s['values'] as $i => $v) {
                if ($v === null) {
                    $segments[] = [];
                    continue;
                }
                $segments[count($segments) - 1][] = $fmt($xAt($i)) . ',' . $fmt($yAt(min((float)$v, $yMax)));
            }
            foreach ($segments as $seg) {
                if (count($seg) < 2) {
                    continue;
                }
                echo "<polyline class='sprint-activity-line' data-member-idx='" . (int)$idx . "' points='" . implode(' ', $seg) . "' "
                    . "fill='none' stroke='{$color}' stroke-width='2' stroke-linejoin='round' stroke-linecap='round'{$dash} />";
            }
            foreach ($s['values'] as $i => $v) {
                if ($v === null) {
                    continue;
                }
                $title = htmlescape($s['label'] . ' — ' . $labels[$i] . ': ' . SprintMember::formatCapacity((float)$v) . '%');
                echo "<circle class='sprint-activity-dot' data-member-idx='" . (int)$idx . "' cx='" . $fmt($xAt($i)) . "' cy='" . $fmt($yAt(min((float)$v, $yMax))) . "' r='3' fill='{$color}'>"
                    . "<title>{$title}</title></circle>";
            }
        }
        echo "</svg></div>";

        echo "<div class='sprint-activity-legend-wrap' style='display:flex;flex-wrap:wrap;gap:12px;margin-top:8px;font-size:0.9em;'>";
        foreach ($series as $idx => $s) {
            $nonNull = array_filter($s['values'], static fn($v) => $v !== null);
            $avg     = $nonNull ? array_sum($nonNull) / count($nonNull) : 0;
            echo "<div class='sprint-activity-legend' data-member-idx='" . (int)$idx . "' "
                . "title='" . htmlescape(__('Click to isolate — hover to preview', 'sprint')) . "' "
                . "style='display:flex;align-items:center;gap:6px;cursor:pointer;'>"
                . (!empty($s['sub'])
                    ? "<span style='display:inline-block;width:14px;height:0;border-top:3px dashed " . htmlescape($s['color']) . ";'></span>"
                    : "<span style='display:inline-block;width:14px;height:3px;background:" . htmlescape($s['color']) . ";border-radius:2px;'></span>")
                . "<span>" . htmlescape($s['label']) . " <span class='text-muted' title='" . __s('Average over the sprints shown', 'sprint') . "'>("
                . htmlescape(sprintf(__('avg. %s%%', 'sprint'), SprintMember::formatCapacity($avg))) . ")</span></span>"
                . "</div>";
        }
        echo "</div>";
        echo "</div>";
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
     * Reconstructed from GLPI's history: time to done, time blocked, reopens.
     *
     * @return array{cycle_days:float,blocked_days:float,rework_pct:int,measured:int,blocked_items:int}
     */
    private static function flowMetrics(array $items): array
    {
        global $DB;

        $out = ['cycle_days' => 0.0, 'blocked_days' => 0.0, 'rework_pct' => 0, 'measured' => 0, 'blocked_items' => 0];

        // Still blocked at close; the log walk below adds the ones that only
        // passed through blocked, or a completed sprint reports no blockers.
        $everBlocked = [];
        foreach ($items as $item) {
            if ((string)($item['status'] ?? '') === SprintItem::STATUS_BLOCKED
                || (int)($item['is_blocked'] ?? 0) === 1) {
                $everBlocked[(int)$item['id']] = true;
            }
        }
        $out['blocked_items'] = count($everBlocked);

        $ids = array_map(static fn($i) => (int)$i['id'], $items);
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

        $cycleSum = 0.0;
        $cycleN   = 0;
        $blockedSeconds = 0;
        $doneItems = 0;
        $reopened  = 0;
        foreach ($logs as $itemId => $itemLogs) {
            $started    = null;
            $finished   = null;
            $blockedAt  = null;
            $wasDone    = false;
            $isReopened = false;

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
                    $everBlocked[$itemId] = true;
                } elseif ($blockedAt !== null) {
                    $blockedSeconds += max(0, $ts - $blockedAt);
                    $blockedAt = null;
                }
            }

            if ($started !== null && $finished !== null && $finished >= $started) {
                $cycleSum += ($finished - $started) / 86400;
                $cycleN++;
            }
            if ($wasDone) {
                $doneItems++;
                if ($isReopened) {
                    $reopened++;
                }
            }
        }

        $out['cycle_days']   = $cycleN > 0 ? $cycleSum / $cycleN : 0.0;
        $out['blocked_days'] = $blockedSeconds / 86400;
        $out['rework_pct']   = $doneItems > 0 ? (int)round(($reopened / $doneItems) * 100) : 0;
        $out['measured']     = $cycleN;
        $out['blocked_items'] = count($everBlocked);
        return $out;
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
        self::renderCard(__('Scope stability', 'sprint'), 'fas fa-arrows-left-right', __('Shows baseline scope, work added after kick-off, completed work and carry-over per sprint.', 'sprint'), function () use ($chart) {
            self::renderGroupedBars($chart['labels'], [
                ['label' => __('Baseline items', 'sprint'), 'color' => '#6c757d', 'values' => $chart['series']['initial_scope']],
                ['label' => __('Added after kick-off', 'sprint'), 'color' => '#d63384', 'values' => $chart['series']['adhoc']],
                ['label' => __('Removed during sprint', 'sprint'), 'color' => '#fd7e14', 'values' => $chart['series']['removed_scope']],
                ['label' => __('Completed items', 'sprint'), 'color' => '#198754', 'values' => array_map(static fn($row) => $row['items_done'], array_slice($chart['sprints'], -count($chart['labels'])))],
                ['label' => __('Carry-over items', 'sprint'), 'color' => '#dc3545', 'values' => $chart['series']['carryover']],
            ], null, null, $chart['links']);
        });
    }

    private static function renderFlowHealth(array $data): void
    {
        self::renderCard(__('Flow health', 'sprint'), 'fas fa-water', __('Compares cycle time, blocked time and rework across completed sprints.', 'sprint'), function () use ($data) {
            $chart = self::trimToChartWindow($data);
            self::renderGroupedBars($chart['labels'], [
                ['label' => __('Cycle time (days)', 'sprint'), 'color' => '#0d6efd', 'values' => $chart['series']['cycle_days']],
                ['label' => __('Blocked time (days)', 'sprint'), 'color' => '#dc3545', 'values' => $chart['series']['blocked_days']],
            ], ['label' => __('Rework rate', 'sprint'), 'color' => '#fd7e14', 'values' => $chart['series']['rework_pct'], 'suffix' => '%', 'max' => 100], null, $chart['links']);
        });
    }

    private static function renderCapacityDelivery(array $data): void
    {
        self::renderCard(__('Capacity versus delivery', 'sprint'), 'fas fa-circle-nodes', __('Each bubble is a sprint. Position compares team load with predictability. Colour shows blocked work and size shows adhoc work.', 'sprint'), function () use ($data) {
            $loads = $data['workload_trend']['team'] ?? [];
            if (!$loads) { echo "<div class='text-muted py-3'>" . __('No data for this period.', 'sprint') . '</div>'; return; }
            $w = 900; $h = 320; $l = 52; $r = 24; $t = 16; $b = 56; $pw = $w - $l - $r; $ph = $h - $t - $b;
            $xMax = max(125, (int)ceil(max(array_map(static fn($v) => (float)($v ?? 0), $loads)) / 25) * 25);
            $okColor = '#1a9c8c'; $blockedColor = '#d63939';
            echo "<div style='overflow-x:auto'><svg class='sprint-responsive-chart' viewBox='0 0 {$w} {$h}' role='img' style='font-size:11px;'>";
            // Recessive horizontal grid + y labels; x labels every 25% without vertical grid.
            for ($i = 0; $i <= 4; $i++) {
                $y = $t + $ph - $ph * $i / 4;
                echo "<line x1='{$l}' y1='{$y}' x2='" . ($l + $pw) . "' y2='{$y}' stroke='var(--tblr-border-color,#ddd)' stroke-opacity='.55'/>";
                echo "<text x='" . ($l - 7) . "' y='" . ($y + 4) . "' text-anchor='end' fill='var(--tblr-secondary)'>" . (25 * $i) . "%</text>";
            }
            for ($pct = 0; $pct <= $xMax; $pct += 25) {
                $x = $l + $pw * $pct / $xMax;
                echo "<text x='{$x}' y='" . ($t + $ph + 18) . "' text-anchor='middle' fill='var(--tblr-secondary)'>{$pct}%</text>";
            }
            // Reference line: 100% load = fully committed team.
            $x100 = $l + $pw * min(100, $xMax) / $xMax;
            echo "<line x1='{$x100}' y1='{$t}' x2='{$x100}' y2='" . ($t + $ph) . "' stroke='var(--tblr-secondary,#888)' stroke-opacity='.5' stroke-dasharray='4,4'/>";
            echo "<text x='" . ($x100 + 4) . "' y='" . ($t + 10) . "' fill='var(--tblr-secondary)' font-size='10'>" . htmlescape(__('100% load', 'sprint')) . "</text>";

            // Large bubbles first so small ones stay clickable on top.
            $bubbles = [];
            foreach ($data['sprints'] as $i => $row) {
                $load = (float)($loads[$i] ?? 0);
                $bubbles[] = ['row' => $row, 'load' => $load, 'radius' => 6 + min(16, (int)$row['adhoc'] * 2)];
            }
            usort($bubbles, static fn($a, $b2) => $b2['radius'] <=> $a['radius']);
            foreach ($bubbles as $bubble) {
                $row = $bubble['row'];
                $predict = (float)$row['predictability'];
                $cx = number_format($l + $pw * min($xMax, $bubble['load']) / $xMax, 1, '.', '');
                $cy = number_format($t + $ph - $ph * min(100, $predict) / 100, 1, '.', '');
                $radius = $bubble['radius'];
                $blocked = (int)$row['blocked'] > 0;
                $color = $blocked ? $blockedColor : $okColor;
                // Blocked = filled, healthy = ring: state stays readable without color.
                $fill = $blocked ? $color : $color . '2e';
                $title = $row['name']
                    . ' · ' . sprintf(__('%d%% load', 'sprint'), (int)round($bubble['load']))
                    . ' · ' . sprintf(__('%s%% predictability', 'sprint'), $predict)
                    . ' · ' . sprintf(__('%d blocked', 'sprint'), (int)$row['blocked'])
                    . ' · ' . sprintf(__('%d adhoc', 'sprint'), (int)$row['adhoc']);
                echo "<a href='" . htmlescape(Sprint::getFormURLWithID((int)$row['id'])) . "'>"
                    . "<circle cx='{$cx}' cy='{$cy}' r='" . ($radius + 1.5) . "' fill='none' stroke='var(--tblr-bg-surface,#fff)' stroke-width='2'/>"
                    . "<circle cx='{$cx}' cy='{$cy}' r='{$radius}' fill='{$fill}' stroke='{$color}' stroke-width='2' fill-opacity='" . ($blocked ? '.6' : '1') . "'>"
                    . "<title>" . htmlescape($title) . "</title></circle></a>";
            }
            echo "<text x='" . ($l + $pw / 2) . "' y='" . ($h - 6) . "' text-anchor='middle' fill='var(--tblr-secondary)'>" . htmlescape(__('Average team load', 'sprint')) . "</text><text transform='translate(13 " . ($t + $ph / 2) . ") rotate(-90)' text-anchor='middle' fill='var(--tblr-secondary)'>" . htmlescape(__('Predictability', 'sprint')) . '</text></svg></div>';

            echo "<div class='d-flex flex-wrap gap-3 mt-1 small text-muted'>";
            echo "<span><span style='display:inline-block;width:11px;height:11px;border-radius:50%;border:2px solid {$okColor};vertical-align:-2px;'></span> " . __('No blocked work', 'sprint') . "</span>";
            echo "<span><span style='display:inline-block;width:11px;height:11px;border-radius:50%;background:{$blockedColor};vertical-align:-2px;'></span> " . __('Blocked work', 'sprint') . "</span>";
            echo "<span>" . __('Bubble size = adhoc items', 'sprint') . "</span>";
            echo "</div>";
        });
    }

    private static function renderDependencyHealth(array $data): void
    {
        $dep = $data['dependencies'];
        self::renderCard(__('Dependency health', 'sprint'), 'fas fa-link', __('Tracks open and resolved dependencies, waiting time and linked work that spans several sprints.', 'sprint'), function () use ($dep) {
            echo "<div class='row g-2'>";
            foreach ([
                [__('Open dependencies', 'sprint'), $dep['open'], 'red'],
                [__('Resolved dependencies', 'sprint'), $dep['resolved'], 'green'],
                [__('Average waiting time', 'sprint'), number_format($dep['avg_age'], 1) . ' ' . __('days', 'sprint'), 'orange'],
                [__('Linked work across sprints', 'sprint'), $dep['multi_sprint'], 'blue'],
                [__('Without owner', 'sprint'), $dep['without_owner'], 'purple'],
            ] as [$label, $value, $color]) echo "<div class='col-6 col-lg'><div class='border rounded p-3 h-100'><div class='h2 text-{$color} mb-1'>" . htmlescape((string)$value) . "</div><div class='text-muted small'>" . htmlescape($label) . '</div></div></div>';
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
        self::renderCard(__('Comparison', 'sprint'), 'fas fa-code-compare', __('Compare the current selection with another period or Scrum Master.', 'sprint'), function () use ($current, $other) {
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

    private static function renderOverviewBehaviour(): void
    {
        echo "<script>(function(){var root=document.querySelector('.sprint-overview-shell')||document;var compact=new URLSearchParams(location.search).get('view')==='compact';root.querySelectorAll('.card.mb-3').forEach(function(card,i){card.classList.add('sprint-overview-collapsible');var body=card.querySelector('.card-body');var title=card.querySelector('.card-title');if(!body||!title)return;var key='sprint-overview-card-'+i;title.style.cursor='pointer';title.setAttribute('title','" . addslashes(__('Click to collapse or expand', 'sprint')) . "');var hidden=compact||localStorage.getItem(key)==='1';if(hidden)body.classList.add('sprint-overview-collapsed');title.addEventListener('click',function(){body.classList.toggle('sprint-overview-collapsed');localStorage.setItem(key,body.classList.contains('sprint-overview-collapsed')?'1':'0');});});var storeKey='sprint-overview-saved-filters',select=document.getElementById('sprint-saved-filters'),save=document.getElementById('sprint-save-filter');function read(){try{return JSON.parse(localStorage.getItem(storeKey)||'{}')}catch(e){return {}}}function fill(){if(!select)return;var all=read();Object.keys(all).sort().forEach(function(name){var o=document.createElement('option');o.value=all[name];o.textContent=name;select.appendChild(o);});}if(save)save.addEventListener('click',function(){var name=window.prompt('" . addslashes(__('Name this filter', 'sprint')) . "');if(!name)return;var all=read();all[name]=location.href;localStorage.setItem(storeKey,JSON.stringify(all));location.reload();});if(select)select.addEventListener('change',function(){if(this.value)location.href=this.value;});fill();})();</script>";
    }

    private static function renderCard(string $title, string $icon, string $subtitle, callable $body, string $wrapper = ''): void
    {
        if ($wrapper !== '') {
            echo "<div class='" . htmlescape($wrapper) . "'>";
        }
        // h-100 only for cards sharing a row: on a full-width card the
        // percentage resolves against the whole stretched content column.
        $classes = $wrapper !== '' ? 'card h-100' : 'card mb-3';
        echo "<div class='" . $classes . "'><div class='card-body'>";
        echo "<h3 class='card-title'><i class='" . htmlescape($icon) . " me-2'></i>" . htmlescape($title) . "</h3>";
        if ($subtitle !== '') {
            echo "<div class='text-muted small mb-2'>" . htmlescape($subtitle) . "</div>";
        }
        $body();
        echo "</div></div>";
        if ($wrapper !== '') {
            echo "</div>";
        }
    }

    /**
     * Grouped bars, plus an optional line on its own scale and a dashed reference.
     *
     * @param array<int,string> $labels
     * @param array<int,array{label:string,color:string,values:array}> $series
     * @param array{label:string,color:string,values:array,suffix:string,max:int}|null $line
     * @param array{label:string,value:float,color:string}|null $reference
     */
    private static function renderGroupedBars(array $labels, array $series, ?array $line = null, ?array $reference = null, array $links = []): void
    {
        $n = count($labels);
        if ($n === 0) {
            return;
        }

        // Past a handful of sprints straight labels overlap, so tilt them.
        $tilted = $n > 8;
        $width = 900; $height = $tilted ? 300 : 260;
        $padL = 44; $padR = 20; $padT = 18; $padB = $tilted ? 86 : 46;
        $plotW = $width - $padL - $padR;
        $plotH = $height - $padT - $padB;

        $maxLeft = 1;
        foreach ($series as $set) {
            foreach ($set['values'] as $value) {
                $maxLeft = max($maxLeft, (float)$value);
            }
        }
        if ($reference !== null) {
            $maxLeft = max($maxLeft, (float)$reference['value']);
        }
        $tickStep = max(1, (int)ceil($maxLeft / 4));
        $yMax     = $tickStep * 4;
        $lineMax  = $line !== null ? max(1, (int)$line['max']) : 1;

        $slot   = $plotW / max(1, $n);
        $count  = count($series);
        $barW   = min(26, ($slot * 0.7) / max(1, $count));
        $gap    = 4;
        $yAt     = fn(float $v) => $padT + $plotH - ($plotH * ($v / $yMax));
        $yLineAt = fn(float $v) => $padT + $plotH - ($plotH * (min($v, $lineMax) / $lineMax));
        $fmt     = fn(float $v) => number_format($v, 2, '.', '');
        $halo    = "paint-order:stroke;stroke:var(--tblr-bg-surface,#fff);stroke-width:3.5px;stroke-linejoin:round;";

        echo "<div style='overflow-x:auto;'>";
        echo "<svg class='sprint-responsive-chart' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$width} {$height}' "
            . "style='min-width:520px;font-family:sans-serif;font-size:11px;'>";

        for ($t = 0; $t <= 4; $t++) {
            $value = (int)round($yMax * $t / 4);
            $y     = $fmt($yAt($value));
            echo "<line x1='{$padL}' y1='{$y}' x2='" . ($padL + $plotW) . "' y2='{$y}' stroke='#e9ecef' stroke-width='1' />";
            echo "<text x='" . ($padL - 6) . "' y='" . $fmt($yAt($value) + 3) . "' text-anchor='end' fill='#6c757d'>{$value}</text>";
        }

        $linePoints  = [];
        $barLabelYs  = [];
        for ($i = 0; $i < $n; $i++) {
            $cx     = $padL + ($slot * $i) + ($slot / 2);
            $startX = $cx - (($count * $barW + ($count - 1) * $gap) / 2);

            foreach ($series as $index => $set) {
                $value = (float)($set['values'][$i] ?? 0);
                $x     = $startX + $index * ($barW + $gap);
                $y     = $yAt($value);
                if (!empty($links[$i])) echo "<a href='" . htmlescape($links[$i]) . "'>";
                echo "<rect x='" . $fmt($x) . "' y='" . $fmt($y) . "' width='" . $fmt($barW) . "' "
                    . "height='" . $fmt($plotH - ($y - $padT)) . "' rx='2' fill='" . $set['color'] . "'>"
                    . "<title>" . htmlescape($labels[$i] . ' — ' . $set['label'] . ': ' . round($value)) . "</title></rect>";
                if (!empty($links[$i])) echo '</a>';
                if ($value > 0) {
                    echo "<text x='" . $fmt($x + $barW / 2) . "' y='" . $fmt($y - 4) . "' text-anchor='middle' "
                        . "fill='" . $set['color'] . "' font-weight='600' style='{$halo}'>" . round($value) . "</text>";
                    $barLabelYs[$i][] = $y - 4;
                }
            }

            $label   = mb_strlen($labels[$i]) > 16 ? (mb_substr($labels[$i], 0, 15) . '…') : $labels[$i];
            $labelX  = $fmt($cx);
            $labelY  = $padT + $plotH + ($tilted ? 14 : 16);
            $anchor  = $tilted ? 'end' : 'middle';
            $rotate  = $tilted ? " transform='rotate(-35 {$labelX} {$labelY})'" : '';
            echo "<text x='{$labelX}' y='{$labelY}' text-anchor='{$anchor}' fill='#6c757d'{$rotate}>"
                . htmlescape($label) . "<title>" . htmlescape($labels[$i]) . "</title></text>";

            if ($line !== null) {
                $linePoints[] = ['x' => $cx, 'y' => $yLineAt((float)($line['values'][$i] ?? 0)), 'v' => (float)($line['values'][$i] ?? 0)];
            }
        }

        if ($reference !== null && (float)$reference['value'] > 0) {
            $y = $fmt($yAt((float)$reference['value']));
            echo "<line x1='{$padL}' y1='{$y}' x2='" . ($padL + $plotW) . "' y2='{$y}' stroke='" . $reference['color']
                . "' stroke-width='1.2' stroke-dasharray='5,4' opacity='0.6' />";
        }

        if (!empty($linePoints)) {
            $poly = [];
            foreach ($linePoints as $point) {
                $poly[] = $fmt($point['x']) . ',' . $fmt($point['y']);
            }
            echo "<polyline points='" . implode(' ', $poly) . "' fill='none' stroke='" . $line['color']
                . "' stroke-width='2' stroke-linejoin='round' />";
            foreach ($linePoints as $i => $point) {
                echo "<circle cx='" . $fmt($point['x']) . "' cy='" . $fmt($point['y']) . "' r='2.8' fill='" . $line['color'] . "'>"
                    . "<title>" . htmlescape($line['label'] . ': ' . round($point['v']) . $line['suffix']) . "</title></circle>";

                // Above the point, unless that lands on a bar value label.
                $labelY = $point['y'] - 8;
                foreach ($barLabelYs[$i] ?? [] as $barLabelY) {
                    if (abs($labelY - $barLabelY) < 12) {
                        $labelY = min($point['y'] + 16, $padT + $plotH - 2);
                        break;
                    }
                }
                $labelY = max($labelY, $padT + 10);
                echo "<text x='" . $fmt($point['x']) . "' y='" . $fmt($labelY) . "' text-anchor='middle' "
                    . "fill='" . $line['color'] . "' font-weight='600' style='{$halo}'>" . round($point['v']) . $line['suffix'] . "</text>";
            }
        }

        echo "</svg></div>";

        echo "<div class='sprint-overview-legend'>";
        foreach ($series as $set) {
            echo "<span><span class='sprint-overview-legend-swatch' style='background:" . $set['color'] . ";'></span> "
                . htmlescape($set['label']) . "</span>";
        }
        if ($line !== null) {
            echo "<span><span class='sprint-overview-legend-line' style='border-color:" . $line['color'] . ";'></span> "
                . htmlescape($line['label']) . "</span>";
        }
        if ($reference !== null) {
            echo "<span><span class='sprint-overview-legend-line dashed' style='border-color:" . $reference['color'] . ";'></span> "
                . htmlescape($reference['label']) . "</span>";
        }
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
                . " <span class='text-muted small'>" . htmlescape($unit) . "</span></td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }
}
