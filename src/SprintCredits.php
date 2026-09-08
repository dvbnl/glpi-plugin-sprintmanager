<?php

namespace GlpiPlugin\Sprint;

use CommonGLPI;
use Html;
use Plugin;

/**
 * The Credits page: what every customer is granted per sprint, what the sprints
 * claimed of it, what lapsed unused, and what the bought wallet still holds.
 */
class SprintCredits extends CommonGLPI
{
    public static $rightname = 'plugin_sprint_credits';

    /** Completed sprints considered when averaging the wallet burn. */
    const BURN_WINDOW = 6;

    /** Sprints shown in the charts and the lapse table. */
    const CHART_SPRINTS = 12;

    public static function getTypeName($nb = 0): string
    {
        return __('Credits', 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-coins';
    }

    public static function getSearchURL($full = true)
    {
        return Plugin::getWebDir('sprint', $full) . '/front/credits.php';
    }

    // =========================================================================
    // Page
    // =========================================================================

    public static function show(): void
    {
        if (!SprintCustomer::canViewCredits()) {
            echo "<div class='alert alert-warning'>"
                . htmlescape(__("You don't have permission to perform this action.")) . "</div>";
            return;
        }
        $balances = SprintCustomer::balances(false);
        $funding  = SprintCustomer::sprintFunding();
        $window   = array_slice(SprintCustomer::fundingSprints(), -self::CHART_SPRINTS);

        self::renderHeader();
        self::renderPortfolio($balances, SprintCustomer::unassignedUsage());
        self::renderCustomerTable($balances, $funding);
        self::renderSprintCharts($window, $funding, $balances);
        self::renderExpiry($window, $funding, $balances);
        self::renderForecast($funding, $balances);
        self::renderLedger();
    }

    private static function renderHeader(): void
    {
        echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'>";
        echo "<h2 class='mb-0'><i class='" . self::getIcon() . " me-2'></i>" . __('Credits', 'sprint') . "</h2>";
        echo "<span class='text-muted sprint-small'>"
            . htmlescape(__('What every customer is granted per sprint, what the sprints claimed of it and what the bought wallet still holds.', 'sprint'))
            . "</span>";
        echo "<span style='flex:1;'></span>";
        if (SprintCustomer::canEditCredits()) {
            echo "<a class='btn btn-sm btn-outline-secondary' href='" . SprintCreditProduct::getSearchURL() . "'>"
                . "<i class='" . SprintCreditProduct::getIcon() . " me-1'></i>" . __('Catalogue', 'sprint') . "</a>";
            echo "<a class='btn btn-sm btn-outline-secondary' href='" . SprintCustomer::getSearchURL() . "'>"
                . "<i class='fas fa-list me-1'></i>" . __('All customers', 'sprint') . "</a>";
            echo "<a class='btn btn-sm btn-primary' href='" . SprintCustomer::getFormURL() . "'>"
                . "<i class='fas fa-plus me-1'></i>" . __('New customer', 'sprint') . "</a>";
        }
        echo "</div>";
    }

    /** Portfolio-wide totals across every customer. */
    private static function renderPortfolio(array $balances, array $unassigned): void
    {
        $keys  = ['purchased', 'granted', 'grant_used', 'grant_reserved', 'expired', 'overflow',
            'wallet_reserved', 'wallet_balance', 'pipeline', 'available'];
        $total = array_fill_keys($keys, 0.0) + ['alert' => 0.0];
        foreach ($balances as $b) {
            foreach ($keys as $k) {
                $total[$k] += (float)$b[$k];
            }
        }

        echo "<div class='sprint-credit-tiles mb-3'>";
        foreach (SprintCredit::balanceTiles($total) as $tile) {
            echo SprintCredit::renderTile($tile[0], $tile[1], $tile[2], $tile[3]);
        }
        echo "</div>";

        $stray = $unassigned['consumed'] + $unassigned['reserved'] + $unassigned['pipeline'];
        if ($stray > 0) {
            echo "<div class='alert alert-warning py-2 sprint-small'>";
            echo "<i class='fas fa-triangle-exclamation me-1'></i>";
            echo sprintf(
                htmlescape(__('%s credits sit on items with no customer, so they count against nobody\'s balance.', 'sprint')),
                htmlescape(SprintCustomer::formatCredits($stray))
            );
            echo "</div>";
        }
    }

    /** One row per customer: the retainer, what it delivered, and the wallet. */
    private static function renderCustomerTable(array $balances, array $funding): void
    {
        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<h3 class='card-title'><i class='fas fa-building me-2'></i>" . __('Balance per customer', 'sprint') . "</h3>";
        echo "<div class='text-muted sprint-small mb-2'>"
            . htmlescape(__('Delivered work spends the sprint\'s grant first; work beyond it is drawn from the bought wallet. Open work only reserves. A grant that a completed sprint did not use lapses.', 'sprint'))
            . "</div>";

        if (!$balances) {
            echo "<div class='text-muted'>" . __('No customers yet.', 'sprint') . "</div>";
            echo "</div></div>";
            return;
        }

        echo "<div class='table-responsive'><table class='table table-sm align-middle sprint-credit-table'>";
        echo "<thead><tr>";
        echo "<th style='min-width:190px;'>" . SprintCustomer::getTypeName(1) . "</th>";
        echo "<th class='text-center' title='" . __s('The retainer: credits granted every sprint in the agreed window', 'sprint') . "'>"
            . __('Per sprint', 'sprint') . "</th>";
        echo "<th class='text-center'>" . __('Granted', 'sprint') . "</th>";
        echo "<th class='text-center' title='" . __s('Delivered against the grants; below it what open work still reserves of them', 'sprint') . "'>"
            . __('Used', 'sprint') . "</th>";
        echo "<th class='text-center'>" . __('Lapsed', 'sprint') . "</th>";
        echo "<th class='text-center'>" . __('In backlog', 'sprint') . "</th>";
        echo "<th class='text-center' title='" . __s('Purchases up to today; a lot that lapsed unused and corrections are shown below it', 'sprint') . "'>"
            . __('Bought', 'sprint') . "</th>";
        echo "<th class='text-center' title='" . __s('Credits drawn from the wallet because delivered work went past its sprint\'s grant', 'sprint') . "'>"
            . __('From wallet', 'sprint') . "</th>";
        echo "<th class='text-center' title='" . __s('The wallet today; below it what open work still needs beyond the grants', 'sprint') . "'>"
            . __('Left', 'sprint') . "</th>";
        echo "<th class='text-center' title='" . __s('Sprints the wallet lasts at the average draw of the last completed sprints', 'sprint') . "'>"
            . __('Runway', 'sprint') . "</th>";
        echo "</tr></thead><tbody>";

        foreach ($balances as $id => $b) {
            $left    = (float)$b['wallet_balance'];
            $free    = (float)$b['available'];
            $alert   = (float)$b['alert'];
            $low     = $free < 0 || ($alert > 0 && $free < $alert);
            $leftCol = $left < 0 ? '#dc3545' : ($low ? '#fd7e14' : '#198754');

            echo "<tr" . ((int)$b['is_active'] === 0 ? " style='opacity:0.55;'" : '') . ">";
            echo "<td><a href='" . SprintCustomer::getFormURLWithID((int)$id) . "'>"
                . "<span class='sprint-matrix-dot' style='background:" . htmlescape((string)$b['color']) . ";'></span>"
                . htmlescape((string)$b['name']) . "</a>";
            if ((int)$b['is_active'] === 0) {
                echo " <span class='badge bg-secondary'>" . __('inactive', 'sprint') . "</span>";
            }
            if ($left < 0) {
                echo " <span class='badge bg-danger'>" . __('overdrawn', 'sprint') . "</span>";
            } elseif ($low) {
                echo " <span class='badge bg-warning' style='color:#000 !important;'>"
                    . __('low balance', 'sprint') . "</span>";
            }
            echo self::customerBar($b);
            echo "</td>";

            echo "<td class='text-center'>" . ((float)$b['per_sprint'] > 0
                ? "<span class='fw-bold'>" . htmlescape(SprintCustomer::formatCredits($b['per_sprint'])) . "</span>"
                    . ((float)$b['cap'] > 0
                        ? "<div class='text-muted sprint-small'>" . sprintf(
                            htmlescape(__('cap %s', 'sprint')),
                            htmlescape(SprintCustomer::formatCredits($b['cap']))
                        ) . "</div>"
                        : '')
                : "<span class='text-muted'>—</span>");
            if (!empty($b['next_rule'])) {
                echo "<div class='sprint-small' style='color:#0d6efd;' title='" . __s('The next retainer rule and the day it takes over', 'sprint') . "'>→ "
                    . htmlescape(SprintCustomer::formatCredits($b['next_rule']['credits'])) . ' '
                    . htmlescape(sprintf(__('from %s', 'sprint'), Html::convDate($b['next_rule']['date_start']))) . "</div>";
            }
            echo "</td>";
            echo "<td class='text-center'>" . htmlescape(SprintCustomer::formatCredits($b['granted'])) . "</td>";
            echo "<td class='text-center'>" . htmlescape(SprintCustomer::formatCredits($b['grant_used']));
            if ((float)$b['grant_reserved'] > 0) {
                echo "<div class='sprint-small text-muted' title='" . __s('Reserved on the grants by open work', 'sprint') . "'>+"
                    . htmlescape(SprintCustomer::formatCredits($b['grant_reserved'])) . ' ' . htmlescape(__('reserved', 'sprint')) . "</div>";
            }
            echo "</td>";
            $expired = (float)$b['expired'];
            echo "<td class='text-center'" . ($expired > 0 ? " style='color:#dc3545;font-weight:600;'" : '') . ">"
                . htmlescape(SprintCustomer::formatCredits($expired)) . "</td>";
            echo "<td class='text-center'>" . htmlescape(SprintCustomer::formatCredits($b['pipeline'])) . "</td>";
            echo "<td class='text-center'>" . htmlescape(SprintCustomer::formatCredits($b['purchased']));
            if ((float)$b['purchase_lapsed'] > 0) {
                echo "<div class='sprint-small' style='color:#dc3545;' title='"
                    . __s('Bought credits that expired before a sprint drew on them', 'sprint') . "'>-"
                    . htmlescape(SprintCustomer::formatCredits($b['purchase_lapsed'])) . ' ' . htmlescape(__('lapsed', 'sprint')) . "</div>";
            }
            if ((float)$b['corrections'] > 0) {
                echo "<div class='sprint-small text-muted' title='" . __s('Negative bookings: corrections and write-offs', 'sprint') . "'>-"
                    . htmlescape(SprintCustomer::formatCredits($b['corrections'])) . ' ' . htmlescape(__('corrected', 'sprint')) . "</div>";
            }
            if ((float)$b['future'] > 0) {
                echo "<div class='sprint-small text-muted' title='" . __s('Purchases with a booking date after today; not counted yet', 'sprint') . "'>+"
                    . htmlescape(SprintCustomer::formatCredits($b['future'])) . ' ' . htmlescape(__('dated ahead', 'sprint')) . "</div>";
            }
            echo "</td>";
            echo "<td class='text-center'>" . htmlescape(SprintCustomer::formatCredits($b['overflow'])) . "</td>";
            echo "<td class='text-center fw-bold' style='color:{$leftCol};'>"
                . htmlescape(SprintCustomer::formatCredits($left));
            if ((float)$b['wallet_reserved'] > 0) {
                echo "<div class='sprint-small fw-normal text-muted' title='" . __s('Open work in running and planned sprints that the grants do not cover', 'sprint') . "'>"
                    . htmlescape(SprintCustomer::formatCredits($b['wallet_reserved'])) . ' ' . htmlescape(__('reserved', 'sprint')) . "</div>";
            }
            echo "</td>";
            echo "<td class='text-center'>" . self::runwayLabel($free, self::walletBurn($id, $funding)) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";
        echo "</div></div>";
    }

    /** Grant used vs lapsed, or wallet drawn vs left for a customer without one. */
    private static function customerBar(array $b): string
    {
        $seg = static function (float $value, float $scale, string $color, string $label): string {
            if ($value <= 0 || $scale <= 0) {
                return '';
            }
            $pct = min(100, round(100 * $value / $scale, 2));
            return "<span style='width:{$pct}%;background:{$color};' title='" . htmlescape($label) . ': '
                . htmlescape(SprintCustomer::formatCredits($value)) . "'></span>";
        };

        if ((float)$b['granted'] > 0) {
            $scale = (float)$b['granted'];
            return "<div class='sprint-wallet-bar mt-1'>"
                . $seg((float)$b['grant_used'], $scale, '#198754', __('Used', 'sprint'))
                . $seg((float)$b['grant_reserved'], $scale, '#fd7e14', __('Reserved', 'sprint'))
                . $seg((float)$b['expired'], $scale, '#dc3545', __('Lapsed', 'sprint'))
                . "</div>";
        }

        $left     = (float)$b['wallet_balance'];
        $reserved = min((float)$b['wallet_reserved'], max($left, 0));
        $scale    = max((float)$b['purchased'], (float)$b['overflow'] + max($left, 0), 0.01);
        $html     = "<div class='sprint-wallet-bar mt-1'>"
            . $seg((float)$b['overflow'], $scale, '#6f42c1', __('From wallet', 'sprint'))
            . $seg($reserved, $scale, '#fd7e14', __('Reserved', 'sprint'))
            . $seg(max($left, 0) - $reserved, $scale, '#198754', __('Left', 'sprint'))
            . "</div>";
        if ($left < 0) {
            $html .= "<div class='sprint-small' style='color:#dc3545;'>"
                . sprintf(htmlescape(__('%s over budget', 'sprint')), htmlescape(SprintCustomer::formatCredits(abs($left))))
                . "</div>";
        }
        return $html;
    }

    /** Average wallet draw per completed sprint, over the last few of them. */
    private static function walletBurn(int $customerId, array $funding): float
    {
        $completed = [];
        foreach ($funding[$customerId] ?? [] as $cell) {
            if ($cell['completed']) {
                $completed[] = (float)$cell['overflow'];
            }
        }
        $completed = array_slice($completed, -self::BURN_WINDOW);
        if (!$completed || array_sum($completed) <= 0) {
            return 0.0;
        }
        return array_sum($completed) / count($completed);
    }

    /** "≈ 4 sprints", or "—" when the wallet is not being drawn on. */
    private static function runwayLabel(float $left, float $rate): string
    {
        if ($rate <= 0) {
            return "<span class='text-muted' title='" . __s('No completed sprint has drawn on this wallet yet', 'sprint') . "'>—</span>";
        }
        if ($left <= 0) {
            return "<span class='fw-bold' style='color:#dc3545;'>0</span>";
        }
        $sprints = $left / $rate;
        $color   = $sprints < 1 ? '#dc3545' : ($sprints < 2 ? '#fd7e14' : '#198754');
        return "<span class='fw-bold' style='color:{$color};'>"
            . sprintf(
                htmlescape(_n('≈ %s sprint', '≈ %s sprints', (int)ceil($sprints), 'sprint')),
                htmlescape(number_format($sprints, 1, '.', ''))
            ) . "</span>";
    }

    // =========================================================================
    // Charts
    // =========================================================================

    /** One card, two views: the trend per customer and the mix per sprint. */
    private static function renderSprintCharts(array $window, array $funding, array $balances): void
    {
        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-1'>";
        echo "<h3 class='card-title mb-0'><i class='fas fa-chart-line me-2'></i>"
            . __('Credits per sprint', 'sprint') . "</h3>";
        echo "<span style='flex:1;'></span>";
        echo "<div class='btn-group btn-group-sm sprint-credit-viewpick' role='group'>";
        echo "<input type='radio' class='btn-check' name='sprint-credit-view' id='sprint-credit-view-trend' value='trend' checked>"
            . "<label class='btn btn-outline-secondary' for='sprint-credit-view-trend'>"
            . "<i class='fas fa-chart-line me-1'></i>" . __('Trend', 'sprint') . "</label>";
        echo "<input type='radio' class='btn-check' name='sprint-credit-view' id='sprint-credit-view-mix' value='mix'>"
            . "<label class='btn btn-outline-secondary' for='sprint-credit-view-mix'>"
            . "<i class='fas fa-chart-column me-1'></i>" . __('Mix', 'sprint') . "</label>";
        echo "</div></div>";
        echo "<div class='text-muted sprint-small mb-3'>"
            . htmlescape(__('Trend: one line per customer showing the credits that sprint claimed, with their agreed volume as a dashed line when you isolate them. Mix: how each sprint was filled, plus the grant that lapsed unused.', 'sprint'))
            . "</div>";

        if (!$window) {
            echo "<div class='text-muted'>" . __('No sprints to show yet.', 'sprint') . "</div>";
            echo "</div></div>";
            return;
        }

        $customerIds = self::chartCustomers($window, $funding);
        if (!$customerIds) {
            echo "<div class='text-muted'>" . __('No credits assigned to a sprint yet.', 'sprint') . "</div>";
            echo "</div></div>";
            return;
        }

        echo "<div class='sprint-credit-view' data-view='trend'>";
        self::renderTrendChart($window, $funding, $balances, $customerIds);
        echo "</div>";
        echo "<div class='sprint-credit-view' data-view='mix' hidden>";
        self::renderMixChart($window, $funding, $balances, $customerIds);
        echo "</div>";

        self::renderLegend($customerIds, $balances);
        self::renderChartScript();
        echo "</div></div>";
    }

    /** Customers with a grant or a claim in the window, name order. */
    private static function chartCustomers(array $window, array $funding): array
    {
        $sprintIds = array_map(static fn($s) => (int)$s['id'], $window);
        $out       = [];
        foreach ($funding as $cid => $bySprint) {
            foreach ($sprintIds as $sid) {
                if (isset($bySprint[$sid])) {
                    $out[] = (int)$cid;
                    break;
                }
            }
        }
        return $out;
    }

    /** Inline SVG line chart: credits claimed per sprint, one line per customer. */
    private static function renderTrendChart(array $window, array $funding, array $balances, array $customerIds): void
    {
        $w = 860;
        $h = 280;
        $padL = 52;
        $padR = 14;
        $padT = 14;
        $padB = 46;

        $max = 0.0;
        foreach ($customerIds as $cid) {
            foreach ($window as $sprint) {
                $cell = $funding[$cid][(int)$sprint['id']] ?? null;
                if ($cell) {
                    $max = max($max, (float)$cell['claimed'], (float)$cell['grant']);
                }
            }
        }
        $max = $max > 0 ? $max : 1.0;
        // Round up to a friendly grid so the axis reads cleanly.
        $step = pow(10, max(0, (int)floor(log10($max)) - 1));
        $max  = ceil($max / (4 * $step)) * 4 * $step;

        $n    = count($window);
        $xAt  = static fn(int $i) => $n > 1
            ? $padL + $i * (($w - $padL - $padR) / ($n - 1))
            : $padL + ($w - $padL - $padR) / 2;
        $yAt  = static fn(float $v) => $padT + ($h - $padT - $padB) * (1 - $v / $max);
        $num  = static fn(float $v) => number_format($v, 2, '.', '');

        echo "<div class='sprint-credit-chartbox'>";
        echo "<svg viewBox='0 0 {$w} {$h}' class='sprint-credit-svg' preserveAspectRatio='xMidYMid meet' role='img'>";

        for ($g = 0; $g <= 4; $g++) {
            $value = $max * $g / 4;
            $y     = $num($yAt($value));
            echo "<line x1='{$padL}' y1='{$y}' x2='" . ($w - $padR) . "' y2='{$y}' class='sprint-credit-grid'/>";
            echo "<text x='" . ($padL - 8) . "' y='" . $num($yAt($value) + 4) . "' class='sprint-credit-axis' text-anchor='end'>"
                . htmlescape(SprintCustomer::formatCredits($value)) . "</text>";
        }

        // On a crowded axis only every other label is drawn.
        $every = $n > 8 ? 2 : 1;
        foreach ($window as $i => $sprint) {
            if ($i % $every !== 0) {
                continue;
            }
            echo "<text x='" . $num($xAt($i)) . "' y='" . ($h - $padB + 18) . "' class='sprint-credit-axis' text-anchor='middle'>"
                . htmlescape(self::shortLabel((string)$sprint['name'])) . "</text>";
        }

        foreach ($customerIds as $cid) {
            $color  = htmlescape($cid > 0 ? SprintCustomer::getColorFor($cid) : '#6c757d');
            $points = [];
            $dots   = '';
            foreach ($window as $i => $sprint) {
                $cell = $funding[$cid][(int)$sprint['id']] ?? null;
                if ($cell === null) {
                    continue;
                }
                $x = $num($xAt($i));
                $y = $num($yAt((float)$cell['claimed']));
                $points[] = $x . ',' . $y;
                $title = htmlescape(
                    self::customerLabel($cid, $balances) . ' — ' . (string)$sprint['name'] . ': '
                    . SprintCustomer::formatCredits($cell['claimed'])
                    . ((float)$cell['grant'] > 0
                        ? ' / ' . SprintCustomer::formatCredits($cell['grant'])
                        : '')
                );
                $dots .= "<circle cx='{$x}' cy='{$y}' r='3.5' fill='{$color}'><title>{$title}</title></circle>";
            }
            if (!$points) {
                continue;
            }
            echo "<g class='sprint-credit-series' data-series='" . (int)$cid . "'>";
            echo "<polyline points='" . implode(' ', $points) . "' fill='none' stroke='{$color}' stroke-width='2' "
                . "stroke-linejoin='round' stroke-linecap='round'/>";
            // The agreement in force per sprint (recorded or retainer), drawn
            // only while this customer is isolated. Per sprint, not today's
            // retainer: a past sprint shows what it was actually granted.
            $refPoints = [];
            $anyGrant  = false;
            foreach ($window as $i => $sprint) {
                $grant = (float)($funding[$cid][(int)$sprint['id']]['grant'] ?? 0);
                $anyGrant = $anyGrant || $grant > 0;
                $refPoints[] = $num($xAt($i)) . ',' . $num($yAt(min($grant, $max)));
            }
            if ($anyGrant && count($refPoints) > 1) {
                echo "<polyline points='" . implode(' ', $refPoints) . "' fill='none' stroke='{$color}' "
                    . "stroke-width='1.5' stroke-dasharray='6 4' class='sprint-credit-reference'/>";
            }
            echo $dots;
            echo "</g>";
        }

        echo "</svg></div>";
    }

    /** Stacked bar per sprint: who filled it, plus the grant that lapsed. */
    private static function renderMixChart(array $window, array $funding, array $balances, array $customerIds): void
    {
        $totals = [];
        $max    = 0.0;
        foreach ($window as $sprint) {
            $sid   = (int)$sprint['id'];
            $claim = $lapsed = 0.0;
            foreach ($customerIds as $cid) {
                $cell = $funding[$cid][$sid] ?? null;
                if ($cell) {
                    $claim  += (float)$cell['claimed'];
                    $lapsed += max(0.0, (float)$cell['expired']);
                }
            }
            $totals[$sid] = ['claimed' => $claim, 'lapsed' => $lapsed];
            $max = max($max, $claim + $lapsed);
        }
        $max = max($max, 0.01);

        $statuses = Sprint::getAllStatuses();
        echo "<div class='sprint-credit-chart'>";
        foreach ($window as $sprint) {
            $sid = (int)$sprint['id'];
            echo "<div class='sprint-credit-chart-row'>";
            echo "<div class='sprint-credit-chart-label' title='"
                . htmlescape((string)($statuses[$sprint['status']] ?? $sprint['status'])) . "'>"
                . "<a href='" . Sprint::getFormURLWithID($sid) . "'>" . htmlescape((string)$sprint['name']) . "</a>"
                . "</div>";
            echo "<div class='sprint-credit-chart-track'>";
            foreach ($customerIds as $cid) {
                $v = (float)($funding[$cid][$sid]['claimed'] ?? 0);
                if ($v <= 0) {
                    continue;
                }
                $pct = round(100 * $v / $max, 2);
                echo "<span class='sprint-credit-series' data-series='" . (int)$cid . "' "
                    . "style='width:{$pct}%;background:" . htmlescape($cid > 0 ? SprintCustomer::getColorFor($cid) : '#6c757d') . ";' title='"
                    . htmlescape(self::customerLabel($cid, $balances) . ': ' . SprintCustomer::formatCredits($v)) . "'></span>";
            }
            if ($totals[$sid]['lapsed'] > 0) {
                $pct = round(100 * $totals[$sid]['lapsed'] / $max, 2);
                echo "<span class='sprint-credit-lapsed' style='width:{$pct}%;' title='"
                    . htmlescape(__('Lapsed', 'sprint') . ': ' . SprintCustomer::formatCredits($totals[$sid]['lapsed'])) . "'></span>";
            }
            echo "</div>";
            echo "<div class='sprint-credit-chart-value'>"
                . htmlescape(SprintCustomer::formatCredits($totals[$sid]['claimed'])) . "</div>";
            echo "</div>";
        }
        echo "</div>";
    }

    /** Shared legend; clicking an entry isolates that customer in both views. */
    private static function renderLegend(array $customerIds, array $balances): void
    {
        echo "<div class='sprint-credit-legend'>";
        foreach ($customerIds as $cid) {
            echo "<button type='button' class='sprint-credit-legend-item' data-series='" . (int)$cid . "'>"
                . "<span class='sprint-matrix-dot' style='background:"
                . htmlescape($cid > 0 ? SprintCustomer::getColorFor($cid) : '#6c757d') . ";'></span>"
                . htmlescape(self::customerLabel($cid, $balances)) . "</button>";
        }
        echo "<span class='sprint-credit-legend-item sprint-credit-legend-static'>"
            . "<span class='sprint-matrix-dot sprint-credit-lapsed-dot'></span>"
            . htmlescape(__('Lapsed', 'sprint')) . "</span>";
        echo "</div>";
    }

    private static function renderChartScript(): void
    {
        echo <<<'HTML'
<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }
    if (window.__sprintCreditChartBound) { return; }
    window.__sprintCreditChartBound = true;

    jQuery(document).on('change', 'input[name="sprint-credit-view"]', function(){
        var view = jQuery('input[name="sprint-credit-view"]:checked').val();
        jQuery('.sprint-credit-view').each(function(){
            this.hidden = (this.getAttribute('data-view') !== view);
        });
    });

    // Clicking a legend entry isolates that customer; clicking again restores all.
    jQuery(document).on('click', '.sprint-credit-legend-item[data-series]', function(){
        var id = jQuery(this).attr('data-series');
        var on = !jQuery(this).hasClass('is-active');
        jQuery('.sprint-credit-legend-item').removeClass('is-active');
        jQuery('.sprint-credit-series').removeClass('is-muted is-isolated');
        if (on) {
            jQuery(this).addClass('is-active');
            jQuery('.sprint-credit-series').each(function(){
                var mine = this.getAttribute('data-series') === id;
                jQuery(this).addClass(mine ? 'is-isolated' : 'is-muted');
            });
        }
    });
})();
</script>
HTML;
    }

    /** Sprint names get long; the axis only has room for a short form. */
    private static function shortLabel(string $name): string
    {
        return mb_strlen($name) > 12 ? mb_substr($name, 0, 11) . '…' : $name;
    }

    private static function customerLabel(int $cid, array $balances): string
    {
        if ($cid <= 0) {
            return __('No customer', 'sprint');
        }
        $name = (string)($balances[$cid]['name'] ?? SprintCustomer::getNameFor($cid));
        return $name !== '' ? $name : '#' . $cid;
    }

    // =========================================================================
    // Lapsed credits
    // =========================================================================

    /** Customers × completed sprints: the grant that went unused. */
    private static function renderExpiry(array $window, array $funding, array $balances): void
    {
        $completed = array_values(array_filter(
            $window,
            static fn($s) => (string)$s['status'] === Sprint::STATUS_COMPLETED
        ));
        $rows = [];
        foreach ($funding as $cid => $bySprint) {
            foreach ($completed as $sprint) {
                if ((float)($bySprint[(int)$sprint['id']]['expired'] ?? 0) > 0) {
                    $rows[] = (int)$cid;
                    break;
                }
            }
        }

        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<h3 class='card-title'><i class='fas fa-hourglass-end me-2'></i>"
            . __('Lapsed credits per sprint', 'sprint') . "</h3>";
        echo "<div class='text-muted sprint-small mb-2'>"
            . htmlescape(__('Granted volume a completed sprint never claimed. It does not carry over, so this is agreed work that was left on the table.', 'sprint'))
            . "</div>";

        if (!$completed || !$rows) {
            echo "<div class='text-muted'>" . __('Nothing has lapsed yet.', 'sprint') . "</div>";
            echo "</div></div>";
            return;
        }

        echo "<div class='table-responsive'><table class='table table-sm align-middle sprint-credit-table'>";
        echo "<thead><tr><th style='min-width:150px;'>" . SprintCustomer::getTypeName(1) . "</th>";
        foreach ($completed as $sprint) {
            $sub = SprintCustomer::dateValue($sprint['date_end'] ?? null);
            echo "<th class='text-center'>" . htmlescape((string)$sprint['name'])
                . ($sub !== '' ? "<div class='text-muted sprint-small fw-normal'>" . htmlescape($sub) . "</div>" : '')
                . "</th>";
        }
        echo "<th class='text-center'>" . __('Total', 'sprint') . "</th></tr></thead><tbody>";

        $columnTotals = array_fill_keys(array_map(static fn($s) => (int)$s['id'], $completed), 0.0);
        $grand        = 0.0;

        foreach ($rows as $cid) {
            echo "<tr>";
            echo "<td><a href='" . SprintCustomer::getFormURLWithID($cid) . "'>"
                . "<span class='sprint-matrix-dot' style='background:"
                . htmlescape(SprintCustomer::getColorFor($cid)) . ";'></span>"
                . htmlescape(self::customerLabel($cid, $balances)) . "</a></td>";
            $rowTotal = 0.0;
            foreach ($completed as $sprint) {
                $sid  = (int)$sprint['id'];
                $cell = $funding[$cid][$sid] ?? null;
                $lost = (float)($cell['expired'] ?? 0);
                $rowTotal += $lost;
                $columnTotals[$sid] += $lost;
                echo "<td class='text-center'>";
                if ($cell === null || (float)$cell['grant'] <= 0) {
                    echo "<span class='text-muted'>—</span>";
                } else {
                    $share = $lost / (float)$cell['grant'];
                    $color = $lost <= 0 ? '#198754' : ($share > 0.25 ? '#dc3545' : '#fd7e14');
                    echo "<span class='fw-bold' style='color:{$color};' title='" . htmlescape(sprintf(
                        __('Granted %1$s, used %2$s', 'sprint'),
                        SprintCustomer::formatCredits($cell['grant']),
                        SprintCustomer::formatCredits($cell['used'])
                    )) . "'>" . htmlescape(SprintCustomer::formatCredits($lost)) . "</span>";
                    echo self::fillBar((float)$cell['used'], (float)$cell['grant']);
                }
                echo "</td>";
            }
            $grand += $rowTotal;
            echo "<td class='text-center fw-bold'>" . htmlescape(SprintCustomer::formatCredits($rowTotal)) . "</td>";
            echo "</tr>";
        }

        echo "<tr class='fw-bold'><td>" . __('Total', 'sprint') . "</td>";
        foreach ($completed as $sprint) {
            echo "<td class='text-center'>"
                . htmlescape(SprintCustomer::formatCredits($columnTotals[(int)$sprint['id']])) . "</td>";
        }
        echo "<td class='text-center' style='color:#dc3545;'>" . htmlescape(SprintCustomer::formatCredits($grand)) . "</td>";
        echo "</tr></tbody></table></div>";
        echo "</div></div>";
    }

    /** Small used-of-granted bar, shared by the lapse table. */
    private static function fillBar(float $used, float $grant): string
    {
        if ($grant <= 0) {
            return '';
        }
        $pct = min(100, round(100 * $used / $grant, 2));
        return "<div class='sprint-wallet-bar mt-1'>"
            . "<span style='width:{$pct}%;background:#198754;'></span></div>";
    }

    // =========================================================================
    // Forecast
    // =========================================================================

    /**
     * What the upcoming sprints look like against the agreed volume. Each
     * sprint is judged on its own (SprintCreditMath::forecast()): room left
     * in one sprint never covers an overrun in another.
     */
    private static function renderForecast(array $funding, array $balances): void
    {
        $upcoming = array_values(array_filter(
            SprintCustomer::fundingSprints(),
            static fn($s) => (string)$s['status'] !== Sprint::STATUS_COMPLETED
        ));

        $rows = [];
        foreach ($balances as $id => $b) {
            if ((int)$b['is_active'] === 0) {
                continue;
            }
            $cells = [];
            $over  = [];
            foreach ($upcoming as $sprint) {
                $cell = $funding[$id][(int)$sprint['id']] ?? null;
                if ($cell === null) {
                    continue;
                }
                $cells[] = $cell;
                if (!empty($cell['over_cap'])) {
                    $over[] = (string)$sprint['name'];
                }
            }
            $f = SprintCreditMath::forecast($cells);
            if ($f['grant'] <= 0 && $f['claim'] <= 0 && (float)$b['pipeline'] <= 0) {
                continue;
            }
            $rows[$id] = [
                'name'          => (string)$b['name'],
                'color'         => (string)$b['color'],
                'grant'         => $f['grant'],
                'claim'         => $f['claim'],
                'unused'        => $f['unused'],
                'wallet_needed' => $f['wallet_needed'],
                'pipeline'      => (float)$b['pipeline'],
                // Free to plan: the wallet after what open work already reserves.
                'left'          => (float)$b['available'],
                'balance'       => (float)$b['wallet_balance'],
                'burn'          => self::walletBurn((int)$id, $funding),
                'over_cap'      => $over,
            ];
        }
        if (!$rows) {
            return;
        }
        // Most agreed volume at risk of lapsing first.
        uasort($rows, static fn($a, $b) => $b['unused'] <=> $a['unused']);

        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<h3 class='card-title'><i class='fas fa-binoculars me-2'></i>" . __('Forecast', 'sprint') . "</h3>";
        echo "<div class='text-muted sprint-small mb-2'>"
            . htmlescape(__('The planned and running sprints against the agreed volume, sprint by sprint. What a sprint fills below its agreement will lapse; what it fills above it is drawn from the bought wallet — one never offsets the other.', 'sprint'))
            . "</div>";

        echo "<div class='table-responsive'><table class='table table-sm align-middle sprint-credit-table'>";
        echo "<thead><tr>"
            . "<th>" . SprintCustomer::getTypeName(1) . "</th>"
            . "<th class='text-center'>" . __('Agreed ahead', 'sprint') . "</th>"
            . "<th class='text-center'>" . __('Booked ahead', 'sprint') . "</th>"
            . "<th class='text-center' title='" . __s('Agreed volume the upcoming sprints leave unbooked, summed per sprint', 'sprint') . "'>"
            . __('Will lapse', 'sprint') . "</th>"
            . "<th class='text-center' title='" . __s('What the upcoming sprints claim past their agreement, summed per sprint', 'sprint') . "'>"
            . __('From wallet', 'sprint') . "</th>"
            . "<th class='text-center'>" . __('In backlog', 'sprint') . "</th>"
            . "<th class='text-center' title='" . __s('Free to plan: the wallet after what open work already reserves', 'sprint') . "'>"
            . __('Free', 'sprint') . "</th>"
            . "<th class='text-center'>" . __('Runway', 'sprint') . "</th>"
            . "<th>" . __('Advice', 'sprint') . "</th>"
            . "</tr></thead><tbody>";

        foreach ($rows as $id => $r) {
            echo "<tr>";
            echo "<td><a href='" . SprintCustomer::getFormURLWithID((int)$id) . "'>"
                . "<span class='sprint-matrix-dot' style='background:" . htmlescape($r['color']) . ";'></span>"
                . htmlescape($r['name']) . "</a></td>";
            echo "<td class='text-center'>" . htmlescape(SprintCustomer::formatCredits($r['grant'])) . "</td>";
            echo "<td class='text-center'>" . htmlescape(SprintCustomer::formatCredits($r['claim']))
                . self::fillBar($r['claim'], $r['grant']) . "</td>";
            echo "<td class='text-center'" . ($r['unused'] > 0 ? " style='color:#fd7e14;font-weight:600;'" : '') . ">"
                . htmlescape(SprintCustomer::formatCredits($r['unused'])) . "</td>";
            echo "<td class='text-center'>" . htmlescape(SprintCustomer::formatCredits($r['wallet_needed'])) . "</td>";
            echo "<td class='text-center'>" . htmlescape(SprintCustomer::formatCredits($r['pipeline'])) . "</td>";
            echo "<td class='text-center fw-bold' style='color:" . ($r['left'] < 0 ? '#dc3545' : '#198754') . ";'>"
                . htmlescape(SprintCustomer::formatCredits($r['left'])) . "</td>";
            echo "<td class='text-center'>" . self::runwayLabel($r['left'], $r['burn']) . "</td>";
            echo "<td class='sprint-small'>" . self::advice($r) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";
        echo "</div></div>";
    }

    /**
     * One sentence on what to do about this customer. `left` is the wallet
     * after reservations, so an open claim is never charged against it twice.
     */
    private static function advice(array $r): string
    {
        if ($r['over_cap']) {
            return "<span style='color:#dc3545;'><i class='fas fa-circle-exclamation me-1'></i>"
                . sprintf(
                    htmlescape(__('Over the agreed cap in %s.', 'sprint')),
                    htmlescape(implode(', ', $r['over_cap']))
                ) . "</span>";
        }
        if ($r['balance'] < 0) {
            return "<span style='color:#dc3545;'><i class='fas fa-circle-exclamation me-1'></i>"
                . htmlescape(__('Wallet overdrawn — nothing new should be planned before a top-up.', 'sprint')) . "</span>";
        }
        if ($r['left'] < 0) {
            return "<span style='color:#dc3545;'><i class='fas fa-circle-exclamation me-1'></i>"
                . sprintf(
                    htmlescape(__('Open work needs %s more than the wallet holds — top up or plan less.', 'sprint')),
                    htmlescape(SprintCustomer::formatCredits(abs($r['left'])))
                ) . "</span>";
        }
        if ($r['unused'] > 0 && $r['wallet_needed'] > 0) {
            return "<span style='color:#fd7e14;'><i class='fas fa-scale-unbalanced me-1'></i>"
                . sprintf(
                    htmlescape(__('%1$s of the agreed volume will lapse while %2$s is drawn from the wallet in other sprints — rebalance the work.', 'sprint')),
                    htmlescape(SprintCustomer::formatCredits($r['unused'])),
                    htmlescape(SprintCustomer::formatCredits($r['wallet_needed']))
                ) . "</span>";
        }
        if ($r['unused'] > 0) {
            return "<span style='color:#fd7e14;'><i class='fas fa-hourglass-half me-1'></i>"
                . sprintf(
                    htmlescape(__('%s of the agreed volume is still unbooked and will lapse.', 'sprint')),
                    htmlescape(SprintCustomer::formatCredits($r['unused']))
                ) . "</span>";
        }
        if ($r['wallet_needed'] > 0 && $r['grant'] > 0) {
            return "<span style='color:#6c757d;'><i class='fas fa-arrow-down me-1'></i>"
                . sprintf(
                    htmlescape(__('%s past the agreed volume, drawn from the wallet.', 'sprint')),
                    htmlescape(SprintCustomer::formatCredits($r['wallet_needed']))
                ) . "</span>";
        }
        if ($r['pipeline'] > $r['left'] && $r['grant'] <= 0) {
            return "<span style='color:#fd7e14;'><i class='fas fa-triangle-exclamation me-1'></i>"
                . sprintf(
                    htmlescape(__('%s credits short of covering the backlog waiting for them.', 'sprint')),
                    htmlescape(SprintCustomer::formatCredits($r['pipeline'] - $r['left']))
                ) . "</span>";
        }
        return "<span class='text-muted'><i class='fas fa-check me-1'></i>"
            . htmlescape(__('On plan.', 'sprint')) . "</span>";
    }

    // =========================================================================
    // Ledger
    // =========================================================================

    /** The last credit bookings across every customer. */
    private static function renderLedger(int $limit = 12): void
    {
        $customers = SprintCustomer::visibleIds();
        $rows      = $customers
            ? (new SprintCredit())->find(
                ['plugin_sprint_sprintcustomers_id' => $customers],
                ['date DESC', 'id DESC'],
                $limit
            )
            : [];

        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<h3 class='card-title'><i class='fas fa-receipt me-2'></i>" . __('Recent bookings', 'sprint') . "</h3>";

        if (count($rows) === 0) {
            echo "<div class='text-muted'>"
                . __('No credits booked yet. Open a customer to book their first purchase.', 'sprint') . "</div>";
            echo "</div></div>";
            return;
        }

        echo "<div class='table-responsive'><table class='table table-sm align-middle sprint-credit-table'>";
        echo "<thead><tr>"
            . "<th>" . __('Booking date', 'sprint') . "</th>"
            . "<th>" . SprintCustomer::getTypeName(1) . "</th>"
            . "<th>" . __('Description', 'sprint') . "</th>"
            . "<th>" . __('Reference', 'sprint') . "</th>"
            . "<th class='text-center'>" . __('Credits', 'sprint') . "</th>"
            . "</tr></thead><tbody>";

        foreach ($rows as $row) {
            $cid     = (int)$row['plugin_sprint_sprintcustomers_id'];
            $credits = (float)$row['credits'];
            $name    = SprintCustomer::getNameFor($cid);
            echo "<tr>";
            echo "<td>" . htmlescape(Html::convDate((string)($row['date'] ?? ''))) . "</td>";
            echo "<td>" . ($name !== ''
                ? "<a href='" . SprintCustomer::getFormURLWithID($cid) . "'>"
                    . "<span class='sprint-matrix-dot' style='background:" . htmlescape(SprintCustomer::getColorFor($cid)) . ";'></span>"
                    . htmlescape($name) . "</a>"
                : "<span class='text-muted'>#" . $cid . "</span>") . "</td>";
            echo "<td>" . htmlescape((string)($row['name'] ?? '')) . "</td>";
            echo "<td class='text-muted sprint-small'>" . htmlescape((string)($row['reference'] ?? '')) . "</td>";
            echo "<td class='text-center fw-bold' style='color:" . ($credits < 0 ? '#dc3545' : '#198754') . ";'>"
                . ($credits > 0 ? '+' : '') . htmlescape(SprintCustomer::formatCredits($credits)) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";
        echo "</div></div>";
    }
}
