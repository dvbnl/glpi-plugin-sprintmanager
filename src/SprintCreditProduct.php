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
        foreach ($active as $id => $row) {
            $level = (int)($row['level'] ?? 0);
            if ((int)($row['is_folder'] ?? 0) === 1) {
                $html .= "<option value='' disabled>" . $indent($level) . '📁 ' . htmlescape((string)$row['name']) . "</option>";
                continue;
            }
            $html .= "<option value='" . (int)$id . "' data-credits='" . htmlescape(SprintCustomer::formatCredits($row['credits'])) . "'"
                . ((int)$id === $selected ? ' selected' : '') . ">" . $indent($level)
                . htmlescape((string)$row['name'])
                . ((float)$row['credits'] > 0 ? ' (' . htmlescape(SprintCustomer::formatCredits($row['credits'])) . ')' : '')
                . "</option>";
        }
        if ($selected > 0 && !isset($active[$selected])) {
            $name = self::getFullNameFor($selected);
            if ($name !== '') {
                $html .= "<option value='" . $selected . "' data-credits='" . htmlescape(SprintCustomer::formatCredits(self::creditsFor($selected))) . "' selected>"
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

    /** The catalogue as a tree with its own add / edit / delete controls. */
    public static function showCatalogue(): void
    {
        $canedit = self::canCreate();
        $all     = self::getAll(false);

        echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'>";
        echo "<h2 class='mb-0'><i class='" . self::getIcon() . " me-2'></i>" . __('Credit catalogue', 'sprint') . "</h2>";
        echo "<span class='text-muted sprint-small'>"
            . htmlescape(__('Products and tasks with a default number of credits. Pick one on a sprint item to fill in its credits; the amount stays editable. Folders only group.', 'sprint'))
            . "</span>";
        echo "<span style='flex:1;'></span>";
        if ($canedit) {
            echo "<a class='btn btn-sm btn-outline-secondary' href='" . self::getFormURL() . "?is_folder=1'>"
                . "<i class='fas fa-folder-plus me-1'></i>" . __('New folder', 'sprint') . "</a>";
            echo "<a class='btn btn-sm btn-primary' href='" . self::getFormURL() . "'>"
                . "<i class='fas fa-plus me-1'></i>" . __('New product', 'sprint') . "</a>";
        }
        echo "</div>";

        echo "<div class='card'><div class='card-body p-0'>";
        if (!$all) {
            echo "<div class='p-3 text-muted'>" . __('The catalogue is empty. Add a folder or a product to start.', 'sprint') . "</div>";
            echo "</div></div>";
            return;
        }

        echo "<div class='table-responsive'><table class='table table-sm align-middle mb-0 sprint-credit-catalogue'>";
        echo "<thead><tr>"
            . "<th>" . __('Name') . "</th>"
            . "<th style='width:120px;'>" . __('Reference code', 'sprint') . "</th>"
            . "<th class='text-center' style='width:130px;'>" . __('Default credits', 'sprint') . "</th>"
            . "<th>" . __('Description') . "</th>"
            . "<th style='width:90px;'></th>"
            . "<th style='width:120px;'></th>"
            . "</tr></thead><tbody>";
        foreach ($all as $id => $row) {
            $id       = (int)$id;
            $isFolder = (int)($row['is_folder'] ?? 0) === 1;
            $inactive = (int)($row['is_active'] ?? 1) === 0;
            $pad      = 12 + 22 * (int)($row['level'] ?? 0);
            echo "<tr" . ($inactive ? " style='opacity:0.55;'" : '') . ($isFolder ? " class='fw-bold'" : '') . ">";
            echo "<td style='padding-left:{$pad}px;'>"
                . "<i class='" . ($isFolder ? 'fas fa-folder' : 'fas fa-tag') . " me-2' style='color:" . ($isFolder ? '#f59f00' : '#6c757d') . ";'></i>"
                . "<a href='" . self::getFormURLWithID($id) . "'>" . htmlescape((string)$row['name']) . "</a>";
            if ($inactive) {
                echo " <span class='badge bg-secondary'>" . __('inactive', 'sprint') . "</span>";
            }
            echo "</td>";
            echo "<td class='text-muted'>" . htmlescape((string)($row['code'] ?? '')) . "</td>";
            echo "<td class='text-center'>" . ($isFolder ? '' : "<span class='sprint-credit-chip'><i class='fas fa-coins'></i> "
                . htmlescape(SprintCustomer::formatCredits($row['credits'])) . "</span>") . "</td>";
            echo "<td class='text-muted sprint-small'>" . htmlescape(mb_strimwidth((string)($row['description'] ?? ''), 0, 120, '…')) . "</td>";
            echo "<td class='text-nowrap'>";
            if ($canedit && $isFolder) {
                echo "<a class='btn btn-sm btn-outline-secondary' title='" . __s('New product in this folder', 'sprint') . "' href='"
                    . self::getFormURL() . "?parent={$id}'><i class='fas fa-plus'></i></a>";
            }
            echo "</td>";
            echo "<td class='text-nowrap text-end'>";
            if ($canedit) {
                echo "<a class='btn btn-sm btn-outline-primary me-1' title='" . __s('Edit') . "' href='" . self::getFormURLWithID($id) . "'><i class='fas fa-pen'></i></a>";
                echo "<form method='post' action='" . self::getFormURL() . "' style='display:inline;' onsubmit=\"return confirm('" . __s('Confirm deletion?') . "');\">";
                echo Html::hidden('id', ['value' => $id]);
                echo "<button type='submit' name='purge' value='1' class='btn btn-sm btn-outline-danger' title='" . __s('Delete permanently') . "'><i class='fas fa-trash'></i></button>";
                Html::closeForm();
            }
            echo "</td></tr>";
        }
        echo "</tbody></table></div>";
        echo "</div></div>";
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
        echo "<td>" . __('Name') . "</td><td>";
        echo Html::input('name', ['value' => $this->fields['name'] ?? '', 'size' => 40]);
        echo "</td>";
        echo "<td>" . __('Type', 'sprint') . "</td><td>";
        \Dropdown::showFromArray('is_folder', [
            0 => self::getTypeName(1),
            1 => __('Folder', 'sprint'),
        ], ['value' => $isFolder ? 1 : 0]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Folder', 'sprint') . "</td><td>";
        echo "<select class='form-select' name='sprintcreditproducts_id' style='max-width:320px;'>"
            . self::folderOptions($parent, (int)$ID) . "</select>";
        echo "</td>";
        echo "<td>" . __('Reference code', 'sprint') . "</td><td>";
        echo Html::input('code', ['value' => $this->fields['code'] ?? '', 'size' => 20]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Default credits', 'sprint') . "<br>";
        echo "<span class='text-muted sprint-small'>"
            . __('Filled in on an item when this product is picked; the amount stays editable. Not used for a folder.', 'sprint') . "</span></td><td>";
        echo "<input type='number' class='form-control' name='credits' min='0' step='0.25' style='max-width:140px;' value='"
            . SprintCustomer::formatCredits($this->fields['credits'] ?? 0) . "'>";
        echo "</td>";
        echo "<td>" . __('Active') . "</td><td>";
        \Dropdown::showYesNo('is_active', $this->fields['is_active'] ?? 1);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Description') . "</td><td colspan='3'>";
        echo "<textarea class='form-control' name='description' rows='3'>"
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
