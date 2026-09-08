<?php

namespace GlpiPlugin\Sprint;

/**
 * Pure credit arithmetic: no GLPI, no database, no session. Every function
 * takes plain arrays and returns plain arrays, so the money rules can be
 * exercised on their own (see tests/credit_math.php) and every screen that
 * shows a credit figure gets it from the same place.
 *
 * Units: credits as floats, rounded to two decimals at the boundaries.
 * Dates: plain Y-m-d strings, '' for "none".
 *
 * Vocabulary (see README, "How a sprint is funded"):
 *  - grant           credits agreed for one customer in one sprint
 *  - consumed        credits on delivered (done) items
 *  - reserved        credits on open items that sit in a sprint
 *  - pipeline        credits on backlog items, not committed to a sprint yet
 *  - wallet          credits bought through the ledger, drawn on past the grant
 */
final class SprintCreditMath
{
    public const SOURCE_OVERRIDE = 'override';
    public const SOURCE_RETAINER = 'retainer';
    public const SOURCE_CLOSED   = 'closed';
    public const SOURCE_ENTITY   = 'entity';
    public const SOURCE_NONE     = 'none';

    public static function round2($value): float
    {
        return round((float)$value, 2);
    }

    /** '' for an empty/zero date, the plain Y-m-d otherwise. */
    public static function dateValue($value): string
    {
        $value = trim((string)$value);
        if ($value === '' || $value === 'NULL' || str_starts_with($value, '0000-00-00')) {
            return '';
        }
        return substr($value, 0, 10);
    }

    // =========================================================================
    // Agreement
    // =========================================================================

    /**
     * The retainer rule in force for a sprint: of the customer's rules, the
     * one with the latest start date on or before the sprint's start date.
     * A rule runs until the next one starts, so a newer rule always ends the
     * one before it; a rule with 0 credits ends the retainer. A rule without
     * a start date applies from the beginning. A sprint without a start date
     * is judged on $today, so it follows the rule in force now.
     *
     * @param array  $rules  list of ['date_start' => ''|Y-m-d, 'credits' => float, 'cap' => float],
     *                       oldest first (empty date first, then date, then id)
     * @param array  $sprint date_start
     * @param string $today  Y-m-d, defaults to the current day
     * @return array{grant: float, cap: float}
     */
    public static function retainerFor(array $rules, array $sprint, string $today = ''): array
    {
        $on = self::dateValue($sprint['date_start'] ?? null) ?: (self::dateValue($today) ?: date('Y-m-d'));
        $inForce = null;
        foreach ($rules as $rule) {
            $from = self::dateValue($rule['date_start'] ?? null);
            if ($from === '' || $from <= $on) {
                // Rules come oldest first, so the last match is the newest.
                $inForce = $rule;
            }
        }
        if ($inForce === null) {
            return ['grant' => 0.0, 'cap' => 0.0];
        }
        return [
            'grant' => max(0.0, self::round2($inForce['credits'] ?? 0)),
            'cap'   => max(0.0, self::round2($inForce['cap'] ?? 0)),
        ];
    }

    /**
     * The agreement in force for one customer × sprint. Single resolver for
     * every caller:
     *
     *  1. a customer that is not valid for the sprint's entity gets nothing;
     *  2. an explicit per-sprint override wins, and 0 is an explicit zero;
     *  3. a completed sprint without a recorded agreement grants nothing — it
     *     closed before one applied, and raising the retainer today must not
     *     rewrite what lapsed back then;
     *  4. otherwise the standing retainer inside its date window.
     *
     * The cap follows the same override-then-rule order.
     *
     * @param array      $rules     the customer's retainer rules, see retainerFor()
     * @param array      $sprint    status, date_start
     * @param array|null $override  ['credits' => ?float, 'max' => ?float]
     * @return array{grant: float, cap: float, source: string}
     */
    public static function resolveAgreement(array $rules, array $sprint, ?array $override, bool $fitsEntity = true, string $today = ''): array
    {
        if (!$fitsEntity) {
            return ['grant' => 0.0, 'cap' => 0.0, 'source' => self::SOURCE_ENTITY];
        }
        $standing    = self::retainerFor($rules, $sprint, $today);
        $overCredits = $override['credits'] ?? null;
        $overMax     = $override['max'] ?? null;
        $cap = $overMax !== null ? max(0.0, self::round2($overMax)) : $standing['cap'];

        if ($overCredits !== null) {
            return ['grant' => max(0.0, self::round2($overCredits)), 'cap' => $cap, 'source' => self::SOURCE_OVERRIDE];
        }
        if ((string)($sprint['status'] ?? '') === 'completed') {
            return ['grant' => 0.0, 'cap' => $cap, 'source' => self::SOURCE_CLOSED];
        }
        return [
            'grant'  => $standing['grant'],
            'cap'    => $cap,
            'source' => $standing['grant'] > 0 ? self::SOURCE_RETAINER : self::SOURCE_NONE,
        ];
    }

    // =========================================================================
    // One sprint
    // =========================================================================

    /**
     * Split one sprint's claim over its grant and the wallet.
     *
     * Delivered work spends the grant first; open work reserves what is left
     * of it and needs the wallet for the rest. A completed sprint has no open
     * work left to reserve — whatever was not delivered when it closed is not
     * charged (it is carried over or sent back), and the unused grant lapses.
     *
     * @return array{
     *   grant: float, cap: float, consumed: float, reserved: float, claimed: float,
     *   grant_used: float, grant_remaining: float, grant_reserved: float,
     *   wallet_consumed: float, wallet_reserved: float, lapsed: float, over_cap: bool
     * }
     */
    public static function splitSprint(float $grant, float $cap, float $consumed, float $reserved, bool $completed): array
    {
        $grant    = max(0.0, self::round2($grant));
        $cap      = max(0.0, self::round2($cap));
        $consumed = max(0.0, self::round2($consumed));
        $reserved = $completed ? 0.0 : max(0.0, self::round2($reserved));

        $grantUsed      = min($grant, $consumed);
        $grantRemaining = self::round2($grant - $grantUsed);
        $grantReserved  = min($grantRemaining, $reserved);
        $claimed        = self::round2($consumed + $reserved);

        return [
            'grant'           => $grant,
            'cap'             => $cap,
            'consumed'        => $consumed,
            'reserved'        => $reserved,
            'claimed'         => $claimed,
            'grant_used'      => self::round2($grantUsed),
            'grant_remaining' => $grantRemaining,
            'grant_reserved'  => self::round2($grantReserved),
            'wallet_consumed' => self::round2(max(0.0, $consumed - $grant)),
            'wallet_reserved' => self::round2(max(0.0, $reserved - $grantRemaining)),
            'lapsed'          => $completed ? $grantRemaining : 0.0,
            'over_cap'        => $cap > 0 && $claimed > $cap,
        ];
    }

    // =========================================================================
    // Wallet
    // =========================================================================

    /**
     * The wallet as of a date, built chronologically from the ledger and the
     * draws the completed sprints made on it.
     *
     * Allocation rule: a draw is covered by the lots valid on its date (booked
     * on or before it, not yet expired), taking the lot that expires first,
     * then the oldest booking, then the lowest id. A negative booking is a
     * write-off and draws the same way; its own expiry date means nothing.
     * Only the part of a lot nobody drew on can lapse. Bookings dated after
     * $asOf are reported apart and do not count.
     *
     * @param array  $bookings list of ['id' => int, 'date' => Y-m-d, 'expire' => ''|Y-m-d, 'credits' => float]
     * @param array  $draws    list of ['date' => Y-m-d, 'credits' => float > 0]
     * @param string $asOf     Y-m-d
     * @return array{
     *   booked: float, corrections: float, consumed: float, lapsed: float,
     *   uncovered: float, balance: float, future: float
     * }
     */
    public static function wallet(array $bookings, array $draws, string $asOf): array
    {
        $asOf   = self::dateValue($asOf) ?: date('Y-m-d');
        $future = 0.0;
        $events = [];
        $seq    = 0;

        foreach ($bookings as $b) {
            $credits = self::round2($b['credits'] ?? 0);
            if ($credits == 0.0) {
                continue;
            }
            $date = self::dateValue($b['date'] ?? null) ?: $asOf;
            if ($date > $asOf) {
                if ($credits > 0) {
                    $future += $credits;
                }
                continue;
            }
            $events[] = [
                'date'   => $date,
                // Lots before debits on the same day: a purchase booked the
                // day the work was delivered is meant to cover it.
                'order'  => $credits > 0 ? 0 : 1,
                'id'     => (int)($b['id'] ?? 0),
                'seq'    => $seq++,
                'type'   => $credits > 0 ? 'lot' : 'debit',
                'amount' => abs($credits),
                'expire' => $credits > 0 ? self::dateValue($b['expire'] ?? null) : '',
                'kind'   => $credits > 0 ? 'purchase' : 'correction',
            ];
        }
        foreach ($draws as $d) {
            $amount = self::round2($d['credits'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $date = self::dateValue($d['date'] ?? null) ?: $asOf;
            $events[] = [
                'date'   => min($date, $asOf),
                'order'  => 1,
                'id'     => 0,
                'seq'    => $seq++,
                'type'   => 'debit',
                'amount' => $amount,
                'expire' => '',
                'kind'   => 'draw',
            ];
        }
        usort($events, static function (array $a, array $b): int {
            return [$a['date'], $a['order'], $a['id'], $a['seq']] <=> [$b['date'], $b['order'], $b['id'], $b['seq']];
        });

        $lots        = [];
        $booked      = 0.0;
        $corrections = 0.0;
        $consumed    = 0.0;
        $uncovered   = 0.0;

        foreach ($events as $e) {
            if ($e['type'] === 'lot') {
                $booked += $e['amount'];
                $lots[]  = ['date' => $e['date'], 'expire' => $e['expire'], 'id' => $e['id'], 'remaining' => $e['amount']];
                continue;
            }
            if ($e['kind'] === 'draw') {
                $consumed += $e['amount'];
            } else {
                $corrections += $e['amount'];
            }
            $left = $e['amount'];
            $valid = [];
            foreach ($lots as $i => $lot) {
                if ($lot['remaining'] > 0 && $lot['date'] <= $e['date'] && ($lot['expire'] === '' || $lot['expire'] >= $e['date'])) {
                    $valid[] = $i;
                }
            }
            usort($valid, static function (int $x, int $y) use ($lots): int {
                $ex = $lots[$x]['expire'] === '' ? '9999-12-31' : $lots[$x]['expire'];
                $ey = $lots[$y]['expire'] === '' ? '9999-12-31' : $lots[$y]['expire'];
                return [$ex, $lots[$x]['date'], $lots[$x]['id']] <=> [$ey, $lots[$y]['date'], $lots[$y]['id']];
            });
            foreach ($valid as $i) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, $lots[$i]['remaining']);
                $lots[$i]['remaining'] = self::round2($lots[$i]['remaining'] - $take);
                $left = self::round2($left - $take);
            }
            if ($left > 0) {
                $uncovered += $left;
            }
        }

        $lapsed  = 0.0;
        $balance = 0.0;
        foreach ($lots as $lot) {
            if ($lot['expire'] !== '' && $lot['expire'] < $asOf) {
                $lapsed += $lot['remaining'];
            } else {
                $balance += $lot['remaining'];
            }
        }

        return [
            'booked'      => self::round2($booked),
            'corrections' => self::round2($corrections),
            'consumed'    => self::round2($consumed),
            'lapsed'      => self::round2($lapsed),
            'uncovered'   => self::round2($uncovered),
            'balance'     => self::round2($balance - $uncovered),
            'future'      => self::round2($future),
        ];
    }

    // =========================================================================
    // Forecast
    // =========================================================================

    /**
     * Sum the upcoming sprints one by one: room left in one sprint never
     * covers an overrun in another, so both figures are per-sprint sums, not
     * the difference between two portfolio totals.
     *
     * @param array $cells list of ['grant' => float, 'claimed' => float]
     * @return array{grant: float, claim: float, unused: float, wallet_needed: float}
     */
    public static function forecast(array $cells): array
    {
        $grant = $claim = $unused = $needed = 0.0;
        foreach ($cells as $cell) {
            $g = max(0.0, self::round2($cell['grant'] ?? 0));
            $c = max(0.0, self::round2($cell['claimed'] ?? 0));
            $grant  += $g;
            $claim  += $c;
            $unused += max(0.0, $g - $c);
            $needed += max(0.0, $c - $g);
        }
        return [
            'grant'         => self::round2($grant),
            'claim'         => self::round2($claim),
            'unused'        => self::round2($unused),
            'wallet_needed' => self::round2($needed),
        ];
    }
}
