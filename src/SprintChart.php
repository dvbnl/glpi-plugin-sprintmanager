<?php

namespace GlpiPlugin\Sprint;

/**
 * Shared building blocks for the plugin's inline SVG charts.
 *
 * Every chart draws its own marks. What they share lives here: the scale,
 * the axis labels, the SVG shell, the legend, the tooltip payload, the empty
 * state and the colours. Nothing in here emits a colour attribute for chart
 * chrome: grid, axis and labels are styled by class (`.sprint-chart-*` in
 * css/sprint.css) so they follow the light and the dark theme.
 *
 * Typical use:
 *
 *   $scale = SprintChart::niceScale($max);
 *   $axis  = SprintChart::axisLabels($names, $slotWidth);
 *   $padB  = $axis['height'] + 6;
 *   echo SprintChart::svgOpen($ariaLabel, $w, $h);
 *   echo SprintChart::yAxis($scale, $padL, $w - $padR, $padT, $h - $padB);
 *   foreach ($names as $i => $name) {
 *       echo SprintChart::axisLabel($axis, $i, $cx, $h - $padB);
 *   }
 *   ... marks, each hover target carrying SprintChart::tipAttr() ...
 *   echo "</svg>";
 *   echo SprintChart::legend($series, true);
 *
 * The behaviour (tooltip, legend isolate) is page-wide JavaScript in
 * js/sprint.js, block "charts". It needs no per-chart script: wrap the chart
 * and its legend in an element with `data-chart-scope`, give every mark of a
 * series `class='sprint-chart-series' data-series='KEY'`, and the legend built
 * here isolates them.
 */
final class SprintChart
{
    /** Categorical slots available before a chart has to fold into "Other". */
    const SERIES_COUNT = 8;

    /** Light-theme hex per slot: the fallback inside var() and the print value. */
    const SERIES_FALLBACK = [
        '#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948',
    ];

    /** Colour of a "none" series (no customer, no tag, manual credits). */
    const NEUTRAL = 'var(--sprint-chart-neutral, #6c757d)';

    /** Colour of the folded "Other" series. */
    const OTHER = 'var(--sprint-chart-other, #aab1ba)';

    /**
     * One colour per fixed metric, the same in every chart. Values are CSS
     * colours usable in a `fill`/`stroke` attribute and in an inline style.
     */
    const METRIC_COLORS = [
        'completed' => 'var(--sprint-series-1, #2a78d6)',
        'planned'   => self::NEUTRAL,
        'fastlane'  => 'var(--sprint-series-2, #eb6834)',
        'adhoc'     => 'var(--sprint-series-5, #e87ba4)',
        'carryover' => 'var(--sprint-series-7, #6250d6)',
        'blocked'   => 'var(--sprint-series-8, #e34948)',
    ];

    /** Marker shapes in the order series receive them. */
    const SHAPES = ['circle', 'square', 'diamond', 'triangle'];

    /** Reference dark surface (GLPI dark purple) for contrast checks on user colours. */
    const DARK_SURFACE = '#2a1d3d';

    /** Average glyph width of a 10px axis label, in viewBox units. */
    private const CHAR_W = 5.6;

    // =========================================================================
    // Colours
    // =========================================================================

    /**
     * Colour of categorical slot $index (0-based) as a themed CSS value.
     * Slots are never cycled: an index past SERIES_COUNT returns OTHER, so the
     * caller should fold those series into one "Other" series first.
     */
    public static function seriesColor(int $index): string
    {
        if ($index < 0 || $index >= self::SERIES_COUNT) {
            return self::OTHER;
        }
        return 'var(--sprint-series-' . ($index + 1) . ', ' . self::SERIES_FALLBACK[$index] . ')';
    }

    /** Colour of a fixed metric from METRIC_COLORS, NEUTRAL when unknown. */
    public static function metricColor(string $metric): string
    {
        return self::METRIC_COLORS[$metric] ?? self::NEUTRAL;
    }

    /** WCAG contrast ratio (1 to 21) between two `#rrggbb` colours. */
    public static function contrastRatio(string $hexA, string $hexB): float
    {
        $a = self::luminance($hexA);
        $b = self::luminance($hexB);
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * Perceptual distance between two `#rrggbb` colours (OKLab, times 100).
     * Below roughly 10 two series colours are hard to tell apart.
     */
    public static function colorDistance(string $hexA, string $hexB): float
    {
        $a = self::oklab($hexA);
        $b = self::oklab($hexB);
        return 100 * sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /** Readable text colour (near white or near black) on a `#rrggbb` fill. */
    public static function textOn(string $hex): string
    {
        return self::contrastRatio($hex, '#ffffff') >= self::contrastRatio($hex, '#111827') ? '#ffffff' : '#111827';
    }

    // =========================================================================
    // Scale and axes
    // =========================================================================

    /**
     * A y-scale with round steps: 1, 2, 2.5 or 5 times a power of ten, the
     * smallest that covers $max in at most $ticks + 1 steps (610 gives
     * 0/200/400/600/800, 45 gives 0/10/.../50). A fractional 2.5 step is
     * skipped. Pass $integers for counts and story points: the step is then
     * never smaller than 1.
     *
     * @return array{max:float,step:float,ticks:float[]} ticks run from 0 to max
     */
    public static function niceScale(float $max, int $ticks = 4, bool $integers = false): array
    {
        $max      = $max > 0 ? $max : 1.0;
        $maxSteps = max(2, $ticks + 1);
        $step     = null;
        $from     = (int)floor(log10($max)) - 2;
        for ($exp = $integers ? max(0, $from) : $from; $step === null && $exp < 20; $exp++) {
            foreach ([1.0, 2.0, 2.5, 5.0] as $mult) {
                if ($mult === 2.5 && $exp === 0) {
                    continue;
                }
                $candidate = $mult * (10 ** $exp);
                if (ceil(round($max / $candidate, 9)) <= $maxSteps) {
                    $step = $candidate;
                    break;
                }
            }
        }
        $step  = $step ?? $max;
        $count = max(1, (int)ceil(round($max / $step, 9)));
        $out   = [];
        for ($i = 0; $i <= $count; $i++) {
            $out[] = round($i * $step, 10);
        }
        return ['max' => round($count * $step, 10), 'step' => $step, 'ticks' => $out];
    }

    /** A coordinate formatted for an SVG attribute (two decimals, dot). */
    public static function num(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    /**
     * Split a sprint name into two axis lines: "Sprint 15 - 16/09/2026" gives
     * ["Sprint 15", "16/09/2026"]. A name without a separator is returned
     * whole with an empty second line.
     *
     * @return array{0:string,1:string}
     */
    public static function splitLabel(string $name): array
    {
        $name = trim($name);
        foreach ([' - ', ' – ', ' — '] as $sep) {
            $pos = mb_strpos($name, $sep);
            if ($pos !== false && $pos > 0) {
                return [trim(mb_substr($name, 0, $pos)), trim(mb_substr($name, $pos + mb_strlen($sep)))];
            }
        }
        return [$name, ''];
    }

    /**
     * Decide how the labels of a categorical x-axis are drawn for the given
     * slot width (viewBox units): two lines when both fit, else the first line
     * only, else rotated. Labels are only thinned out when even rotated ones
     * would touch, and the first and last label are always kept.
     *
     * @param string[] $names     one name per slot, in axis order
     * @param float    $slotWidth width available to one label
     * @return array{mode:string,height:int,lines:array<int,array{0:string,1:string}>,skip:array<int,bool>}
     *         mode is 'two', 'one' or 'rotate'. height is the room to reserve
     *         below the axis. Pass the array to axisLabel().
     */
    public static function axisLabels(array $names, float $slotWidth): array
    {
        $names = array_values($names);
        $lines = [];
        $w1    = $w2 = 0;
        foreach ($names as $i => $name) {
            $lines[$i] = self::splitLabel((string)$name);
            $w1 = max($w1, mb_strlen($lines[$i][0]));
            $w2 = max($w2, mb_strlen($lines[$i][1]));
        }
        $room = max(0.0, $slotWidth - 4);
        $skip = [];

        if ($w1 * self::CHAR_W <= $room && $w2 > 0 && $w2 * self::CHAR_W * 0.9 <= $room) {
            return ['mode' => 'two', 'height' => 30, 'lines' => $lines, 'skip' => $skip];
        }
        if ($w1 * self::CHAR_W <= $room) {
            return ['mode' => 'one', 'height' => 18, 'lines' => $lines, 'skip' => $skip];
        }

        // Rotated: keep the first line, cut at 16 characters.
        $longest = 0;
        foreach ($lines as $i => $pair) {
            if (mb_strlen($pair[0]) > 16) {
                $lines[$i][0] = mb_substr($pair[0], 0, 15) . '…';
            }
            $longest = max($longest, mb_strlen($lines[$i][0]));
        }
        $n     = count($names);
        $every = $slotWidth > 0 ? max(1, (int)ceil(17 / $slotWidth)) : 1;
        if ($every > 1) {
            foreach (array_keys($lines) as $i) {
                $isEdge = $i === 0 || $i === $n - 1;
                if (!$isEdge && ($i % $every !== 0 || ($n - 1 - $i) < $every)) {
                    $skip[$i] = true;
                }
            }
        }
        return [
            'mode'   => 'rotate',
            'height' => 16 + (int)ceil($longest * self::CHAR_W * 0.574),
            'lines'  => $lines,
            'skip'   => $skip,
        ];
    }

    /**
     * The SVG text for label $i of an axis built by axisLabels().
     *
     * @param array  $axis  result of axisLabels()
     * @param int    $i     slot index
     * @param float  $cx    horizontal centre of the slot
     * @param float  $y     y of the axis line (labels are drawn below it)
     * @param string $class extra class, for example 'is-dim'
     */
    public static function axisLabel(array $axis, int $i, float $cx, float $y, string $class = ''): string
    {
        if (!isset($axis['lines'][$i]) || !empty($axis['skip'][$i])) {
            return '';
        }
        [$line1, $line2] = $axis['lines'][$i];
        $cls = 'sprint-chart-label' . ($class !== '' ? ' ' . htmlescape($class) : '');
        $x   = self::num($cx);

        if ($axis['mode'] === 'rotate') {
            $ty = self::num($y + 12);
            return "<text class='{$cls}' x='{$x}' y='{$ty}' text-anchor='end' transform='rotate(-35 {$x} {$ty})'>"
                . htmlescape($line1) . "</text>";
        }
        $out = "<text class='{$cls}' x='{$x}' y='" . self::num($y + 14) . "' text-anchor='middle'>"
            . htmlescape($line1) . "</text>";
        if ($axis['mode'] === 'two' && $line2 !== '') {
            $out .= "<text class='{$cls} sprint-chart-label-sub' x='{$x}' y='" . self::num($y + 26) . "' text-anchor='middle'>"
                . htmlescape($line2) . "</text>";
        }
        return $out;
    }

    /**
     * Opening `<svg>` tag: viewBox, `role='img'`, the aria-label and the
     * inherited page font. Close it yourself with `</svg>`.
     *
     * @param string $ariaLabel plain text: the title plus one sentence summary
     * @param string $class     extra classes next to `sprint-chart-svg`
     */
    public static function svgOpen(string $ariaLabel, int $w, int $h, string $class = ''): string
    {
        return "<svg viewBox='0 0 {$w} {$h}' class='sprint-chart-svg" . ($class !== '' ? ' ' . htmlescape($class) : '')
            . "' preserveAspectRatio='xMinYMid meet' role='img' font-family='inherit' aria-label='"
            . htmlescape($ariaLabel) . "'>";
    }

    /**
     * Horizontal gridlines with their tick labels on the left, and the
     * baseline at 0. Draw it before the marks.
     *
     * @param array         $scale   result of niceScale()
     * @param float         $x0      left edge of the plot
     * @param float         $x1      right edge of the plot
     * @param float         $yTop    y of the scale maximum
     * @param float         $yBottom y of 0
     * @param callable|null $format  fn(float): string for the tick text
     */
    public static function yAxis(array $scale, float $x0, float $x1, float $yTop, float $yBottom, ?callable $format = null): string
    {
        $format ??= static fn(float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        $out = '';
        foreach ($scale['ticks'] as $tick) {
            $y   = self::yAt((float)$tick, $scale, $yTop, $yBottom);
            $cls = (float)$tick === 0.0 ? 'sprint-chart-axis' : 'sprint-chart-grid';
            $out .= "<line class='{$cls}' x1='" . self::num($x0) . "' y1='" . self::num($y)
                . "' x2='" . self::num($x1) . "' y2='" . self::num($y) . "'/>";
            $out .= "<text class='sprint-chart-label' x='" . self::num($x0 - 8) . "' y='" . self::num($y + 3.5)
                . "' text-anchor='end'>" . htmlescape((string)$format((float)$tick)) . "</text>";
        }
        return $out;
    }

    /** The y coordinate of $value on a niceScale() between $yTop (max) and $yBottom (0). */
    public static function yAt(float $value, array $scale, float $yTop, float $yBottom): float
    {
        $max = (float)$scale['max'] > 0 ? (float)$scale['max'] : 1.0;
        return $yTop + ($yBottom - $yTop) * (1 - max(0.0, min($value, $max)) / $max);
    }

    // =========================================================================
    // Marks
    // =========================================================================

    /**
     * A point marker whose shape follows the series index: circle, square,
     * diamond, triangle, then round again. Colour and class go in $attrs.
     *
     * @param int    $index 0-based series index
     * @param string $attrs raw, already escaped attributes, for example "fill='#0d6efd'"
     */
    public static function marker(int $index, float $cx, float $cy, float $r = 4.0, string $attrs = ''): string
    {
        $attrs = $attrs !== '' ? ' ' . $attrs : '';
        $n     = [self::class, 'num'];
        switch (self::SHAPES[(($index % 4) + 4) % 4]) {
            case 'square':
                $s = $r * 0.9;
                return "<rect x='" . $n($cx - $s) . "' y='" . $n($cy - $s) . "' width='" . $n(2 * $s) . "' height='" . $n(2 * $s) . "'{$attrs}/>";
            case 'diamond':
                $d = $r * 1.25;
                return "<path d='M" . $n($cx) . ',' . $n($cy - $d) . ' L' . $n($cx + $d) . ',' . $n($cy)
                    . ' L' . $n($cx) . ',' . $n($cy + $d) . ' L' . $n($cx - $d) . ',' . $n($cy) . " Z'{$attrs}/>";
            case 'triangle':
                $t = $r * 1.2;
                return "<path d='M" . $n($cx) . ',' . $n($cy - $t) . ' L' . $n($cx + $t) . ',' . $n($cy + $t * 0.85)
                    . ' L' . $n($cx - $t) . ',' . $n($cy + $t * 0.85) . " Z'{$attrs}/>";
            default:
                return "<circle cx='" . $n($cx) . "' cy='" . $n($cy) . "' r='" . $n($r) . "'{$attrs}/>";
        }
    }

    /**
     * The ` data-tip='...'` attribute (leading space included) read by the
     * shared tooltip `sprintChartTooltip` in js/sprint.js. Put it on any
     * element, SVG or HTML. Keep a `<title>` child or a `title` attribute next
     * to it as the fallback without JavaScript: the script hides it on hover.
     *
     * @param string $title heading of the tooltip
     * @param array  $rows  each row is [label, value] or [label, value, cssColour]
     */
    public static function tipAttr(string $title, array $rows = []): string
    {
        $clean = static fn($s): string => trim((string)preg_replace('/[\t\r\n]+/', ' ', (string)$s));
        $lines = [$clean($title)];
        foreach ($rows as $row) {
            $row     = array_values((array)$row);
            $lines[] = $clean($row[0] ?? '') . "\t" . $clean($row[1] ?? '') . "\t" . $clean($row[2] ?? '');
        }
        return " data-tip='" . htmlescape(implode("\n", $lines)) . "'";
    }

    // =========================================================================
    // Legend and empty state
    // =========================================================================

    /**
     * One legend for every chart. Each entry of $series is an array:
     *  - label  (string, required)
     *  - color  (string) CSS colour of the swatch
     *  - mark   'line' (default), 'bar', 'ref' (dashed reference line) or 'hatch'
     *  - shape  (int) series index for the marker on a 'line' swatch
     *  - key    (string|int) series key, matched against `data-series` on the marks
     *  - value  (string) optional figure shown after the label
     *  - muted  (bool) dimmed entry, for a series without data
     *  - pressed (bool) entry starts as the isolated one
     *  - href   (string) makes the entry a link instead of a button
     *  - static (bool) never clickable, even in an interactive legend
     *
     * With $interactive an entry that has a key becomes a
     * `<button aria-pressed>`. Clicking it isolates that series inside the
     * closest `[data-chart-scope]` ancestor (js/sprint.js, block "charts").
     */
    public static function legend(array $series, bool $interactive): string
    {
        $out = "<div class='sprint-chart-legend'>";
        foreach ($series as $item) {
            $label   = (string)($item['label'] ?? '');
            $key     = isset($item['key']) ? (string)$item['key'] : null;
            $pressed = !empty($item['pressed']);
            $cls     = 'sprint-chart-legend-item' . (!empty($item['muted']) ? ' is-zero' : '') . ($pressed ? ' is-active' : '');
            $body    = self::swatch($item) . "<span class='sprint-chart-legend-label'>" . htmlescape($label) . "</span>";
            if (isset($item['value']) && (string)$item['value'] !== '') {
                $body .= " <span class='sprint-chart-legend-value'>" . htmlescape((string)$item['value']) . "</span>";
            }
            $data = $key !== null ? " data-series='" . htmlescape($key) . "'" : '';

            if (!empty($item['href'])) {
                $out .= "<a class='{$cls}' href='" . htmlescape((string)$item['href']) . "'{$data}"
                    . ($pressed ? " aria-current='true'" : '') . ">{$body}</a>";
            } elseif ($interactive && $key !== null && empty($item['static'])) {
                $out .= "<button type='button' class='{$cls}'{$data} aria-pressed='" . ($pressed ? 'true' : 'false') . "'>{$body}</button>";
            } else {
                $out .= "<span class='{$cls} is-static'{$data}>{$body}</span>";
            }
        }
        return $out . "</div>";
    }

    /**
     * The block shown instead of a chart that has nothing to draw.
     *
     * @param string $icon  Font Awesome classes, for example 'fas fa-chart-line'
     * @param string $title one sentence: what is missing
     * @param string $hint  where to fix it, '' for none
     */
    public static function emptyState(string $icon, string $title, string $hint): string
    {
        return "<div class='sprint-chart-empty text-center text-muted py-3'>"
            . "<i class='" . htmlescape($icon) . " sprint-chart-empty-icon' aria-hidden='true'></i>"
            . "<div class='fw-bold'>" . htmlescape($title) . "</div>"
            . ($hint !== '' ? "<div class='sprint-small'>" . htmlescape($hint) . "</div>" : '')
            . "</div>";
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** One swatch shape per mark type, so a legend reads the same everywhere. */
    private static function swatch(array $item): string
    {
        $color = htmlescape((string)($item['color'] ?? self::NEUTRAL));
        switch ((string)($item['mark'] ?? 'line')) {
            case 'bar':
                return "<span class='sprint-chart-swatch sprint-chart-swatch-bar' style='background:{$color}' aria-hidden='true'></span>";
            case 'hatch':
                return "<span class='sprint-chart-swatch sprint-chart-swatch-bar sprint-chart-hatch' style='background-color:{$color}' aria-hidden='true'></span>";
            case 'ref':
                return "<svg class='sprint-chart-swatch sprint-chart-swatch-line' width='22' height='10' viewBox='0 0 22 10' aria-hidden='true'>"
                    . "<line x1='1' y1='5' x2='21' y2='5' stroke='{$color}' stroke-width='2' stroke-dasharray='5 3'/></svg>";
            default:
                return "<svg class='sprint-chart-swatch sprint-chart-swatch-line' width='22' height='10' viewBox='0 0 22 10' aria-hidden='true'>"
                    . "<line x1='1' y1='5' x2='21' y2='5' stroke='{$color}' stroke-width='2' stroke-linecap='round'/>"
                    . (isset($item['shape']) ? self::marker((int)$item['shape'], 11, 5, 3.2, "fill='{$color}'") : '')
                    . "</svg>";
        }
    }

    /** @return array{0:float,1:float,2:float} linear sRGB, each 0 to 1 */
    private static function linearRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '6c757d';
        }
        $out = [];
        foreach ([0, 2, 4] as $o) {
            $c     = hexdec(substr($hex, $o, 2)) / 255;
            $out[] = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        return $out;
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::linearRgb($hex);
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** @return array{0:float,1:float,2:float} OKLab L, a, b */
    private static function oklab(string $hex): array
    {
        [$r, $g, $b] = self::linearRgb($hex);
        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);
        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }
}
