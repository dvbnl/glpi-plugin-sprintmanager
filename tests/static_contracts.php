<?php

$root = dirname(__DIR__);
$required = [
    'src/SprintAgility.php',
    'front/sprintagility.form.php',
    'src/SprintBoard.php',
    'src/SprintItem.php',
    'hook.php',
];
foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) {
        fwrite(STDERR, "Missing required file: {$file}\n");
        exit(1);
    }
}
$hook = file_get_contents($root . '/hook.php');
foreach (['sprintepics', 'sprintavailabilities', 'sprintimprovements', 'sprintsignals'] as $table) {
    if (!str_contains($hook, 'glpi_plugin_sprint_' . $table)) {
        fwrite(STDERR, "Missing install migration for {$table}\n");
        exit(1);
    }
}
$setup = file_get_contents($root . '/setup.php');
if (!str_contains($setup, "PLUGIN_SPRINT_VERSION', '1.2.1")) {
    fwrite(STDERR, "Version was not advanced to 1.2.1\n");
    exit(1);
}
$endpoint = file_get_contents($root . '/front/sprintagility.form.php');
if (!str_contains($endpoint, "REQUEST_METHOD'] !== 'POST'") || !str_contains($endpoint, 'Session::checkCSRF')) {
    fwrite(STDERR, "Agility mutations must be POST + CSRF protected\n");
    exit(1);
}
$agility = file_get_contents($root . '/src/SprintAgility.php');
foreach (['validateTransition', 'effectiveCapacity', 'syncLinkedStatus', 'cronSprintSignals', 'carryImprovementsToSprint'] as $method) {
    if (!str_contains($agility, 'function ' . $method)) {
        fwrite(STDERR, "Missing agility service method: {$method}\n");
        exit(1);
    }
}
if (!str_contains($agility, "'plugin_sprint_sprints_id' => \$sprintId, 'users_id'")
    || !str_contains($agility, "if (\$description === '') return false")
    || !str_contains($agility, "if (\$name === '') return false")) {
    fwrite(STDERR, "Agility validation or sprint-scoped signals are missing\n");
    exit(1);
}
$items = file_get_contents($root . '/src/SprintItem.php');
if (!str_contains($items, "\$candidate->fields['status'] = self::STATUS_TODO")) {
    fwrite(STDERR, "New sprint items must pass transition policy checks\n");
    exit(1);
}
$board = file_get_contents($root . '/src/SprintBoard.php');
if (!str_contains($board, 'dataset.boardOrder')) {
    fwrite(STDERR, "Board order must be restored after disabling swimlanes\n");
    exit(1);
}
$dashboard = file_get_contents($root . '/src/SprintDashboard.php');
if (str_contains($dashboard, 'max-width:{$width}px')
    || substr_count($dashboard, "class='sprint-responsive-chart'") < 3) {
    fwrite(STDERR, "Dashboard SVG charts must remain responsive\n");
    exit(1);
}
$overview = file_get_contents($root . '/src/SprintOverview.php');
foreach (['renderLiveSprints', 'renderFilterBar', 'renderScopeStability', 'renderFlowHealth', 'renderCapacityDelivery', 'renderDependencyHealth', 'renderComparison'] as $method) {
    if (!str_contains($overview, 'function ' . $method)) {
        fwrite(STDERR, "Missing overview enhancement: {$method}\n");
        exit(1);
    }
}
foreach (['blocked_only', 'over_only', 'predictability_below', 'compare_period'] as $filter) {
    if (!str_contains($overview, "'{$filter}'")) {
        fwrite(STDERR, "Missing overview filter: {$filter}\n");
        exit(1);
    }
}
// Retro input has no owner picker: the contributor must become the owner,
// while unassigned actions stay unowned for the readiness check.
if (!str_contains($agility, "if (\$owner <= 0 && \$category !== 'action') \$owner = (int)Session::getLoginUserID();")) {
    fwrite(STDERR, "Retro input must default its owner to the contributor\n");
    exit(1);
}
$rail = file_get_contents($root . '/templates/meeting/rail.html.twig');
if (!str_contains($rail, 'data-current-user=')
    || !str_contains(file_get_contents($root . '/src/SprintMeeting.php'), "'current_user_name'")
    || !str_contains(file_get_contents($root . '/js/sprint.js'), "root.getAttribute('data-current-user')")) {
    fwrite(STDERR, "Freshly added retro input must render its contributor as owner\n");
    exit(1);
}
// The deps dialog stacks on the backlog edit modal instead of replacing it.
$backlog = file_get_contents($root . '/src/Backlog.php');
if (!str_contains($backlog, 'setFocusTrap') || !str_contains($backlog, "addClass('modal-open')")) {
    fwrite(STDERR, "Dependency modal must stack on top of the backlog edit modal\n");
    exit(1);
}
foreach (['js/sprint.js' => 'public/sprint.js', 'css/sprint.css' => 'public/sprint.css'] as $source => $copy) {
    if (md5_file($root . '/' . $source) !== md5_file($root . '/' . $copy)) {
        fwrite(STDERR, "{$copy} is out of sync with {$source}\n");
        exit(1);
    }
}
echo "Static contracts OK\n";
