<?php

namespace GlpiPlugin\Sprint;

/**
 * Pure capacity arithmetic for one member in one sprint: no GLPI, no
 * database, no session, so every capacity view (team cards, dashboards,
 * backlog forecasts, the report and its CSV) gets its numbers, its warning
 * state and its bar geometry from the same place, and the rules can be
 * exercised on their own (see tests/static_contracts.php).
 *
 * Units: capacity as a share of a full-time sprint, in percentage points
 * ("80%" = 0.8 FTE for this sprint). Remaining is a difference in those
 * points, never a relative utilisation ("15% remaining" of 80% available,
 * not "81% busy").
 *
 * Vocabulary:
 *  - set          the agreed share (member settings)
 *  - leave        not available: leave / absence / availability exceptions
 *  - available    effectively usable capacity (set − leave)
 *  - planned      regular + fastlane + helping colleagues; resolved help was
 *                 spent and keeps counting, like the central calculation
 *  - remaining    available − planned (0 when over)
 *  - over         planned − available (0 when it fits)
 *  - proposed     backlog work pencilled in, not assigned yet — shown apart
 */
final class SprintCapacityMath
{
    /** "Almost full": fewer than this many percentage points remain. */
    public const ALMOST_FULL_PP = 10.0;
    /** Float noise below this is treated as zero (half a 0.5 step is far above it). */
    public const EPSILON = 0.01;

    public const STATE_NONE        = 'none';        // no availability and nothing planned
    public const STATE_NORMAL      = 'normal';
    public const STATE_ALMOST_FULL = 'almost_full';
    public const STATE_FULL        = 'full';        // exactly 0 remaining
    public const STATE_OVER        = 'over';        // more planned than available

    private static function r(float $v): float
    {
        return round($v, 2);
    }

    /**
     * One warning policy for every view. Low availability on its own is not
     * a signal: nothing planned is always neutral.
     */
    public static function state(float $available, float $load): string
    {
        $available = max($available, 0.0);
        $load      = max($load, 0.0);
        if ($load > $available + self::EPSILON) {
            return self::STATE_OVER;
        }
        if ($load <= self::EPSILON) {
            return $available <= self::EPSILON ? self::STATE_NONE : self::STATE_NORMAL;
        }
        $left = $available - $load;
        if ($left <= self::EPSILON) {
            return self::STATE_FULL;
        }
        return $left < self::ALMOST_FULL_PP ? self::STATE_ALMOST_FULL : self::STATE_NORMAL;
    }

    /**
     * @return array{set:float,leave:float,available:float,regular:float,fastlane:float,
     *               helping:float,helping_open:float,helping_resolved:float,planned:float,
     *               remaining:float,over:float,balance:float,state:string,proposed:float,
     *               load_after_proposals:float,remaining_after_proposals:float,
     *               over_after_proposals:float,state_after_proposals:string}
     */
    public static function figures(
        float $set,
        float $available,
        float $regular,
        float $fastlane,
        float $helping,
        float $helpingOpen = 0.0,
        float $proposed = 0.0
    ): array {
        $available = max($available, 0.0);
        $set       = max($set, $available);
        $helping   = max($helping, 0.0);
        $proposed  = max($proposed, 0.0);
        $planned   = self::r($regular + $fastlane + $helping);
        $balance   = self::r($available - $planned);
        $after     = self::r($planned + $proposed);
        $balAfter  = self::r($available - $after);

        return [
            'set'              => self::r($set),
            'leave'            => self::r($set - $available),
            'available'        => self::r($available),
            'regular'          => self::r($regular),
            'fastlane'         => self::r($fastlane),
            'helping'          => self::r($helping),
            'helping_open'     => self::r(min(max($helpingOpen, 0.0), $helping)),
            'helping_resolved' => self::r(max($helping - max($helpingOpen, 0.0), 0.0)),
            'planned'          => $planned,
            'remaining'        => max($balance, 0.0),
            'over'             => max(-$balance, 0.0),
            'balance'          => $balance,
            'state'            => self::state($available, $planned),
            'proposed'         => self::r($proposed),
            'load_after_proposals'      => $after,
            'remaining_after_proposals' => max($balAfter, 0.0),
            'over_after_proposals'      => max(-$balAfter, 0.0),
            'state_after_proposals'     => self::state($available, $after),
        ];
    }

    /**
     * Bar geometry, in percent of the bar width. The scale is the larger of
     * the set capacity and the total load, so nothing is clipped. Work
     * segments (regular, fastlane, helping, then proposed) are laid side by
     * side and together span exactly the load; overload is an overlay from
     * the available marker to the end of the load — it marks segments that
     * are already drawn, it never adds width of its own. Not-available
     * capacity sits between available and set, underneath any overload.
     *
     * @param array $f figures() result
     * @return array{scale:float,segments:array<string,float>,marker:float,
     *               unavailable_left:float,unavailable_width:float,
     *               overflow_left:float,overflow_width:float}
     */
    public static function bar(array $f): array
    {
        $load  = (float)$f['planned'] + (float)($f['proposed'] ?? 0);
        $scale = max((float)$f['set'], (float)$f['available'], $load);
        $w     = static fn(float $v): float => $scale > 0 ? round(100 * max($v, 0.0) / $scale, 2) : 0.0;

        $segments = [];
        foreach (['regular', 'fastlane', 'helping', 'proposed'] as $key) {
            $segments[$key] = $w((float)($f[$key] ?? 0));
        }
        $marker = $w((float)$f['available']);
        $over   = max($load - (float)$f['available'], 0.0);
        return [
            'scale'             => self::r($scale),
            'segments'          => $segments,
            'marker'            => $marker,
            'unavailable_left'  => $marker,
            'unavailable_width' => $w((float)$f['leave']),
            'overflow_left'     => $over > self::EPSILON ? $marker : 0.0,
            'overflow_width'    => $over > self::EPSILON ? $w($over) : 0.0,
        ];
    }
}
