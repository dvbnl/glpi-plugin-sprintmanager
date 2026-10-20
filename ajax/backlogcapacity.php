<?php

/**
 * Returns the capacity situation of an owner in a specific sprint as JSON,
 * for the backlog edit modal: the shared capacity model (available, planned,
 * remaining) plus what other backlog items proposed for that sprint and this
 * item itself would add, with the same wording, bar and warning threshold as
 * the sprint views.
 */

use GlpiPlugin\Sprint\SprintCapacityMath;
use GlpiPlugin\Sprint\SprintItem;
use GlpiPlugin\Sprint\SprintMember;

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkRight('plugin_sprint_item', READ);

$sprintId = (int)($_GET['sprint_id'] ?? $_POST['sprint_id'] ?? 0);
$userId   = (int)($_GET['users_id'] ?? $_POST['users_id'] ?? 0);
$itemId   = (int)($_GET['item_id'] ?? $_POST['item_id'] ?? 0);
// This item's own estimate as currently typed in the dialog (not saved yet).
$own      = SprintMember::normalizeCapacity($_GET['capacity'] ?? $_POST['capacity'] ?? 0);

// The sprint's members and load are entity-scoped data.
if (!SprintItem::sprintEntityAccessible($sprintId)) {
    echo json_encode(['success' => false]);
    return;
}

$preview = SprintMember::backlogCapacityPreview($sprintId, $userId, $itemId);

if ($preview === null) {
    echo json_encode(['success' => false]);
    return;
}

$out = [
    'success'       => true,
    'is_member'     => $preview['is_member'],
    'total'         => SprintMember::formatCapacity($preview['total']),
    'used'          => SprintMember::formatCapacity($preview['used']),
    'pending_other' => SprintMember::formatCapacity($preview['pending_other']),
    'own'           => SprintMember::formatCapacity($own),
    'available'     => SprintMember::formatCapacity(
        $preview['total'] - $preview['used'] - $preview['pending_other']
    ),
];

if ($preview['is_member'] && $preview['figures'] !== null) {
    $base = $preview['figures'];
    $f    = SprintMember::capacityFigures(
        $base['set'],
        $base['available'],
        $base['regular'],
        $base['fastlane'],
        $base['helping'],
        $base['helping_open'],
        $preview['pending_other'] + $own
    );
    $pct  = static fn(float $v): string => SprintMember::formatCapacity($v) . '%';
    $name = GlpiPlugin\Sprint\SprintCache::userName($userId);
    $sprintName = (string)(GlpiPlugin\Sprint\SprintCache::getObject(GlpiPlugin\Sprint\Sprint::class, $sprintId)?->fields['name'] ?? '');

    $html = "<div class='sprint-cap-figures' style='display:flex;flex-wrap:wrap;gap:2px 8px;margin-bottom:4px;'>"
        . "<span>" . __s('Available', 'sprint') . " <strong>" . $pct($f['available']) . "</strong></span>"
        . "<span>" . __s('Planned', 'sprint') . " <strong>" . $pct($f['planned']) . "</strong></span>"
        . "<span>" . htmlescape(sprintf(
            __('Proposed %1$s (other backlog items %2$s, this item %3$s)', 'sprint'),
            $pct($f['proposed']),
            $pct($preview['pending_other']),
            $pct($own)
        )) . "</span>"
        . "</div>";
    $html .= SprintMember::renderFiguresBar($f, SprintMember::capacityDescription($f, $name, $sprintName));
    $html .= "<div style='margin-top:4px;'>"
        . "<strong" . ($f['over_after_proposals'] > SprintCapacityMath::EPSILON ? " class='sprint-cap-over'" : '') . ">"
        . htmlescape(SprintMember::remainingText($f, true)) . "</strong> "
        . ($f['state_after_proposals'] !== SprintCapacityMath::STATE_OVER
            ? SprintMember::capacityStateBadge($f['state_after_proposals'], $f) : '')
        . "</div>";

    $out['state'] = $f['state_after_proposals'];
    $out['html']  = $html;
}

echo json_encode($out);
