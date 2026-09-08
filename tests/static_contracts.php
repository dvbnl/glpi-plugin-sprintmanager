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
foreach ([
    'sprintepics', 'sprintavailabilities', 'sprintimprovements', 'sprintsignals',
    'sprintcustomers', 'sprintcredits', 'sprintcustomercredits', 'sprintretainers', 'sprintcreditproducts',
] as $table) {
    if (!str_contains($hook, 'glpi_plugin_sprint_' . $table)) {
        fwrite(STDERR, "Missing install migration for {$table}\n");
        exit(1);
    }
}
// Customers & credits: the money rules live in one pure class and the
// callers go through it; the item side refuses an invalid customer rather
// than silently re-attributing; bookings carry their own CREATE/PURGE rights.
$creditMath = file_get_contents($root . '/src/SprintCreditMath.php');
foreach (['resolveAgreement', 'splitSprint', 'wallet', 'forecast', 'retainerFor'] as $method) {
    if (!str_contains($creditMath, 'function ' . $method)) {
        fwrite(STDERR, "Missing credit math method: {$method}\n");
        exit(1);
    }
}
if (preg_match('/\b(global \$DB|Session::|new Sprint\b|new SprintItem\b)/', $creditMath)) {
    fwrite(STDERR, "SprintCreditMath must stay free of GLPI and database dependencies\n");
    exit(1);
}
$product = file_get_contents($root . '/src/SprintCreditProduct.php');
foreach (['function dropdownOptions', 'function creditsFor', 'function isVisible'] as $method) {
    if (!str_contains($product, $method)) {
        fwrite(STDERR, "Missing credit product method: {$method}\n");
        exit(1);
    }
}
foreach (['src/SprintItem.php' => 'sprint-credit-product', 'src/Backlog.php' => 'SprintItem::productSelect(',
    'templates/sprintmeeting.form.html.twig' => 'sprint-credit-product'] as $surface => $needle) {
    if (!str_contains(file_get_contents($root . '/' . $surface), $needle)) {
        fwrite(STDERR, "{$surface} must offer the credit product picker\n");
        exit(1);
    }
}
$retainer = file_get_contents($root . '/src/SprintRetainer.php');
foreach (['function rulesFor', 'function rawRulesFor', 'function renderEditor', 'function applyPostedRules'] as $method) {
    if (!str_contains($retainer, $method)) {
        fwrite(STDERR, "Missing retainer rule method: {$method}\n");
        exit(1);
    }
}
if (str_contains($hook, "'credits_per_sprint',\n            \"DECIMAL")) {
    fwrite(STDERR, "The retainer columns must not be re-added to the customer table\n");
    exit(1);
}
$customer = file_get_contents($root . '/src/SprintCustomer.php');
if (str_contains($customer, "name='credits_per_sprint'") || !str_contains($customer, 'SprintRetainer::renderEditor')) {
    fwrite(STDERR, "The customer form must edit retainer rules through SprintRetainer\n");
    exit(1);
}
foreach (['SprintCreditMath::resolveAgreement', 'SprintCreditMath::splitSprint', 'SprintCreditMath::wallet',
    'function customerFitsEntity', 'function isChargeableInSprint', 'function agreementFor',
    'function standingAgreementFor', 'function invalidateCaches'] as $needle) {
    if (!str_contains($customer, $needle)) {
        fwrite(STDERR, "SprintCustomer must route credits through SprintCreditMath: missing {$needle}\n");
        exit(1);
    }
}
if (!str_contains($customer, "return self::canViewCredits() ? self::loadOverrides() : [];")
    || !str_contains($customer, "if (!self::canViewCredits()) {\n            return self::\$fundingCache;")) {
    fwrite(STDERR, "Credit aggregates must refuse without the credits right\n");
    exit(1);
}
$backlog = file_get_contents($root . '/src/Backlog.php');
if (!str_contains($backlog, 'SprintCustomer::agreementFor($cid, $sid)')
    || !str_contains($backlog, 'SprintCustomer::standingAgreementFor($cid, $sid)')
    || !str_contains($backlog, 'sprint-be-customer-legacy')) {
    fwrite(STDERR, "The credits matrix must use the effective per-sprint agreement and keep inactive customers in quick-edit\n");
    exit(1);
}
$credits = file_get_contents($root . '/src/SprintCredits.php');
if (!str_contains($credits, 'SprintCreditMath::forecast($cells)')
    || str_contains($credits, 'function renderForecast(array $window')) {
    fwrite(STDERR, "The forecast must sum per sprint through SprintCreditMath::forecast()\n");
    exit(1);
}
$credit = file_get_contents($root . '/src/SprintCredit.php');
if (!str_contains($credit, "Session::haveRight(self::\$rightname, CREATE)")
    || !str_contains($credit, "Session::haveRight(self::\$rightname, PURGE)")
    || !str_contains($credit, 'function pre_deleteItem')) {
    fwrite(STDERR, "Credit bookings must carry their own CREATE and PURGE rights\n");
    exit(1);
}
$creditForm = file_get_contents($root . '/front/sprintcredit.form.php');
foreach (['SprintCredit::canCreate()', 'SprintCredit::canPurge()', 'SprintCredit::canUpdate()'] as $needle) {
    if (!str_contains($creditForm, $needle)) {
        fwrite(STDERR, "The credit booking handler must check {$needle}\n");
        exit(1);
    }
}
$sprintItems = file_get_contents($root . '/src/SprintItem.php');
if (!str_contains($sprintItems, 'function validateCustomer')
    || !str_contains($sprintItems, 'sprint-qe-customer-legacy')
    || str_contains($sprintItems, '$customerId = 0;' . "\n            }\n            \$input['plugin_sprint_sprintcustomers_id'] = \$customerId;")) {
    fwrite(STDERR, "Sprint items must refuse an invalid customer and keep inactive ones in quick-edit\n");
    exit(1);
}
$quick = file_get_contents($root . '/ajax/updateitemquick.php');
if (!str_contains($quick, "unset(\$update['plugin_sprint_sprintcustomers_id']);")) {
    fwrite(STDERR, "An empty customer field in quick-edit must be ignored, not treated as detach\n");
    exit(1);
}
foreach (['templates/sprintmeeting.form.html.twig', 'templates/meeting/items_tables.html.twig'] as $tpl) {
    $twig = file_get_contents($root . '/' . $tpl);
    if (!str_contains($twig, 'data-customer-name') && !str_contains($twig, 'sprint-qe-customer-legacy')) {
        fwrite(STDERR, "{$tpl} must carry the customer name for quick-edit\n");
        exit(1);
    }
}

$setup = file_get_contents($root . '/setup.php');
if (!str_contains($setup, "PLUGIN_SPRINT_VERSION', '1.3.0")) {
    fwrite(STDERR, "Version was not advanced to 1.3.0\n");
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
$css = file_get_contents($root . '/css/sprint.css');
if (!str_contains($overview, 'align-items-center sprint-overview-tile')
    || !str_contains($css, '.sprint-overview-tile > .avatar { flex: 0 0 auto; }')
    || !str_contains($css, '.sprint-overview-tile-content { flex: 1 1 0; min-width: 0; }')) {
    fwrite(STDERR, "Overview tiles must reserve the avatar width and give remaining space to their labels\n");
    exit(1);
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
if (!str_contains($backlog, 'sprint-backlog-blocked-hint')
    || !str_contains($css, '.sprint-backlog-blocked-header > .sprint-backlog-blocked-hint')) {
    fwrite(STDERR, "Blocked backlog hint must retain its readable intrinsic width\n");
    exit(1);
}
// GLPI 10 forces `width: 1%` on `.small`; only `.sprint-small` is allowed (#3).
if (!str_contains($css, '.sprint-small {')) {
    fwrite(STDERR, "css/sprint.css must define .sprint-small (GLPI 10 forces width:1% on .small)\n");
    exit(1);
}
$markup = [];
foreach (['src', 'ajax', 'front'] as $dir) {
    $markup = array_merge($markup, glob($root . '/' . $dir . '/*.php'));
}
$markup = array_merge($markup, glob($root . '/templates/*.twig'), glob($root . '/templates/*/*.twig'));
foreach ($markup as $file) {
    $body = (string)file_get_contents($file);
    preg_match_all('/(?:class\s*=\s*|className\s*=\s*)([\'"])(.*?)\1/s', $body, $attrs, PREG_SET_ORDER);
    foreach ($attrs as $attr) {
        if (preg_match('/(?<![\w-])small(?![\w-])/', $attr[2])) {
            $rel = substr($file, strlen($root) + 1);
            fwrite(STDERR, "{$rel}: use `sprint-small`, not Bootstrap's `small` class — "
                . "GLPI 10 forces width:1% on it (issue #3): {$attr[2]}\n");
            exit(1);
        }
    }
}
foreach (['js/sprint.js' => 'public/sprint.js', 'css/sprint.css' => 'public/sprint.css'] as $source => $copy) {
    if (md5_file($root . '/' . $source) !== md5_file($root . '/' . $copy)) {
        fwrite(STDERR, "{$copy} is out of sync with {$source}\n");
        exit(1);
    }
}
echo "Static contracts OK\n";
