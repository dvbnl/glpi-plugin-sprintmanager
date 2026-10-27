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
// The meeting view uses the shared quick-edit dialog (and its picker).
foreach (['src/SprintItem.php' => 'sprint-credit-product', 'src/Backlog.php' => 'SprintItem::productSelect(',
    'src/SprintMeeting.php' => 'SprintItem::renderQuickEditUI('] as $surface => $needle) {
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
// The meeting rows feed the shared quick-edit dialog (SprintItem).
foreach (['templates/meeting/items_tables.html.twig'] as $tpl) {
    $twig = file_get_contents($root . '/' . $tpl);
    if (!str_contains($twig, 'data-customer-name') && !str_contains($twig, 'sprint-qe-customer-legacy')) {
        fwrite(STDERR, "{$tpl} must carry the customer name for quick-edit\n");
        exit(1);
    }
}

$setup = file_get_contents($root . '/setup.php');
// 1.4.0: dependency credits, the credit flow card, required tags and the
// catalogue drag order all need their schema / surfaces in place.
$hook = file_get_contents($root . '/hook.php');
foreach (["'credits'", "'sort_order'"] as $needle) {
    if (!str_contains($hook, $needle)) {
        fwrite(STDERR, "Missing 1.4.0 migration column {$needle}\n");
        exit(1);
    }
}
foreach ([
    'src/SprintItemDependency.php' => 'function creditRows',
    'src/SprintCreditFlow.php'     => 'function collect',
    'src/SprintCredits.php'        => 'SprintCreditFlow::render(',
    'src/Config.php'               => 'function isTagRequired',
    'src/SprintItem.php'           => 'validateRequiredTags(',
    'src/SprintCreditProduct.php'  => 'sprint-catalogue-grip',
    'ajax/catalogreorder.php'      => 'sort_order',
] as $file => $needle) {
    if (!str_contains((string)file_get_contents($root . '/' . $file), $needle)) {
        fwrite(STDERR, "{$file} must contain {$needle}\n");
        exit(1);
    }
}

if (!str_contains($setup, "PLUGIN_SPRINT_VERSION', '1.3.2")) {
    fwrite(STDERR, "Version was not advanced to 1.3.2\n");
    exit(1);
}
$endpoint = file_get_contents($root . '/front/sprintagility.form.php');
// CSRF itself is validated by GLPI before the handler runs (see below).
if (!str_contains($endpoint, "REQUEST_METHOD'] !== 'POST'")) {
    fwrite(STDERR, "Agility mutations must be POST only\n");
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
// Security contracts (1.3.1 review): search-engine entity scope, export
// entity check, CSV formula neutralization, no double CSRF in front/.
if (!str_contains($hook, 'function plugin_sprint_addDefaultWhere')) {
    fwrite(STDERR, "Missing plugin_sprint_addDefaultWhere search scope hook\n");
    exit(1);
}
if (!str_contains(file_get_contents($root . '/front/sprint.export.php'), '$sprint->check($sprintId, READ)')) {
    fwrite(STDERR, "front/sprint.export.php must check() the sprint, not only getFromDB()\n");
    exit(1);
}
if (!str_contains(file_get_contents($root . '/ajax/getsprintmembers.php'), 'sprintEntityAccessible')) {
    fwrite(STDERR, "ajax/getsprintmembers.php must check the sprint entity\n");
    exit(1);
}
foreach (glob($root . '/front/*.php') as $file) {
    if (preg_match('/^\s*Session::checkCSRF\(/m', file_get_contents($file))) {
        $rel = substr($file, strlen($root) + 1);
        fwrite(STDERR, "{$rel}: inc/includes.php (GLPI 10) and the kernel (GLPI 11) already "
            . "validate and spend the CSRF token for front/ POSTs\n");
        exit(1);
    }
}
// Board (spec 02/03/11): counters, WIP and warnings are re-derived after
// moves/reverts/filters; a menu offers the same move without drag & drop.
$board = file_get_contents($root . '/src/SprintBoard.php');
foreach (["data-wip-limit", "col.classList.toggle('sk-over-wip'", 'function revertMove', 'afterLayoutChange();', 'sk-move-to', 'function setSaving', 'sk-column-picker', 'MutationObserver'] as $needle) {
    if (!str_contains($board, $needle)) {
        fwrite(STDERR, "Board must keep live counters, WIP, status menu and saving state: missing {$needle}\n");
        exit(1);
    }
}
// Capacity (spec 04/13): low availability is neutral, only overload warns;
// one summary speaks Available / Planned / Remaining.
$memberSrc = file_get_contents($root . '/src/SprintMember.php');
if (str_contains($memberSrc, "\$pct >= 50 ? '#ffc107' : '#dc3545'")
    || !str_contains($memberSrc, 'function capacityFigures')
    || !str_contains($memberSrc, "'%s%% over capacity'")
    || !str_contains($memberSrc, "'dependency_open'")) {
    fwrite(STDERR, "Member capacity must be neutral for low availability and explained as available/planned/remaining\n");
    exit(1);
}
// Retro follow-up (spec 19): actions keep one identity — carry-over no
// longer inserts copies, legacy copies are linked once by the migration,
// follow-up notes have their own table (installed and dropped), and status
// changes on earlier sprints' actions go through the entity/right-checked path.
$agility = file_get_contents($root . '/src/SprintAgility.php');
$carryBody = substr($agility, strpos($agility, 'function carryImprovementsToSprint'), 600);
if (str_contains($carryBody, '$DB->insert')
    || !str_contains($agility, 'function getRetroFollowUp')
    || !str_contains($agility, 'function linkLegacyCarryCopies')
    || !str_contains($agility, 'return self::handleActionFollowUp($action, $input, $sprintId);')
    || !str_contains($agility, "if (!\$ownSprint && !SprintItem::sprintEntityAccessible((int)\$row['plugin_sprint_sprints_id'])) return false;")
    || substr_count($hook, 'glpi_plugin_sprint_sprintimprovementfollowups') < 2
    || !str_contains($hook, 'SprintAgility::linkLegacyCarryCopies()')
    || !str_contains($hook, "'origin_improvements_id'")) {
    fwrite(STDERR, "Retrospective actions must keep one identity with per-retro follow-up notes\n");
    exit(1);
}
if (!str_contains(file_get_contents($root . '/src/SprintMeeting.php'), "SprintAgility::getRetroFollowUp(\$this->fields)")
    || !str_contains(file_get_contents($root . '/templates/meeting/rail.html.twig'), "@sprint/meeting/followup.html.twig")) {
    fwrite(STDERR, "The retro follow-up phase must render the series follow-up, not date-filtered copies\n");
    exit(1);
}
// Phase-notes autosave (spec 01): status per note, serialized saves with
// sequence numbers, flush before phase navigation, leave warning.
$js = file_get_contents($root . '/js/sprint.js');
if (!str_contains(file_get_contents($root . '/ajax/meetingphase.php'), "'seq'                 => (int)(\$_POST['seq'] ?? 0)")
    || !str_contains($js, "navPost(root, 'set_phase', { phase: target })")
    || !str_contains($js, "navPost(root, 'end_meeting')")
    || !str_contains($js, "(parseInt(resp.seq, 10) || 0) === seq")
    || !str_contains($js, "window.addEventListener('beforeunload'")
    || !str_contains(file_get_contents($root . '/templates/meeting/rail.html.twig'), 'sprint-phase-note-status')) {
    fwrite(STDERR, "Meeting notes autosave must show its status, ignore stale responses and flush on navigation\n");
    exit(1);
}
// Backlog UX: proposal vs assignment wording, no browser dialogs, the
// per-user Work queue / Planning view must be accepted by ajax/userpref.php.
$backlog = file_get_contents($root . '/src/Backlog.php');
if (preg_match('/\b(alert|confirm|prompt)\(/', preg_replace('#//[^\n]*|\*[^\n]*#', '', $backlog))) {
    fwrite(STDERR, "src/Backlog.php must use sprintBacklogNotify / inline dialogs, not alert()/confirm()/prompt()\n");
    exit(1);
}
if (str_contains($backlog, "__s('Assign to sprint', 'sprint')") || !str_contains($backlog, "'Planning complete'")
    || !str_contains($backlog, 'function planningGaps')) {
    fwrite(STDERR, "Backlog must label the sprint field \"Proposed sprint\" and show \"Planning complete\" / what is missing\n");
    exit(1);
}
if (!str_contains(file_get_contents($root . '/ajax/userpref.php'), 'Backlog::VIEW_PREF')) {
    fwrite(STDERR, "ajax/userpref.php must whitelist the backlog view preference\n");
    exit(1);
}
// credits-admin (UX 14/17): the balance explanation and the attention
// signal are pure; the credits page filter only accepts visible customers;
// every CSV line still goes through csvRow() (formula neutralization).
require_once $root . '/src/SprintCreditMath.php';
$steps = GlpiPlugin\Sprint\SprintCreditMath::walletSteps([
    'purchased' => 40, 'corrections' => 2, 'overflow' => 10, 'purchase_lapsed' => 3,
    'wallet_balance' => 25, 'wallet_reserved' => 5,
]);
if ($steps['left'] != 25.0 || $steps['free'] != 20.0 || !$steps['consistent'] || count($steps['steps']) !== 4) {
    fwrite(STDERR, "SprintCreditMath::walletSteps() must explain left = bought - corrections - drawn - lapsed\n");
    exit(1);
}
$attn = static fn(array $b, bool $cap = false, float $lapse = 0.0) => GlpiPlugin\Sprint\SprintCreditMath::attention($b, $cap, $lapse);
if ($attn(['wallet_balance' => -1, 'available' => -3]) !== 'overdrawn'
    || $attn(['wallet_balance' => 5, 'available' => -1]) !== 'short'
    || $attn(['wallet_balance' => 5, 'available' => 4, 'alert' => 10]) !== 'low'
    || $attn(['wallet_balance' => 50, 'available' => 50], true) !== 'over_cap'
    || $attn(['wallet_balance' => 50, 'available' => 50], false, 2.0) !== 'lapse'
    || $attn(['wallet_balance' => 50, 'available' => 50]) !== '') {
    fwrite(STDERR, "SprintCreditMath::attention() ranks overdrawn > short > low > over_cap > lapse\n");
    exit(1);
}
$creditsPage = file_get_contents($root . '/src/SprintCredits.php');
if (!str_contains($creditsPage, "if (\$cid > 0 && !isset(\$balances[\$cid])) {")
    || !str_contains($creditsPage, 'SprintCustomer::visibleIds()')) {
    fwrite(STDERR, "The credits page filter must only accept customers the session may see\n");
    exit(1);
}
$exportSrc = file_get_contents($root . '/src/SprintExport.php');
if (substr_count($exportSrc, 'fputcsv(') !== 1 || !str_contains($exportSrc, "array_map([self::class, 'csvCell'], \$fields)")) {
    fwrite(STDERR, "Every CSV line must go through SprintExport::csvRow() and csvCell()\n");
    exit(1);
}
// dashboard-nav (UX 03/05/07/10/15/16): real collapsible buttons, named
// progress units, stable overview section ids stored per user, aria-current
// side nav, shared meeting-date planner for apply + preview.
$dash = file_get_contents($root . '/src/SprintDashboard.php');
foreach (["<button type='button' class='sprint-collapsible-header'", 'aria-controls', "aria-sort='none'",
    'Progress weighted by capacity', 'Story points delivered', 'no_capacity_items', 'dashboard_view'] as $needle) {
    if (!str_contains($dash . file_get_contents($root . '/js/sprint.js'), $needle)) {
        fwrite(STDERR, "Dashboard contract missing: {$needle}\n");
        exit(1);
    }
}
if (str_contains($dash, "echo \"<div class='sprint-collapsible-header'>\"")) {
    fwrite(STDERR, "Dashboard collapsible headers must be <button>s\n");
    exit(1);
}
$overview = file_get_contents($root . '/src/SprintOverview.php');
if (!str_contains($overview, 'private static function renderCard(string $id,') || str_contains($overview, "'sprint-overview-card-'+i")) {
    fwrite(STDERR, "Overview sections must use stable ids, not the card index\n");
    exit(1);
}
foreach (["aria-current='page'", 'sprint-nav-toggle', 'UserPref::OVERVIEW_COLLAPSED', 'sprint-filter-chip', 'renderEmptyState'] as $needle) {
    if (!str_contains($overview, $needle)) {
        fwrite(STDERR, "Overview contract missing: {$needle}\n");
        exit(1);
    }
}
require_once $root . '/src/UserPref.php';
foreach ([
    ['velocity,flow', 'velocity,flow'], ['flow,flow', 'flow'], ['', ''], [' scope , flow ', 'scope,flow'],
    ['Velocity', null], ['a;b', null], [str_repeat('a,', 200) . 'b', 'a,b'],
] as [$in, $want]) {
    if (\GlpiPlugin\Sprint\UserPref::normalizeSectionList($in) !== $want) {
        fwrite(STDERR, "UserPref::normalizeSectionList(" . var_export($in, true) . ") must be " . var_export($want, true) . "\n");
        exit(1);
    }
}
if (!str_contains(file_get_contents($root . '/ajax/userpref.php'), 'normalizeSectionList')) {
    fwrite(STDERR, "ajax/userpref.php must validate the overview section list\n");
    exit(1);
}
$tmplMeeting = file_get_contents($root . '/src/SprintTemplateMeeting.php');
if (substr_count($tmplMeeting, 'self::planMeetingDates(') < 2) {
    fwrite(STDERR, "Template meeting apply and preview must share planMeetingDates()\n");
    exit(1);
}
// UX 08/09/15: one quick-edit dialog everywhere, requests reported as
// requests, filter bars with chips/result count.
if (str_contains(file_get_contents($root . '/templates/sprintmeeting.form.html.twig'), 'id="sprint-quickedit-modal"')) {
    fwrite(STDERR, "The meeting view must use SprintItem::renderQuickEditUI(), not its own quick-edit modal\n");
    exit(1);
}
foreach (['modal-dialog-scrollable', "data-section='planning'", "data-section='deps'", 'qe-allow-close',
    'function renderUiHelpersScript', 'sf-chips', 'function filterBarTextAttrs'] as $needle) {
    if (!str_contains($sprintItems = file_get_contents($root . '/src/SprintItem.php'), $needle)) {
        fwrite(STDERR, "SprintItem quick-edit/filter UI is missing: {$needle}\n");
        exit(1);
    }
}
if (!str_contains($quick = file_get_contents($root . '/ajax/updateitemquick.php'), "'requests'")
    || !str_contains($quick, '_adhoc_request_reason')
    || !str_contains(file_get_contents($root . '/src/SprintRequest.php'), 'function describePendingForItem')) {
    fwrite(STDERR, "Quick-edit saves must report approval requests (current → requested)\n");
    exit(1);
}
if (!str_contains(file_get_contents($root . '/js/sprint.js'), 'window.sprintFilterAfterApply(bar, rows)')) {
    fwrite(STDERR, "applyFilter() must feed the filter chips / result count\n");
    exit(1);
}
// UX 18: UI text uses no semicolons (split the sentence or use a comma,
// dash or colon). Checks every msgid of the template and every Dutch
// translation, HTML entities such as &nbsp; excepted.
$poStrings = static function (string $file, string $keyword): array {
    $out = [];
    $cur = null;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^(msgid|msgid_plural|msgstr(?:\[\d+\])?|msgctxt) "(.*)"$/', $line, $m)) {
            if ($cur !== null) { $out[] = $cur; }
            $cur = (str_starts_with($m[1], $keyword)) ? stripcslashes($m[2]) : null;
        } elseif ($cur !== null && preg_match('/^"(.*)"$/', $line, $m)) {
            $cur .= stripcslashes($m[1]);
        } elseif (!str_starts_with($line, '"')) {
            if ($cur !== null) { $out[] = $cur; }
            $cur = null;
        }
    }
    if ($cur !== null) { $out[] = $cur; }
    return array_values(array_filter($out, static fn($s) => $s !== '' && !str_contains($s, 'Plural-Forms:')));
};
foreach ([['/locales/sprint.pot', 'msgid'], ['/locales/nl_NL.po', 'msgstr']] as [$poFile, $kw]) {
    foreach ($poStrings($root . $poFile, $kw) as $s) {
        if (str_contains(preg_replace('/&#?[a-zA-Z0-9]+;/', '', $s), ';')) {
            fwrite(STDERR, "No semicolons in UI text ({$poFile} {$kw}): {$s}\n");
            exit(1);
        }
    }
}
// UX 18: runtime messages of js/sprint.js are translated, no browser dialogs.
$sprintJs = file_get_contents($root . '/js/sprint.js');
if (!str_contains($sprintJs, 'window.sprintT = function') || !str_contains(file_get_contents($root . '/src/SprintItem.php'), 'SprintI18n::render()')) {
    fwrite(STDERR, "js/sprint.js runtime messages must come from window.sprintI18n (SprintI18n::render())\n");
    exit(1);
}
if (preg_match('/(?<![\w.])(alert|prompt)\(|window\.confirm\(/', $sprintJs)) {
    fwrite(STDERR, "js/sprint.js must not use alert()/confirm()/prompt() — use sprintNotify()/sprintConfirm()\n");
    exit(1);
}
if (preg_match("/'(Network error|Assign failed)'\)/", $sprintJs) && !str_contains($sprintJs, "sprintT('networkError'")) {
    fwrite(STDERR, "js/sprint.js has untranslated runtime messages\n");
    exit(1);
}
// capacity-unify (UX 13): one pure capacity model for every view.
require_once $root . '/src/SprintCapacityMath.php';
$capFail = static function (string $msg): void {
    fwrite(STDERR, "SprintCapacityMath: {$msg}\n");
    exit(1);
};
$cm = GlpiPlugin\Sprint\SprintCapacityMath::class;
// Worked example of the spec: 80% available, 40 regular, 10 fastlane, 15 helping.
$f = $cm::figures(100, 80, 40, 10, 15, 5);
if ($f['planned'] != 65.0 || $f['remaining'] != 15.0 || $f['over'] != 0.0 || $f['leave'] != 20.0
    || $f['helping_resolved'] != 10.0 || $f['state'] !== $cm::STATE_NORMAL) {
    $capFail('80% available, 40+10+15 planned must give planned 65, remaining 15, normal');
}
// Exactly full is its own state, distinct from over capacity.
$f = $cm::figures(50, 50, 30, 10, 10);
if ($f['remaining'] != 0.0 || $f['over'] != 0.0 || $f['state'] !== $cm::STATE_FULL) {
    $capFail('planned == available must be "full", not over');
}
// Over capacity: shown as a difference in points, never clipped away.
$f = $cm::figures(50, 50, 45, 10, 5);
if ($f['over'] != 10.0 || $f['remaining'] != 0.0 || $f['balance'] != -10.0 || $f['state'] !== $cm::STATE_OVER) {
    $capFail('60 planned on 50 available must be 10 over capacity');
}
// 0% availability: neutral when nothing is planned, over when something is.
$f = $cm::figures(50, 0, 0, 0, 0);
if ($f['state'] !== $cm::STATE_NONE || $f['leave'] != 50.0 || $f['remaining'] != 0.0) {
    $capFail('0% available with nothing planned must be neutral ("none")');
}
if ($cm::figures(50, 0, 5, 0, 0)['state'] !== $cm::STATE_OVER || $cm::figures(50, 0, 5, 0, 0)['over'] != 5.0) {
    $capFail('0% available with 5% planned must be 5 over capacity');
}
// Low availability alone is not a signal; almost full uses the one threshold.
if ($cm::figures(100, 5, 0, 0, 0)['state'] !== $cm::STATE_NORMAL
    || $cm::figures(100, 80, 71, 0, 0)['state'] !== $cm::STATE_ALMOST_FULL
    || $cm::figures(100, 80, 80 - $cm::ALMOST_FULL_PP, 0, 0)['state'] !== $cm::STATE_NORMAL) {
    $capFail('almost full = planned work and less than ALMOST_FULL_PP points remaining');
}
// Proposals stay apart: they never change planned/remaining, only the "after proposals" figures.
$f = $cm::figures(100, 80, 40, 10, 15, 0, 20);
if ($f['planned'] != 65.0 || $f['remaining'] != 15.0 || $f['state'] !== $cm::STATE_NORMAL
    || $f['over_after_proposals'] != 5.0 || $f['state_after_proposals'] !== $cm::STATE_OVER) {
    $capFail('proposals must be reported separately ("remaining after proposals")');
}
// Bar geometry: segments add up to the load, the overflow overlay never adds width.
foreach ([[100, 80, 40, 10, 15, 0], [50, 50, 45, 10, 5, 0], [50, 0, 5, 0, 0, 0], [100, 80, 40, 10, 15, 20], [50, 50, 30, 10, 10, 0]] as $case) {
    [$set, $av, $r, $fl, $h, $pr] = $case;
    $f = $cm::figures($set, $av, $r, $fl, $h, 0, $pr);
    $g = $cm::bar($f);
    $segSum = array_sum($g['segments']);
    $load   = $f['planned'] + $f['proposed'];
    if ($segSum > 100.05 || abs($segSum - 100 * $load / max($g['scale'], 1e-9)) > 0.05) {
        $capFail('bar segments must add up to the load, within 100%: ' . json_encode($case));
    }
    if ($g['overflow_width'] > 0 && abs(($g['overflow_left'] + $g['overflow_width']) - $segSum) > 0.05) {
        $capFail('overflow overlay must end where the work segments end (no double counting): ' . json_encode($case));
    }
    if ($load > $av && $g['overflow_width'] <= 0) {
        $capFail('overload must stay visible in the bar: ' . json_encode($case));
    }
}
$g = $cm::bar($cm::figures(100, 80, 40, 10, 15));
if (array_keys($g['segments']) !== ['regular', 'fastlane', 'helping', 'proposed'] || $g['unavailable_width'] != 20.0 || $g['marker'] != 80.0) {
    $capFail('bar order is regular, fastlane, helping, proposed; not-available spans available..set');
}
if (preg_match('/\b(global \$DB|Session::|__\()/', file_get_contents($root . '/src/SprintCapacityMath.php'))) {
    $capFail('must stay free of GLPI, database and translation dependencies');
}
// Every capacity view goes through the shared model, the export no longer clips.
foreach ([
    'src/SprintDashboard.php' => 'SprintMember::figuresForMember(',
    'src/SprintExport.php'    => 'SprintMember::figuresForMember(',
    'ajax/backlogcapacity.php' => 'SprintMember::capacityFigures(',
    'src/Backlog.php'         => 'SprintMember::proposedBacklogCriteria(',
] as $rel => $needle) {
    if (!str_contains(file_get_contents($root . '/' . $rel), $needle)) {
        $capFail("{$rel} must use the shared capacity model ({$needle})");
    }
}
if (str_contains($exportSrc = file_get_contents($root . '/src/SprintExport.php'), 'max($totalCap - $used, 0)')
    || str_contains(file_get_contents($root . '/src/Backlog.php'), "free < 10 ? 'alert-warning'")) {
    $capFail('export must not clip overload and the backlog preview must use the shared threshold');
}
if (!str_contains($memberSrc = file_get_contents($root . '/src/SprintMember.php'), "step='0.5'")
    || !str_contains($memberSrc, '<datalist')) {
    $capFail('SprintMember::capacityInput() must offer a 0.5-step number input with presets');
}

// XSS (1.3.1 review): names in HTML messages go through plugin_sprint_html(),
// JSON text fields through plugin_sprint_text(), toast joins are escaped.
if (!str_contains(file_get_contents($root . '/front/backlog.form.php'), "was deleted from the backlog', 'sprint'), plugin_sprint_html(\$name)")) {
    fwrite(STDERR, "Backlog purge message must escape the item name\n");
    exit(1);
}
$si = file_get_contents($root . '/src/SprintItem.php');
if (substr_count($si, 'plugin_sprint_html($name)') < 2 || !str_contains($si, "esc(r.label) + (r.change ? ': ' + esc(r.change)")) {
    fwrite(STDERR, "Carry-over messages and request toasts must escape sprint names\n");
    exit(1);
}
if (!str_contains(file_get_contents($root . '/src/SprintRequest.php'), "'change' => plugin_sprint_text(")) {
    fwrite(STDERR, "Request changes must travel as plain text\n");
    exit(1);
}
// Cross-entity sprint names (1.3.1): the header switcher and the velocity
// window only list sprints the session may see.
$sprintSrc = file_get_contents($root . '/src/Sprint.php');
if (!preg_match("/\['status' => \[self::STATUS_PLANNED, self::STATUS_ACTIVE\]\]\s*\+ getEntitiesRestrictCriteria/", $sprintSrc)) {
    fwrite(STDERR, "Sprint switcher must be entity-restricted\n");
    exit(1);
}
if (!preg_match("/'entities_id' => \(int\)\(\\\$sprint->fields\['entities_id'\] \?\? 0\),\s*\] \+ getEntitiesRestrictCriteria\(Sprint::getTable\(\)/", file_get_contents($root . '/src/SprintDashboard.php'))) {
    fwrite(STDERR, "Velocity window must stay within the sprint's entity\n");
    exit(1);
}
echo "Static contracts OK\n";
