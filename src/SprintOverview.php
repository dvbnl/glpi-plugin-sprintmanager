<?php

namespace GlpiPlugin\Sprint;

use CommonGLPI;
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

    /** Charts stay readable up to this many sprints; the tiles cover them all. */
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
        $data     = self::collect($period);
        $previous = $period === self::PERIOD_ALL ? ['sprint_count' => 0] : self::collect($period, true);

        echo "<h2 class='mb-1'><i class='" . self::getIcon() . " me-2'></i>"
            . __('Sprint overview', 'sprint') . "</h2>";
        echo "<div class='text-muted mb-3'>"
            . __('Trends across completed sprints, filtered on their end date.', 'sprint') . "</div>";

        self::renderPeriodSelector($period);
        self::renderTiles($data, $previous, $period);

        if ($data['sprint_count'] === 0) {
            echo "<div class='alert alert-info'><i class='fas fa-info-circle me-2'></i>"
                . __('No completed sprints in this period.', 'sprint') . "</div>";
            return;
        }

        $chart = self::trimToChartWindow($data);
        if ($chart['dropped'] > 0) {
            echo "<div class='text-muted small mb-2'><i class='fas fa-info-circle me-1'></i>"
                . sprintf(
                    __('Charts show the last %1$d of %2$d sprints in this period; the tiles cover all of them.', 'sprint'),
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
                    ]
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
                ]);
            }
        );

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
                    ]
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
        self::renderSprintTable($data);
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
        foreach ($data['series'] as $key => $values) {
            $data['series'][$key] = array_slice($values, -self::CHART_SPRINTS);
        }
        return $data;
    }

    private static function renderPeriodSelector(string $period): void
    {
        $self = Plugin::getWebDir('sprint') . '/front/sprint.php';
        echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'>";
        echo "<span class='text-muted'>" . __('Period', 'sprint') . ":</span>";
        echo "<div class='btn-group'>";
        foreach (self::getPeriods() as $key => $label) {
            $class = 'btn btn-sm ' . ($key === $period ? 'btn-primary' : 'btn-outline-secondary');
            $url   = $self . '?section=' . self::SECTION_OVERVIEW . '&period=' . $key;
            echo "<a class='" . $class . "' href='" . htmlescape($url) . "'>" . htmlescape($label) . "</a>";
        }
        echo "</div></div>";
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
    private static function collect(string $period, bool $shift = false): array
    {
        [$from, $to] = self::periodRange($period, $shift);

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
            'series'         => [
                'pts_done'       => [],
                'pts_planned'    => [],
                'predictability' => [],
                'carryover'      => [],
                'fl_done'        => [],
                'adhoc'          => [],
                'fl_cap'         => [],
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
            'workload'       => [],
            'workload_trend' => ['labels' => [], 'team' => [], 'over' => [], 'members' => 0, 'drift' => 0.0, 'direction' => 'stable'],
            'flow'           => ['cycle_days' => 0.0, 'blocked_days' => 0.0, 'rework_pct' => 0, 'measured' => 0, 'blocked_items' => 0],
            'requests'       => ['total' => 0, 'accepted_pct' => 0, 'wait_days' => 0.0],
        ];
        if ($out['sprint_count'] === 0) {
            return $out;
        }

        $sprintIds = array_map(static fn($s) => (int)$s['id'], $sprints);
        $items     = self::fetchItems($sprintIds);
        $flByUser  = self::fetchAllocations(SprintFastlaneMember::getTable(), $items, true);
        $depByUser = self::fetchAllocations(SprintItemDependency::getTable(), $items, false);

        // Item totals reuse the per-user rows.
        $flCap = [];
        foreach ($flByUser as $itemId => $perUser) {
            $flCap[$itemId] = array_sum($perUser);
        }

        $bySprint  = [];
        $perMember = [];
        $perType   = [];
        foreach ($items as $item) {
            $sid  = (int)$item['plugin_sprint_sprints_id'];
            $done = (string)$item['status'] === SprintItem::STATUS_DONE;
            $pts  = (int)$item['story_points'];

            $bySprint[$sid] ??= [
                'pts_planned' => 0, 'pts_done' => 0, 'items_total' => 0, 'items_done' => 0,
                'fl_items' => 0, 'fl_done' => 0, 'adhoc' => 0, 'blocked' => 0, 'fl_cap' => 0.0,
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
            unset($bucket);

            if ($done) {
                $uid = (int)$item['users_id'];
                $perMember[$uid] = ($perMember[$uid] ?? 0) + $pts;
                $type = (string)($item['itemtype'] ?? '');
                $perType[$type] = ($perType[$type] ?? 0) + 1;
            }
        }

        $velocities = [];
        foreach ($sprints as $sprint) {
            $sid    = (int)$sprint['id'];
            $bucket = $bySprint[$sid] ?? [
                'pts_planned' => 0, 'pts_done' => 0, 'items_total' => 0, 'items_done' => 0,
                'fl_items' => 0, 'fl_done' => 0, 'adhoc' => 0, 'blocked' => 0, 'fl_cap' => 0.0,
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
            $out['series']['pts_done'][]       = $bucket['pts_done'];
            $out['series']['pts_planned'][]    = $bucket['pts_planned'];
            $out['series']['predictability'][] = $predict;
            $out['series']['carryover'][]      = $carry;
            $out['series']['fl_done'][]        = $bucket['fl_done'];
            $out['series']['adhoc'][]          = $bucket['adhoc'];
            $out['series']['fl_cap'][]         = (int)round($bucket['fl_cap']);

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

        $count = $out['sprint_count'];
        $out['avg_velocity']   = $out['pts_done'] / $count;
        $out['avg_fl_cap']     = $out['avg_fl_cap'] / $count;
        $out['predictability'] = $out['pts_planned'] > 0
            ? (int)round(($out['pts_done'] / $out['pts_planned']) * 100)
            : 0;
        $out['consistency']    = self::consistency($velocities, $out['avg_velocity']);
        $out['per_member']     = self::rankMembers($perMember);
        $out['per_type']       = self::rankTypes($perType);
        $out['workload']       = self::workload($sprints, $items, $flByUser, $depByUser);
        $out['workload_trend'] = self::workloadTrend($out['labels'], $out['workload']);

        // The delta badges don't use flow data, so skip the log work there.
        if (!$shift) {
            $out['flow']     = self::flowMetrics($items);
            $out['requests'] = self::requestStats($sprintIds);
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
                'users_id', 'itemtype', 'is_fastlane', 'is_adhoc', 'is_blocked',
            ],
            'FROM'   => SprintItem::getTable(),
            'WHERE'  => ['plugin_sprint_sprints_id' => $sprintIds],
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
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
            $available = (float)$row['capacity_percent'];
            if ($available <= 0) {
                continue;
            }
            $sid  = (int)$row['plugin_sprint_sprints_id'];
            $uid  = (int)$row['users_id'];
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
    private static function renderGroupedBars(array $labels, array $series, ?array $line = null, ?array $reference = null): void
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
        echo "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$width} {$height}' "
            . "style='width:100%;height:auto;min-width:520px;font-family:sans-serif;font-size:11px;'>";

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
                echo "<rect x='" . $fmt($x) . "' y='" . $fmt($y) . "' width='" . $fmt($barW) . "' "
                    . "height='" . $fmt($plotH - ($y - $padT)) . "' rx='2' fill='" . $set['color'] . "'>"
                    . "<title>" . htmlescape($labels[$i] . ' — ' . $set['label'] . ': ' . round($value)) . "</title></rect>";
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
            echo "<tr>";
            echo "<td class='sprint-overview-ranked-label'>" . htmlescape($row['label']) . "</td>";
            echo "<td><div class='progress progress-sm'><div class='progress-bar bg-" . $color . "' "
                . "role='progressbar' style='width:{$share}%' aria-valuenow='{$share}' aria-valuemin='0' aria-valuemax='100'></div></div></td>";
            echo "<td class='text-end text-nowrap'>" . (int)$row['value']
                . " <span class='text-muted small'>" . htmlescape($unit) . "</span></td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }
}
