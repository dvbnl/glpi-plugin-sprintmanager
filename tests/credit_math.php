<?php

/**
 * Behaviour tests for the credit arithmetic (SprintCreditMath). Pure PHP: no
 * GLPI bootstrap, explicit amounts and dates, the scenarios from the
 * Customers & Credits repair plan.
 */

require_once dirname(__DIR__) . '/src/SprintCreditMath.php';

use GlpiPlugin\Sprint\SprintCreditMath as M;

$failures = 0;
$checks   = 0;
function check(string $name, $expected, $actual): void
{
    global $failures, $checks;
    $checks++;
    $ok = is_float($expected) || is_int($expected)
        ? abs((float)$expected - (float)$actual) < 0.005
        : $expected === $actual;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n");
    }
}
function lot(int $id, string $date, float $credits, string $expire = ''): array
{
    return ['id' => $id, 'date' => $date, 'expire' => $expire, 'credits' => $credits];
}
function draw(string $date, float $credits): array
{
    return ['date' => $date, 'credits' => $credits];
}

// -----------------------------------------------------------------------------
// Wallet: allocation per purchase and date
// -----------------------------------------------------------------------------

// Buy 100; use 60; the remaining 40 lapse; buy 100 again.
$w = M::wallet(
    [lot(1, '2026-01-01', 100, '2026-03-31'), lot(2, '2026-04-01', 100)],
    [draw('2026-02-15', 60)],
    '2026-05-01'
);
check('relapse: balance', 100, $w['balance']);
check('relapse: lapsed', 40, $w['lapsed']);
check('relapse: consumed', 60, $w['consumed']);
check('relapse: uncovered', 0, $w['uncovered']);

// Buy 100; nothing used; the purchase expires.
$w = M::wallet([lot(1, '2026-01-01', 100, '2026-03-31')], [], '2026-05-01');
check('full lapse: balance', 0, $w['balance']);
check('full lapse: lapsed', 100, $w['lapsed']);

// Buy 100; use 100 before expiry: nothing extra is written off on expiry.
$w = M::wallet([lot(1, '2026-01-01', 100, '2026-03-31')], [draw('2026-02-01', 100)], '2026-05-01');
check('spent before expiry: balance', 0, $w['balance']);
check('spent before expiry: lapsed', 0, $w['lapsed']);

// A purchase dated after the as-of date does not raise today's balance.
$w = M::wallet([lot(1, '2026-01-01', 50), lot(2, '2026-12-01', 500)], [], '2026-06-01');
check('future booking: balance', 50, $w['balance']);
check('future booking: future', 500, $w['future']);
check('future booking: booked', 50, $w['booked']);

// Consumption after the only purchase expired is not covered by it.
$w = M::wallet([lot(1, '2026-01-01', 100, '2026-03-31')], [draw('2026-04-15', 30)], '2026-05-01');
check('draw after expiry: lapsed', 100, $w['lapsed']);
check('draw after expiry: uncovered', 30, $w['uncovered']);
check('draw after expiry: balance', -30, $w['balance']);

// Two purchases with different expiry dates: the one expiring first is drawn first.
$w = M::wallet(
    [lot(1, '2026-01-01', 100), lot(2, '2026-01-10', 100, '2026-06-30')],
    [draw('2026-02-01', 70)],
    '2026-07-15'
);
check('earliest expiry first: lapsed', 30, $w['lapsed']);
check('earliest expiry first: balance', 100, $w['balance']);

// Same expiry: the oldest booking, then the lowest id, goes first.
$w = M::wallet(
    [lot(5, '2026-01-01', 10, '2026-06-30'), lot(3, '2026-01-01', 10, '2026-06-30'), lot(4, '2025-12-01', 10, '2026-06-30')],
    [draw('2026-02-01', 15)],
    '2026-03-01'
);
check('stable order: balance', 15, $w['balance']);

// A purchase booked the same day as the delivery covers it.
$w = M::wallet([lot(1, '2026-03-01', 40)], [draw('2026-03-01', 40)], '2026-03-02');
check('same-day purchase: uncovered', 0, $w['uncovered']);

// A draw dated before the purchase cannot use it.
$w = M::wallet([lot(1, '2026-03-05', 40)], [draw('2026-03-01', 40)], '2026-03-10');
check('draw before purchase: uncovered', 40, $w['uncovered']);
check('draw before purchase: balance', 0, $w['balance']);

// A negative booking is a write-off; its expiry date never restores credits.
$w = M::wallet(
    [lot(1, '2026-01-01', 100), lot(2, '2026-02-01', -30, '2026-02-15')],
    [],
    '2026-06-01'
);
check('correction: balance', 70, $w['balance']);
check('correction: corrections', 30, $w['corrections']);
check('correction: lapsed', 0, $w['lapsed']);

// A correction larger than the wallet leaves it negative, explicitly.
$w = M::wallet([lot(1, '2026-01-01', 20), lot(2, '2026-02-01', -50)], [], '2026-06-01');
check('over-correction: balance', -30, $w['balance']);
check('over-correction: uncovered', 30, $w['uncovered']);

// A backdated purchase covers a draw that happened after its booking date.
$w = M::wallet([lot(9, '2026-01-01', 100)], [draw('2026-02-01', 60)], '2026-03-01');
check('backdated purchase: balance', 40, $w['balance']);

// Rounding: quarters stay exact.
$w = M::wallet([lot(1, '2026-01-01', 10.25)], [draw('2026-02-01', 3.5)], '2026-03-01');
check('quarters: balance', 6.75, $w['balance']);

// -----------------------------------------------------------------------------
// One sprint: grant first, wallet beyond; reservations apart from consumption
// -----------------------------------------------------------------------------

$s = M::splitSprint(10, 0, 4, 3, false);
check('running: grant_used', 4, $s['grant_used']);
check('running: grant_reserved', 3, $s['grant_reserved']);
check('running: wallet_consumed', 0, $s['wallet_consumed']);
check('running: wallet_reserved', 0, $s['wallet_reserved']);
check('running: lapsed', 0, $s['lapsed']);

$s = M::splitSprint(10, 0, 12, 5, false);
check('overrun: grant_used', 10, $s['grant_used']);
check('overrun: wallet_consumed', 2, $s['wallet_consumed']);
check('overrun: wallet_reserved', 5, $s['wallet_reserved']);

$s = M::splitSprint(10, 0, 6, 8, false);
check('partial overrun: grant_reserved', 4, $s['grant_reserved']);
check('partial overrun: wallet_reserved', 4, $s['wallet_reserved']);

// Completed with open work: the open items are not delivered and reserve nothing.
$s = M::splitSprint(10, 0, 6, 8, true);
check('completed: reserved', 0, $s['reserved']);
check('completed: claimed', 6, $s['claimed']);
check('completed: lapsed', 4, $s['lapsed']);
check('completed: wallet_reserved', 0, $s['wallet_reserved']);

// A cap without a grant still flags an overrun.
$s = M::splitSprint(0, 5, 2, 4, false);
check('cap without grant: over_cap', true, $s['over_cap']);
check('cap without grant: wallet_reserved', 4, $s['wallet_reserved']);

// The grant that a running sprint has not used yet does not lapse.
$s = M::splitSprint(10, 0, 0, 0, false);
check('running unused: lapsed', 0, $s['lapsed']);
check('running unused: grant_remaining', 10, $s['grant_remaining']);

// -----------------------------------------------------------------------------
// Agreement resolution
// -----------------------------------------------------------------------------

// Rules: 10/15 from January, the retainer ends (0) from July.
$rule = static fn(string $from, float $credits, float $cap = 0.0) => ['date_start' => $from, 'credits' => $credits, 'cap' => $cap];
$customer = [$rule('2026-01-01', 10, 15), $rule('2026-07-01', 0)];
$planned  = ['status' => 'planned', 'date_start' => '2026-03-01'];
$closed   = ['status' => 'completed', 'date_start' => '2026-03-01'];

// A newer rule takes over from its start date and ends the one before it.
$steps = [$rule('', 1000, 1500), $rule('2026-11-01', 2000, 2500), $rule('2027-03-01', 0)];
check('rule: before any dated rule', 1000, M::retainerFor($steps, ['date_start' => '2026-09-01'])['grant']);
check('rule: on the start date', 2000, M::retainerFor($steps, ['date_start' => '2026-11-01'])['grant']);
check('rule: cap follows the rule', 2500, M::retainerFor($steps, ['date_start' => '2026-12-15'])['cap']);
check('rule: zero rule ends it', 0, M::retainerFor($steps, ['date_start' => '2027-03-01'])['grant']);
check('rule: no rules', 0, M::retainerFor([], ['date_start' => '2026-01-01'])['grant']);
check('rule: dated rules only, sprint before the first', 0, M::retainerFor([$rule('2026-05-01', 10)], ['date_start' => '2026-04-30'])['grant']);
check('rule: undated sprint follows today', 2000, M::retainerFor($steps, ['date_start' => ''], '2026-12-01')['grant']);
check('rule: undated sprint, today before the change', 1000, M::retainerFor($steps, ['date_start' => ''], '2026-10-01')['grant']);

$a = M::resolveAgreement($customer, $planned, ['credits' => 20, 'max' => null]);
check('override 20 on 10', 20, $a['grant']);
check('override keeps customer cap', 15, $a['cap']);
check('override source', M::SOURCE_OVERRIDE, $a['source']);

$a = M::resolveAgreement($customer, $planned, ['credits' => 0, 'max' => null]);
check('explicit zero stays zero', 0, $a['grant']);
check('explicit zero source', M::SOURCE_OVERRIDE, $a['source']);

$a = M::resolveAgreement($customer, $planned, ['credits' => null, 'max' => 30]);
check('empty override follows retainer', 10, $a['grant']);
check('partial override cap', 30, $a['cap']);
check('empty override source', M::SOURCE_RETAINER, $a['source']);

$a = M::resolveAgreement($customer, ['status' => 'planned', 'date_start' => '2026-09-01'], null);
check('after the ending rule: no grant', 0, $a['grant']);
check('after the ending rule source', M::SOURCE_NONE, $a['source']);

$a = M::resolveAgreement($customer, ['status' => 'planned', 'date_start' => ''], null, true, '2026-03-15');
check('no start date: rule in force today applies', 10, $a['grant']);

$a = M::resolveAgreement($customer, $closed, null);
check('completed without record: nothing', 0, $a['grant']);
check('completed source', M::SOURCE_CLOSED, $a['source']);

$a = M::resolveAgreement($customer, $closed, ['credits' => 10, 'max' => 15]);
check('frozen snapshot survives retainer change', 10, $a['grant']);

$a = M::resolveAgreement($customer, $planned, ['credits' => 20, 'max' => 25], false);
check('wrong entity: no grant', 0, $a['grant']);
check('wrong entity: no cap', 0, $a['cap']);
check('wrong entity source', M::SOURCE_ENTITY, $a['source']);

// Changing the customer's retainer does not change a frozen sprint.
$raised = [$rule('2026-01-01', 50, 15), $rule('2026-07-01', 0)];
check('raised retainer, frozen sprint', 10, M::resolveAgreement($raised, $closed, ['credits' => 10, 'max' => null])['grant']);
check('raised retainer, open sprint', 50, M::resolveAgreement($raised, $planned, null)['grant']);

// -----------------------------------------------------------------------------
// Forecast: per-sprint sums, no netting between sprints
// -----------------------------------------------------------------------------

$f = M::forecast([['grant' => 10, 'claimed' => 20], ['grant' => 10, 'claimed' => 0]]);
check('forecast: wallet_needed', 10, $f['wallet_needed']);
check('forecast: unused', 10, $f['unused']);
check('forecast: grant', 20, $f['grant']);
check('forecast: claim', 20, $f['claim']);

$f = M::forecast([['grant' => 10, 'claimed' => 10]]);
check('forecast on plan: unused', 0, $f['unused']);
check('forecast on plan: wallet_needed', 0, $f['wallet_needed']);

// -----------------------------------------------------------------------------

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} credit checks failed\n");
    exit(1);
}
echo "Credit math OK ({$checks} checks)\n";
