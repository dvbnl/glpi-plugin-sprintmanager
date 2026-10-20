<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use Html;
use Plugin;
use Session;

/**
 * The credit catalogue: products and tasks ("Functional design", "Onboarding
 * workshop") with a default number of credits, arranged in folders. Picking
 * a product on a sprint item fills in the credits, which stay editable — the
 * catalogue is a shortcut, not a lock. Folders only group; they cannot be
 * picked. Managed by the credit managers, readable by everyone who may fill
 * in credits on an item.
 */
class SprintCreditProduct extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_credits';

    public $dohistory = true;

    /** @var array<int,array<string,mixed>>|null */
    private static ?array $cache = null;

    public static function getTypeName($nb = 0): string
    {
        return _n('Credit product', 'Credit products', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-tags';
    }

    public static function getSearchURL($full = true)
    {
        return Plugin::getWebDir('sprint', $full) . '/front/sprintcreditproduct.php';
    }

    public static function getFormURL($full = true)
    {
        return Plugin::getWebDir('sprint', $full) . '/front/sprintcreditproduct.form.php';
    }

    // The catalogue is read by everyone who fills in credits on an item and
    // managed by the credit managers.
    public static function canView(): bool
    {
        return SprintCustomer::canUseCredits();
    }

    public static function canCreate(): bool
    {
        return SprintCustomer::canEditCredits();
    }

    public static function canUpdate(): bool
    {
        return SprintCustomer::canEditCredits();
    }

    public static function canPurge(): bool
    {
        return SprintCustomer::canEditCredits();
    }

    // =========================================================================
    // Lookup
    // =========================================================================

    public static function invalidate(): void
    {
        self::$cache = null;
    }

    /**
     * Visible catalogue entries in tree order: each folder followed by what
     * it holds, name order on every level, with a 'level' for indentation.
     * An entry whose parent is out of sight is shown at the top level.
     *
     * @return array<int,array<string,mixed>> by id
     */
    public static function getAll(bool $onlyActive = true): array
    {
        global $DB;

        if (self::$cache === null) {
            $rows = [];
            if ($DB->tableExists(self::getTable())) {
                foreach ((new self())->find(getEntitiesRestrictCriteria(self::getTable(), '', '', true), ['name ASC']) as $row) {
                    $rows[(int)$row['id']] = $row;
                }
                // Drag order first; entries never dragged (0) keep name order after them.
                uasort($rows, static function (array $a, array $b): int {
                    $sa = (int)($a['sort_order'] ?? 0);
                    $sb = (int)($b['sort_order'] ?? 0);
                    if (($sa === 0) !== ($sb === 0)) {
                        return $sa === 0 ? 1 : -1;
                    }
                    return $sa <=> $sb ?: strnatcasecmp((string)$a['name'], (string)$b['name']);
                });
            }
            self::$cache = self::treeOrder($rows);
        }
        if (!$onlyActive) {
            return self::$cache;
        }
        return array_filter(self::$cache, static fn($r) => (int)($r['is_active'] ?? 1) === 1);
    }

    /** @param array<int,array<string,mixed>> $rows name order */
    private static function treeOrder(array $rows): array
    {
        $children = [];
        foreach ($rows as $id => $row) {
            $pid = (int)($row['sprintcreditproducts_id'] ?? 0);
            if ($pid > 0 && !isset($rows[$pid])) {
                $pid = 0;
            }
            $children[$pid][] = $id;
        }
        $ordered = [];
        $emit = function (int $parent, int $level) use (&$ordered, &$emit, &$children, $rows): void {
            foreach ($children[$parent] ?? [] as $id) {
                if (isset($ordered[$id])) {
                    continue; // a cycle from a hand-edited database
                }
                $ordered[$id] = $rows[$id] + ['level' => $level];
                $emit($id, $level + 1);
            }
        };
        $emit(0, 0);
        return $ordered;
    }

    /** True when $id is an entry the session may see (0 = none). */
    public static function isVisible(int $id): bool
    {
        return $id === 0 || isset(self::getAll(false)[$id]);
    }

    /** True for a pickable product: visible and not a folder. */
    public static function isPickable(int $id): bool
    {
        $row = self::getAll(false)[$id] ?? null;
        return $row !== null && (int)($row['is_folder'] ?? 0) === 0;
    }

    public static function getNameFor(int $id): string
    {
        $all = self::getAll(false);
        return $id > 0 && isset($all[$id]) ? (string)$all[$id]['name'] : '';
    }

    /** "Folder › Subfolder › Product". */
    public static function getFullNameFor(int $id): string
    {
        $all   = self::getAll(false);
        $parts = [];
        $guard = 0;
        while ($id > 0 && isset($all[$id]) && $guard++ < 20) {
            array_unshift($parts, (string)$all[$id]['name']);
            $id = (int)($all[$id]['sprintcreditproducts_id'] ?? 0);
        }
        return implode(' › ', $parts);
    }

    /** Default credits of a product, 0 for an unknown one or a folder. */
    public static function creditsFor(int $id): float
    {
        $all = self::getAll(false);
        return $id > 0 && isset($all[$id]) && (int)($all[$id]['is_folder'] ?? 0) === 0 ? (float)$all[$id]['credits'] : 0.0;
    }

    /**
     * The product is leading: the credits to store for a row that carries
     * $productId, or null to leave the submitted/stored amount alone.
     * A new row or a newly picked product takes the product's amount. With
     * the product unchanged the stored amount stands (a carried-over split
     * keeps its share), and any other submitted amount falls back to the
     * product's — a different amount needs "Manual credits".
     *
     * @param array|null $stored fields of the row being updated, null on add
     */
    public static function leadingCredits(int $productId, array $input, ?array $stored): ?float
    {
        if ($productId <= 0) {
            return null;
        }
        $amount = SprintCustomer::normalizeCredits(self::creditsFor($productId));
        if ($stored === null || (int)($stored['plugin_sprint_sprintcreditproducts_id'] ?? 0) !== $productId) {
            return $amount;
        }
        if (!isset($input['credits'])
            || abs((float)$input['credits'] - (float)($stored['credits'] ?? 0)) < 0.005) {
            return null;
        }
        return $amount;
    }

    /** Ids of everything under $id, any depth. */
    public static function descendantsOf(int $id): array
    {
        $out = [];
        foreach (self::getAll(false) as $cid => $row) {
            if ((int)($row['sprintcreditproducts_id'] ?? 0) === $id) {
                $out[] = (int)$cid;
                $out   = array_merge($out, self::descendantsOf((int)$cid));
            }
        }
        return $out;
    }

    /**
     * `<option>` list for the item pickers, in tree order: folders as
     * disabled headings, products indented under them and carrying their
     * default credits; $selected is kept even when it is no longer active.
     */
    public static function dropdownOptions(int $selected = 0): string
    {
        $html   = '';
        $active = self::getAll(true);
        $indent = static fn(int $level) => str_repeat('&nbsp;&nbsp;&nbsp;', $level);
        // Read by js/sprint.js to show, under a locked credits input, how to
        // switch to a hand-entered amount.
        $hint   = " data-fixed-hint='" . __s('Fixed by the product.', 'sprint') . "'"
            . " data-manual-label='" . __s('Switch to manual credits', 'sprint') . "'";
        foreach ($active as $id => $row) {
            $level = (int)($row['level'] ?? 0);
            if ((int)($row['is_folder'] ?? 0) === 1) {
                $html .= "<option value='' disabled>" . $indent($level) . '📁 ' . htmlescape((string)$row['name']) . "</option>";
                continue;
            }
            $html .= "<option value='" . (int)$id . "' data-credits='" . htmlescape(SprintCustomer::formatCredits($row['credits'])) . "'" . $hint
                . ((int)$id === $selected ? ' selected' : '') . ">" . $indent($level)
                . htmlescape((string)$row['name'])
                . ((float)$row['credits'] > 0 ? ' (' . htmlescape(SprintCustomer::formatCredits($row['credits'])) . ')' : '')
                . "</option>";
        }
        if ($selected > 0 && !isset($active[$selected])) {
            $name = self::getFullNameFor($selected);
            if ($name !== '') {
                $html .= "<option value='" . $selected . "' data-credits='" . htmlescape(SprintCustomer::formatCredits(self::creditsFor($selected))) . "'" . $hint . " selected>"
                    . htmlescape($name) . ' (' . __('inactive', 'sprint') . ")</option>";
            }
        }
        return $html;
    }

    /**
     * Flat list for a template picker: [id, label (indented full name),
     * credits, is_folder] in tree order, active entries only.
     *
     * @return array<int,array{id:int,label:string,credits:string,is_folder:bool}>
     */
    public static function pickerRows(): array
    {
        $out = [];
        foreach (self::getAll(true) as $id => $row) {
            $out[] = [
                'id'        => (int)$id,
                // Non-breaking spaces survive the option's whitespace collapsing.
                'label'     => str_repeat("\u{a0}\u{a0}\u{a0}", (int)($row['level'] ?? 0)) . (string)$row['name'],
                'credits'   => SprintCustomer::formatCredits($row['credits']),
                'is_folder' => (int)($row['is_folder'] ?? 0) === 1,
            ];
        }
        return $out;
    }

    /** Folder `<option>`s for the parent picker, minus $selfId and its subtree. */
    private static function folderOptions(int $selected, int $selfId): string
    {
        $skip = $selfId > 0 ? array_merge([$selfId], self::descendantsOf($selfId)) : [];
        $html = "<option value='0'>— " . htmlescape(__('Top level', 'sprint')) . " —</option>";
        foreach (self::getAll(false) as $id => $row) {
            if ((int)($row['is_folder'] ?? 0) !== 1 || in_array((int)$id, $skip, true)) {
                continue;
            }
            $html .= "<option value='" . (int)$id . "'" . ((int)$id === $selected ? ' selected' : '') . ">"
                . str_repeat('&nbsp;&nbsp;&nbsp;', (int)($row['level'] ?? 0)) . htmlescape((string)$row['name']) . "</option>";
        }
        return $html;
    }

    // =========================================================================
    // Catalogue page
    // =========================================================================

    /**
     * The catalogue as a tree with its own add / edit / delete controls, a
     * search box, an active-only filter and folders that fold away. Rows
     * keep their tree order; filtering only hides them, so drag and the
     * up/down buttons always work on the real sibling order.
     */
    public static function showCatalogue(): void
    {
        $canedit = self::canCreate();
        $all     = self::getAll(false);

        echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'>";
        echo "<h2 class='mb-0'><i class='" . self::getIcon() . " me-2' aria-hidden='true'></i>" . __('Credit catalogue', 'sprint') . "</h2>";
        echo "<span class='text-muted sprint-small'>"
            . htmlescape(__('Products and tasks with a fixed number of credits. Pick one on a sprint item to set its credits. A different amount needs "Manual credits". Folders only group.', 'sprint'))
            . "</span>";
        echo "<span style='flex:1;'></span>";
        if ($canedit) {
            echo "<a class='btn btn-sm btn-outline-secondary' href='" . self::getFormURL() . "?is_folder=1'>"
                . "<i class='fas fa-folder-plus me-1' aria-hidden='true'></i>" . __('New folder', 'sprint') . "</a>";
            echo "<a class='btn btn-sm btn-primary' href='" . self::getFormURL() . "'>"
                . "<i class='fas fa-plus me-1' aria-hidden='true'></i>" . __('New product', 'sprint') . "</a>";
        }
        echo "</div>";

        echo "<div class='card'><div class='card-body p-0'>";
        if (!$all) {
            echo "<div class='p-3 text-muted'>" . __('The catalogue is empty. Add a folder or a product to start.', 'sprint') . "</div>";
            echo "</div></div>";
            return;
        }

        $hasChildren = [];
        foreach ($all as $row) {
            $hasChildren[(int)($row['sprintcreditproducts_id'] ?? 0)] = true;
        }

        // Toolbar: search, active only, fold all / unfold all, result count.
        echo "<div class='sprint-catalogue-toolbar d-flex flex-wrap align-items-center gap-2 p-2' role='search'>";
        echo "<label class='visually-hidden' for='sprint-catalogue-search'>" . __s('Search the catalogue', 'sprint') . "</label>";
        echo "<input type='search' id='sprint-catalogue-search' class='form-control form-control-sm sprint-catalogue-search' style='max-width:280px;' "
            . "placeholder='" . __s('Search name, code or description…', 'sprint') . "'>";
        echo "<label class='form-check form-switch mb-0 sprint-small'>"
            . "<input class='form-check-input sprint-catalogue-active' type='checkbox' role='switch' checked>"
            . "<span class='form-check-label'>" . __s('Active only', 'sprint') . "</span></label>";
        echo "<button type='button' class='btn btn-sm btn-outline-secondary sprint-catalogue-fold' data-fold='1'>"
            . "<i class='fas fa-folder me-1' aria-hidden='true'></i>" . __s('Collapse all', 'sprint') . "</button>";
        echo "<button type='button' class='btn btn-sm btn-outline-secondary sprint-catalogue-fold' data-fold='0'>"
            . "<i class='fas fa-folder-open me-1' aria-hidden='true'></i>" . __s('Expand all', 'sprint') . "</button>";
        echo "<span class='ms-auto text-muted sprint-small sprint-catalogue-count' role='status' aria-live='polite'></span>";
        echo "</div>";
        echo "<div class='sprint-catalogue-feedback px-2' role='status' aria-live='polite'></div>";

        $candrag = $canedit && self::canUpdate();
        echo "<div class='table-responsive'><table class='table table-sm align-middle mb-0 sprint-credit-catalogue'"
            . ($candrag ? " data-reorder-url='" . htmlescape(Plugin::getWebDir('sprint') . '/ajax/catalogreorder.php') . "'"
                . " data-token-url='" . htmlescape(Plugin::getWebDir('sprint') . '/ajax/csrftoken.php') . "'" : '')
            . ">";
        echo "<caption class='visually-hidden'>" . __s('Credit catalogue', 'sprint') . "</caption>";
        echo "<thead><tr>"
            . ($candrag ? "<th scope='col' style='width:26px;'><span class='visually-hidden'>" . __s('Order', 'sprint') . "</span></th>" : '')
            . "<th scope='col'>" . __('Name') . "</th>"
            . "<th scope='col' style='width:120px;'>" . __('Reference code', 'sprint') . "</th>"
            . "<th scope='col' class='text-center' style='width:130px;'>" . __('Default credits', 'sprint') . "</th>"
            . "<th scope='col'>" . __('Description') . "</th>"
            . "<th scope='col' style='width:90px;'><span class='visually-hidden'>" . __s('Add', 'sprint') . "</span></th>"
            . "<th scope='col' style='width:" . ($candrag ? 190 : 120) . "px;'><span class='visually-hidden'>" . __s('Actions') . "</span></th>"
            . "</tr></thead><tbody>";
        foreach ($all as $id => $row) {
            $id       = (int)$id;
            $isFolder = (int)($row['is_folder'] ?? 0) === 1;
            $inactive = (int)($row['is_active'] ?? 1) === 0;
            $pad      = 12 + 22 * (int)($row['level'] ?? 0);
            $parentId = (int)($row['sprintcreditproducts_id'] ?? 0);
            if ($parentId > 0 && !isset($all[$parentId])) {
                $parentId = 0;
            }
            $name   = (string)$row['name'];
            $search = mb_strtolower($name . ' ' . (string)($row['code'] ?? '') . ' ' . (string)($row['description'] ?? ''));
            echo "<tr class='sprint-catalogue-row" . ($isFolder ? ' fw-bold sprint-catalogue-folder' : '') . "'"
                . ($inactive ? " style='opacity:0.55;'" : '')
                . " data-id='{$id}' data-parent='{$parentId}' data-level='" . (int)($row['level'] ?? 0) . "'"
                . " data-active='" . ($inactive ? 0 : 1) . "' data-folder='" . ($isFolder ? 1 : 0) . "'"
                . " data-search='" . htmlescape($search) . "'>";
            if ($candrag) {
                echo "<td class='sprint-catalogue-grip text-muted' title='" . __s('Drag to reorder', 'sprint') . "'>"
                    . "<i class='fas fa-grip-vertical' aria-hidden='true'></i></td>";
            }
            echo "<td style='padding-left:{$pad}px;'>";
            if ($isFolder && isset($hasChildren[$id])) {
                echo "<button type='button' class='btn btn-sm btn-link p-0 me-1 sprint-catalogue-toggle' aria-expanded='true' "
                    . "aria-label='" . htmlescape(sprintf(__('Fold or unfold %s', 'sprint'), $name)) . "'>"
                    . "<i class='fas fa-chevron-down' aria-hidden='true'></i></button>";
            }
            echo "<i class='" . ($isFolder ? 'fas fa-folder' : 'fas fa-tag') . " me-2' aria-hidden='true' style='color:" . ($isFolder ? '#f59f00' : '#6c757d') . ";'></i>"
                . "<a href='" . self::getFormURLWithID($id) . "'>" . htmlescape($name) . "</a>";
            if ($inactive) {
                echo " <span class='badge bg-secondary'>" . __('inactive', 'sprint') . "</span>";
            }
            echo "</td>";
            echo "<td class='text-muted'>" . htmlescape((string)($row['code'] ?? '')) . "</td>";
            echo "<td class='text-center'>" . ($isFolder ? '' : "<span class='sprint-credit-chip'><i class='fas fa-coins' aria-hidden='true'></i> "
                . htmlescape(SprintCustomer::formatCredits($row['credits'])) . "</span>") . "</td>";
            echo "<td class='text-muted sprint-small'>" . htmlescape(mb_strimwidth((string)($row['description'] ?? ''), 0, 120, '…')) . "</td>";
            echo "<td class='text-nowrap'>";
            if ($canedit && $isFolder) {
                $label = sprintf(__('New product in %s', 'sprint'), $name);
                echo "<a class='btn btn-sm btn-outline-secondary' title='" . htmlescape($label) . "' aria-label='" . htmlescape($label) . "' href='"
                    . self::getFormURL() . "?parent={$id}'><i class='fas fa-plus' aria-hidden='true'></i></a>";
            }
            echo "</td>";
            echo "<td class='text-nowrap text-end'>";
            if ($candrag) {
                echo "<button type='button' class='btn btn-sm btn-outline-secondary me-1 sprint-catalogue-move' data-dir='-1' "
                    . "title='" . __s('Move up', 'sprint') . "' aria-label='" . htmlescape(sprintf(__('Move %s up', 'sprint'), $name)) . "'>"
                    . "<i class='fas fa-arrow-up' aria-hidden='true'></i></button>";
                echo "<button type='button' class='btn btn-sm btn-outline-secondary me-1 sprint-catalogue-move' data-dir='1' "
                    . "title='" . __s('Move down', 'sprint') . "' aria-label='" . htmlescape(sprintf(__('Move %s down', 'sprint'), $name)) . "'>"
                    . "<i class='fas fa-arrow-down' aria-hidden='true'></i></button>";
            }
            if ($canedit) {
                echo "<a class='btn btn-sm btn-outline-primary me-1' title='" . __s('Edit') . "' aria-label='"
                    . htmlescape(sprintf(__('Edit %s', 'sprint'), $name)) . "' href='" . self::getFormURLWithID($id) . "'>"
                    . "<i class='fas fa-pen' aria-hidden='true'></i></a>";
                echo "<form method='post' action='" . self::getFormURL() . "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $id]);
                echo "<button type='submit' name='purge' value='1' class='btn btn-sm btn-outline-danger' title='" . __s('Delete permanently') . "' "
                    . "aria-label='" . htmlescape(sprintf(__('Delete %s permanently', 'sprint'), $name)) . "' "
                    . "data-sprint-arm='" . htmlescape($isFolder && isset($hasChildren[$id])
                        ? __('Click again: the folder and everything in it are deleted', 'sprint')
                        : __('Click again to delete', 'sprint')) . "'>"
                    . "<i class='fas fa-trash' aria-hidden='true'></i></button>";
                Html::closeForm();
            }
            echo "</td></tr>";
        }
        echo "</tbody></table></div>";
        echo "<div class='sprint-catalogue-empty p-3 text-muted' hidden>"
            . "<span class='sprint-catalogue-empty-text'></span> "
            . "<button type='button' class='btn btn-sm btn-link sprint-catalogue-reset'>" . __s('Reset filters', 'sprint') . "</button></div>";
        echo "</div></div>";
        echo SprintCredit::armScript();
        self::renderFilterScript();
        if ($candrag) {
            self::renderReorderScript();
        }
    }

    /** Search, active-only and folding for the catalogue table. */
    private static function renderFilterScript(): void
    {
        $i18n = json_encode([
            'count'      => __('%1$s of %2$s products', 'sprint'),
            'noMatch'    => __('No product matches "%s".', 'sprint'),
            'noActive'   => __('No active products. Switch off "Active only" to see the inactive ones.', 'sprint'),
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        echo <<<HTML
<script>
(function(){
    var T = {$i18n};
    if (window.__sprintCatalogueFilterBound) { window.sprintCatalogueRefresh && window.sprintCatalogueRefresh(); return; }
    window.__sprintCatalogueFilterBound = true;
    var KEY = 'sprint.catalogue.collapsed';
    function loadCollapsed() {
        try { return JSON.parse(window.localStorage.getItem(KEY) || '[]') || []; } catch (e) { return []; }
    }
    function saveCollapsed(list) {
        try { window.localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) { /* private mode */ }
    }
    var collapsed = loadCollapsed().map(String);

    function card() {
        var all = document.querySelectorAll('.sprint-credit-catalogue');
        var table = all[all.length - 1];
        return table ? table.closest('.card') : null;
    }
    function fmt(s) {
        var args = Array.prototype.slice.call(arguments, 1);
        return s.replace(/%(\d)\\\$s/g, function(_, n){ return args[n - 1]; }).replace('%s', args[0]);
    }

    function refresh() {
        var root = card();
        if (!root) { return; }
        var rows = Array.prototype.slice.call(root.querySelectorAll('tr.sprint-catalogue-row'));
        var q = (root.querySelector('.sprint-catalogue-search') || {}).value || '';
        q = q.toLowerCase().trim();
        var activeOnly = !!(root.querySelector('.sprint-catalogue-active') || {}).checked;
        var byId = {};
        rows.forEach(function(r){ byId[r.getAttribute('data-id')] = r; });
        function ancestors(r) {
            var out = [], p = r.getAttribute('data-parent'), guard = 0;
            while (p && p !== '0' && byId[p] && guard++ < 50) { out.push(byId[p]); p = byId[p].getAttribute('data-parent'); }
            return out;
        }
        var activeOk = function(r){ return !activeOnly || r.getAttribute('data-active') === '1'; };
        var show = new Set();
        rows.forEach(function(r){
            var anc = ancestors(r);
            if (!activeOk(r) || !anc.every(activeOk)) { return; }
            if (q && (r.getAttribute('data-search') || '').indexOf(q) === -1) { return; }
            show.add(r);
            // A match keeps the folders above it in view.
            if (q) { anc.forEach(function(a){ show.add(a); }); }
        });
        var products = 0, shown = 0;
        rows.forEach(function(r){
            var visible = show.has(r);
            // Folding only applies while not searching: a search shows every match.
            if (visible && !q) {
                visible = !ancestors(r).some(function(a){ return collapsed.indexOf(a.getAttribute('data-id')) !== -1; });
            }
            r.hidden = !visible;
            var isFolder = r.getAttribute('data-folder') === '1';
            if (!isFolder) { products++; if (show.has(r)) { shown++; } }
            var toggle = r.querySelector('.sprint-catalogue-toggle');
            if (toggle) {
                var open = !!q || collapsed.indexOf(r.getAttribute('data-id')) === -1;
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                var icon = toggle.querySelector('i');
                if (icon) { icon.className = open ? 'fas fa-chevron-down' : 'fas fa-chevron-right'; }
            }
        });
        var count = root.querySelector('.sprint-catalogue-count');
        if (count) { count.textContent = fmt(T.count, String(shown), String(products)); }
        var empty = root.querySelector('.sprint-catalogue-empty');
        if (empty) {
            var none = show.size === 0;
            empty.hidden = !none;
            var text = empty.querySelector('.sprint-catalogue-empty-text');
            if (text) { text.textContent = q ? fmt(T.noMatch, q) : T.noActive; }
        }
    }
    window.sprintCatalogueRefresh = refresh;

    document.addEventListener('input', function(e){
        if (e.target && e.target.classList && e.target.classList.contains('sprint-catalogue-search')) { refresh(); }
    });
    document.addEventListener('change', function(e){
        if (e.target && e.target.classList && e.target.classList.contains('sprint-catalogue-active')) { refresh(); }
    });
    document.addEventListener('click', function(e){
        var t = e.target && e.target.closest ? e.target : null;
        if (!t) { return; }
        var toggle = t.closest('.sprint-catalogue-toggle');
        if (toggle) {
            var id = toggle.closest('tr').getAttribute('data-id');
            var at = collapsed.indexOf(id);
            if (at === -1) { collapsed.push(id); } else { collapsed.splice(at, 1); }
            saveCollapsed(collapsed);
            refresh();
            return;
        }
        var fold = t.closest('.sprint-catalogue-fold');
        if (fold) {
            var root = card();
            collapsed = [];
            if (fold.getAttribute('data-fold') === '1' && root) {
                root.querySelectorAll('.sprint-catalogue-toggle').forEach(function(b){
                    collapsed.push(b.closest('tr').getAttribute('data-id'));
                });
            }
            saveCollapsed(collapsed);
            refresh();
            return;
        }
        if (t.closest('.sprint-catalogue-reset')) {
            var r = card();
            if (r) {
                var s = r.querySelector('.sprint-catalogue-search');
                if (s) { s.value = ''; s.focus(); }
                var a = r.querySelector('.sprint-catalogue-active');
                if (a) { a.checked = false; }
            }
            refresh();
        }
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refresh);
    } else {
        refresh();
    }
})();
</script>
HTML;
    }

    /**
     * Drag a row (a folder takes its subtree along) to another spot among
     * its siblings, or move it one place with the up/down buttons; the new
     * order is saved straight away. Dropping onto a row with another parent
     * is refused: moving between folders stays an explicit edit on the form.
     */
    private static function renderReorderScript(): void
    {
        $i18n = json_encode([
            'saving' => __('Saving the order…', 'sprint'),
            'saved'  => __('Order saved', 'sprint'),
            'failed' => __('Could not save the order', 'sprint'),
            'retry'  => __('Try again', 'sprint'),
            'edge'   => __('Already at the edge of its folder', 'sprint'),
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        echo <<<HTML
<script>
(function(){
    var T = {$i18n};
    if (typeof jQuery === 'undefined' || window.__sprintCatalogueReorderBound) { return; }
    window.__sprintCatalogueReorderBound = true;
    var dragging = null;   // the block of rows being moved (row + subtree)

    // A folder row and everything indented under it move as one block.
    function blockOf(row) {
        var level = parseInt(row.getAttribute('data-level'), 10) || 0;
        var rows = [row];
        var next = row.nextElementSibling;
        while (next && (parseInt(next.getAttribute('data-level'), 10) || 0) > level) {
            rows.push(next);
            next = next.nextElementSibling;
        }
        return rows;
    }

    // One feedback line above the table: saving, saved, or failed with a retry.
    function feedback(table, kind, text, retry) {
        var box = table.closest('.card') ? table.closest('.card').querySelector('.sprint-catalogue-feedback') : null;
        if (!box) { return; }
        box.innerHTML = '';
        if (!text) { return; }
        var line = document.createElement('div');
        line.className = 'sprint-small py-1 ' + (kind === 'error' ? 'text-danger' : 'text-muted');
        line.textContent = text;
        if (retry) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-link py-0';
            btn.textContent = T.retry;
            btn.addEventListener('click', retry);
            line.appendChild(btn);
        }
        box.appendChild(line);
    }

    function saveOrder(tbody, parent) {
        var order = [];
        jQuery(tbody).find('.sprint-catalogue-row[data-parent="' + parent + '"]').each(function(){
            order.push(parseInt(this.getAttribute('data-id'), 10));
        });
        var table = jQuery(tbody).closest('table');
        var retry = function(){ saveOrder(tbody, parent); };
        feedback(table[0], 'info', T.saving);
        jQuery.ajax({
            url: table.attr('data-token-url'), type: 'GET', dataType: 'json', cache: false
        }).then(function(tok){
            return jQuery.ajax({
                url: table.attr('data-reorder-url'), type: 'POST', dataType: 'json',
                data: { order: JSON.stringify(order), _glpi_csrf_token: tok && tok.token ? tok.token : '' }
            });
        }).done(function(resp){
            if (resp && resp.success) {
                feedback(table[0], 'info', T.saved);
                if (window.glpi_toast_info) { window.glpi_toast_info(T.saved); }
            } else {
                feedback(table[0], 'error', (resp && resp.message) || T.failed, retry);
            }
        }).fail(function(){
            feedback(table[0], 'error', T.failed, retry);
        });
    }

    // Keyboard route: one place up or down among the siblings.
    jQuery(document).on('click', '.sprint-catalogue-move', function(){
        var row = this.closest('tr');
        var dir = parseInt(this.getAttribute('data-dir'), 10);
        var parent = row.getAttribute('data-parent');
        var tbody = row.parentNode;
        var siblings = Array.prototype.filter.call(tbody.querySelectorAll('.sprint-catalogue-row'), function(r){
            return r.getAttribute('data-parent') === parent;
        });
        var at = siblings.indexOf(row);
        var other = siblings[at + dir];
        var table = row.closest('table');
        if (!other) { feedback(table, 'info', T.edge); return; }
        var block = blockOf(row);
        if (dir < 0) {
            block.forEach(function(r){ tbody.insertBefore(r, other); });
        } else {
            var anchor = blockOf(other).slice(-1)[0];
            block.forEach(function(r){ tbody.insertBefore(r, anchor.nextSibling); anchor = r; });
        }
        this.focus();
        saveOrder(tbody, parent);
    });

    jQuery(document).on('mousedown', '.sprint-catalogue-grip', function(){
        var row = this.closest('tr');
        if (row) { row.setAttribute('draggable', 'true'); }
    });
    jQuery(document).on('dragstart', '.sprint-catalogue-row', function(e){
        if (this.getAttribute('draggable') !== 'true') { e.preventDefault(); return; }
        dragging = blockOf(this);
        dragging.forEach(function(r){ r.classList.add('sprint-catalogue-dragging'); });
        if (e.originalEvent.dataTransfer) {
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData('text/plain', this.getAttribute('data-id'));
        }
    });
    jQuery(document).on('dragover', '.sprint-catalogue-row', function(e){
        if (!dragging) { return; }
        var sameParent = this.getAttribute('data-parent') === dragging[0].getAttribute('data-parent');
        if (!sameParent || dragging.indexOf(this) !== -1) { return; }
        e.preventDefault();
        var rect = this.getBoundingClientRect();
        var below = (e.originalEvent.clientY - rect.top) > rect.height / 2;
        jQuery('.sprint-catalogue-row').removeClass('sprint-catalogue-over-top sprint-catalogue-over-bottom');
        this.classList.add(below ? 'sprint-catalogue-over-bottom' : 'sprint-catalogue-over-top');
    });
    jQuery(document).on('dragleave', '.sprint-catalogue-row', function(){
        this.classList.remove('sprint-catalogue-over-top', 'sprint-catalogue-over-bottom');
    });
    jQuery(document).on('drop', '.sprint-catalogue-row', function(e){
        if (!dragging) { return; }
        e.preventDefault();
        var target = this;
        var below  = target.classList.contains('sprint-catalogue-over-bottom');
        jQuery('.sprint-catalogue-row').removeClass('sprint-catalogue-over-top sprint-catalogue-over-bottom');
        if (target.getAttribute('data-parent') !== dragging[0].getAttribute('data-parent') || dragging.indexOf(target) !== -1) {
            return;
        }
        // Dropping below a folder means below its whole subtree.
        var anchor = below ? blockOf(target).slice(-1)[0] : target;
        var tbody  = target.parentNode;
        dragging.forEach(function(r){
            if (below) { tbody.insertBefore(r, anchor.nextSibling); anchor = r; }
            else { tbody.insertBefore(r, anchor); }
        });
        saveOrder(tbody, dragging[0].getAttribute('data-parent'));
    });
    jQuery(document).on('dragend', '.sprint-catalogue-row', function(){
        jQuery('.sprint-catalogue-row').removeClass('sprint-catalogue-dragging sprint-catalogue-over-top sprint-catalogue-over-bottom')
            .each(function(){ this.removeAttribute('draggable'); });
        dragging = null;
    });
})();
</script>
HTML;
    }

    // =========================================================================
    // Form
    // =========================================================================

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $isNew    = (int)$ID <= 0;
        $isFolder = $isNew
            ? (int)($_GET['is_folder'] ?? 0) === 1
            : (int)($this->fields['is_folder'] ?? 0) === 1;
        $parent   = $isNew
            ? (int)($_GET['parent'] ?? 0)
            : (int)($this->fields['sprintcreditproducts_id'] ?? 0);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='sprint-product-name'>" . __('Name') . "</label></td><td>";
        echo Html::input('name', ['id' => 'sprint-product-name', 'value' => $this->fields['name'] ?? '', 'size' => 40]);
        echo "</td>";
        echo "<td>" . __('Type', 'sprint') . "</td><td>";
        \Dropdown::showFromArray('is_folder', [
            0 => self::getTypeName(1),
            1 => __('Folder', 'sprint'),
        ], ['value' => $isFolder ? 1 : 0]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='sprint-product-folder'>" . __('Folder', 'sprint') . "</label></td><td>";
        echo "<select class='form-select' id='sprint-product-folder' name='sprintcreditproducts_id' style='max-width:320px;'>"
            . self::folderOptions($parent, (int)$ID) . "</select>";
        echo "</td>";
        echo "<td><label for='sprint-product-code'>" . __('Reference code', 'sprint') . "</label></td><td>";
        echo Html::input('code', ['id' => 'sprint-product-code', 'value' => $this->fields['code'] ?? '', 'size' => 20]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='sprint-product-credits'>" . __('Default credits', 'sprint') . "</label><br>";
        echo "<span class='text-muted sprint-small' id='sprint-product-credits-help'>"
            . __('Set on an item when this product is picked. A different amount needs "Manual credits". Not used for a folder.', 'sprint') . "</span></td><td>";
        echo "<input type='number' class='form-control' id='sprint-product-credits' aria-describedby='sprint-product-credits-help' name='credits' min='0' step='0.25' style='max-width:140px;' value='"
            . SprintCustomer::formatCredits($this->fields['credits'] ?? 0) . "'>";
        echo "</td>";
        echo "<td>" . __('Active') . "</td><td>";
        \Dropdown::showYesNo('is_active', $this->fields['is_active'] ?? 1);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td><label for='sprint-product-description'>" . __('Description') . "</label></td><td colspan='3'>";
        echo "<textarea class='form-control' id='sprint-product-description' name='description' rows='3'>"
            . htmlescape((string)($this->fields['description'] ?? '')) . "</textarea>";
        echo "</td></tr>";

        $this->showFormButtons($options);
        return true;
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'code',
            'name'     => __('Reference code', 'sprint'),
            'datatype' => 'string',
        ];
        // DECIMAL column: 'integer' would strip the decimal point in filterValues().
        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'credits',
            'name'     => __('Default credits', 'sprint'),
            'datatype' => 'decimal',
        ];
        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'is_active',
            'name'     => __('Active'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'description',
            'name'     => __('Description'),
            'datatype' => 'text',
        ];
        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'is_folder',
            'name'     => __('Folder', 'sprint'),
            'datatype' => 'bool',
        ];

        return $tab;
    }

    // =========================================================================
    // Persistence
    // =========================================================================

    public function prepareInputForAdd($input)
    {
        return $this->sanitize($input, 0);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->sanitize($input, (int)$this->getID());
    }

    /** @return array<string,mixed>|false */
    private function sanitize(array $input, int $selfId)
    {
        if (!SprintCustomer::canEditCredits()) {
            Session::addMessageAfterRedirect(__('You are not allowed to manage credits', 'sprint'), false, ERROR);
            return false;
        }
        if (isset($input['is_folder'])) {
            $input['is_folder'] = (int)(bool)$input['is_folder'];
            if ($input['is_folder'] === 1) {
                $input['credits'] = 0;
            }
        }
        if (isset($input['credits'])) {
            $input['credits'] = SprintCustomer::normalizeCredits($input['credits']);
        }
        if (isset($input['is_active'])) {
            $input['is_active'] = (int)(bool)$input['is_active'];
        }
        if (isset($input['sprintcreditproducts_id'])) {
            // The parent must be a folder and not the entry itself or one of
            // its own descendants.
            $parent = (int)$input['sprintcreditproducts_id'];
            $row    = self::getAll(false)[$parent] ?? null;
            if (
                $parent > 0 && ($row === null || (int)($row['is_folder'] ?? 0) !== 1
                || $parent === $selfId || ($selfId > 0 && in_array($parent, self::descendantsOf($selfId), true)))
            ) {
                $parent = 0;
            }
            $input['sprintcreditproducts_id'] = $parent;
        }
        return $input;
    }

    public function post_addItem()
    {
        self::invalidate();
        parent::post_addItem();
    }

    public function post_updateItem($history = true)
    {
        self::invalidate();
        parent::post_updateItem($history);
    }

    /**
     * Purging an entry leaves the items' credits as they are, unlinked; what
     * a purged folder held moves up one level.
     */
    public function post_purgeItem()
    {
        global $DB;

        $DB->update(SprintItem::getTable(), ['plugin_sprint_sprintcreditproducts_id' => 0], [
            'plugin_sprint_sprintcreditproducts_id' => (int)$this->getID(),
        ]);
        $DB->update(self::getTable(), ['sprintcreditproducts_id' => (int)($this->fields['sprintcreditproducts_id'] ?? 0)], [
            'sprintcreditproducts_id' => (int)$this->getID(),
        ]);
        self::invalidate();
        parent::post_purgeItem();
    }
}
