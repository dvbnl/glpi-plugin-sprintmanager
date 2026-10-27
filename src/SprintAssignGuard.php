<?php

/**
 * -------------------------------------------------------------------------
 * SprintManager - Agile/Scrum Sprint Management Plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of SprintManager.
 *
 * SprintManager is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * SprintManager is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with SprintManager. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2024-2026 DVBNL
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/dvbnl/glpi-plugin-sprintmanager
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Sprint;

/**
 * The two checks the Scrum Master answers when backlog items join a sprint,
 * on every assign path (single row, bulk, Sprint-tab planning line):
 *
 *  - items above the configured maximum capacity per item are reviewed one by
 *    one: the Scrum Master approves the planner's reason as it is or lowers
 *    the capacity inline (posted as `cap_capacity[<item id>]`);
 *  - items whose credits push their customer past the credits agreed for the
 *    sprint are confirmed once (posted as `confirm_credits=1`).
 *
 * check() answers null when nothing is left to ask, else the JSON reply that
 * makes the browser open the matching dialog. The same reply shape feeds the
 * server-rendered dialog of the no-JS planning line.
 */
final class SprintAssignGuard
{
    /**
     * Items that need the Scrum Master's capacity decision, and the decisions
     * already posted.
     *
     * @param array<int,array<string,mixed>> $items sprint item rows
     * @param array<string,mixed>            $post  the request
     * @return array{limit:float, review:array<int,array<string,mixed>>, apply:array<int,float>}
     */
    public static function capacityReview(array $items, array $post): array
    {
        $limit = Config::getItemCapacityLimit();
        $out   = ['limit' => $limit, 'review' => [], 'apply' => []];
        if ($limit <= 0) {
            return $out;
        }
        $decisions = (array)($post['cap_capacity'] ?? []);
        foreach ($items as $row) {
            if ((int)($row['is_fastlane'] ?? 0) === 1) {
                continue;
            }
            $capacity = (float)($row['capacity'] ?? 0);
            if ($capacity <= $limit) {
                continue;
            }
            $id = (int)$row['id'];
            if (array_key_exists($id, $decisions) && (string)$decisions[$id] !== '') {
                // Lowering is the only inline change; anything else keeps the
                // planner's value (approved as it is).
                $decided = SprintMember::normalizeCapacity($decisions[$id]);
                $out['apply'][$id] = ($decided > 0 && $decided < $capacity) ? $decided : $capacity;
                continue;
            }
            $ownerId = (int)($row['users_id'] ?? 0);
            $out['review'][] = [
                'id'            => $id,
                'name'          => plugin_sprint_text((string)($row['name'] ?? '')),
                'capacity'      => SprintMember::formatCapacity($capacity),
                'over'          => SprintMember::formatCapacity($capacity - $limit),
                'justification' => plugin_sprint_text((string)($row['capacity_justification'] ?? '')),
                'owner'         => $ownerId > 0 ? SprintCache::userName($ownerId) : '',
            ];
        }
        return $out;
    }

    /**
     * Customers that run past the credits agreed for the sprint once $items
     * join it. Only judged for a session that may see credits.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array{customer:string, agreed:float, planned:float, over:float}> keyed by customer id
     */
    public static function creditOverruns(array $items, int $sprintId): array
    {
        if ($sprintId <= 0 || !Config::isCreditOverrunWarning() || !SprintCustomer::canViewCredits()) {
            return [];
        }
        $extra = [];
        foreach ($items as $row) {
            $customerId = (int)($row['plugin_sprint_sprintcustomers_id'] ?? 0);
            $credits    = (float)($row['credits'] ?? 0);
            if ($customerId <= 0 || $credits <= 0 || (int)($row['is_parked'] ?? 0) === 1) {
                continue;
            }
            // Already counted in the sprint's claim (a re-assign to the same sprint).
            if ((int)($row['plugin_sprint_sprints_id'] ?? 0) === $sprintId) {
                continue;
            }
            $extra[$customerId] = ($extra[$customerId] ?? 0.0) + $credits;
        }
        $out = [];
        foreach ($extra as $customerId => $credits) {
            $agreement = SprintCustomer::agreementFor($customerId, $sprintId);
            if ($agreement === null) {
                continue;
            }
            // The retainer is what the customer has per sprint; a cap alone
            // is a hard ceiling and counts the same way.
            $agreed = (float)$agreement['grant'] > 0 ? (float)$agreement['grant'] : (float)$agreement['cap'];
            if ($agreed <= 0) {
                continue;
            }
            $funding = SprintCustomer::fundingFor($customerId, $sprintId);
            $claimed = $funding ? (float)($funding['claimed'] ?? 0) : 0.0;
            $planned = SprintCreditMath::round2($claimed + $credits);
            if ($planned <= $agreed) {
                continue;
            }
            $out[$customerId] = [
                'customer' => SprintCustomer::getNameFor($customerId),
                'agreed'   => $agreed,
                'planned'  => $planned,
                'over'     => SprintCreditMath::round2($planned - $agreed),
            ];
        }
        return $out;
    }

    /**
     * One sentence per customer over its credits, ready for a confirm dialog.
     *
     * @param array<int,array{customer:string, agreed:float, planned:float, over:float}> $overruns
     */
    public static function creditOverrunMessage(array $overruns, string $sprintName, int $itemCount = 1): string
    {
        $lines = [];
        foreach ($overruns as $o) {
            $lines[] = sprintf(
                $itemCount > 1
                    ? __('%1$s has %2$s credits for %3$s. With these items the planned total comes to %4$s credits (%5$s over).', 'sprint')
                    : __('%1$s has %2$s credits for %3$s. With this item the planned total comes to %4$s credits (%5$s over).', 'sprint'),
                plugin_sprint_text($o['customer']),
                SprintCustomer::formatCredits($o['agreed']),
                plugin_sprint_text($sprintName),
                SprintCustomer::formatCredits($o['planned']),
                SprintCustomer::formatCredits($o['over'])
            );
        }
        $lines[] = __('Assign anyway?', 'sprint');
        return implode("\n", $lines);
    }

    /**
     * The question the Scrum Master still has to answer before $items may
     * join the sprint, or null when every check passed. Capacity review comes
     * first (it may lower what is planned), the credit confirmation after.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed>            $post
     * @return array<string,mixed>|null a JSON reply (success=false) or null
     */
    public static function check(array $items, int $sprintId, string $sprintName, array $post): ?array
    {
        $capacity = self::capacityReview($items, $post);
        if ($capacity['review']) {
            return [
                'success'         => false,
                'needs_cap_review' => true,
                'limit'           => SprintMember::formatCapacity($capacity['limit']),
                'items'           => array_values($capacity['review']),
                'message'         => sprintf(
                    __('%s%% is the maximum capacity per item. Approve the reason or lower the capacity.', 'sprint'),
                    SprintMember::formatCapacity($capacity['limit'])
                ),
            ];
        }
        if ((int)($post['confirm_credits'] ?? 0) !== 1) {
            $overruns = self::creditOverruns($items, $sprintId);
            if ($overruns) {
                return [
                    'success'              => false,
                    'needs_credit_confirm' => true,
                    'message'              => self::creditOverrunMessage($overruns, $sprintName, count($items)),
                ];
            }
        }
        return null;
    }

    /**
     * The capacity the Scrum Master decided on for one item, to merge into
     * its update, or [] when the item was not under review.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function capacityUpdate(array $row, array $post): array
    {
        $decision = self::capacityReview([$row], $post)['apply'][(int)$row['id']] ?? null;
        if ($decision === null || $decision >= (float)($row['capacity'] ?? 0)) {
            return [];
        }
        return ['capacity' => $decision, '_capacity_reviewed' => 1];
    }
}
