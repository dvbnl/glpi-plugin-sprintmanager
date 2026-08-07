<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use Session;

/**
 * Backlog category (Projects / R&D / Internal / ...). Admin-defined in the
 * plugin settings; every sprint item carries at most one, so backlog capacity
 * can be aggregated and capped per category and per sprint.
 */
class SprintCategory extends CommonDBTM
{
    public static $rightname = 'config';

    /** Per-sprint capacity caps live in this side table (no own class). */
    const CAPS_TABLE = 'glpi_plugin_sprint_sprintcategorycaps';

    public static function getTypeName($nb = 0): string
    {
        return _n('Backlog category', 'Backlog categories', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-folder-tree';
    }

    /**
     * All categories in tree order (each parent directly followed by its
     * subcategories), with a computed `level` key (0 = top, 1 = sub). A child
     * whose parent is missing or deactivated is promoted to top level so it
     * never disappears from the boards.
     *
     * @return array<int,array<string,mixed>> id => row, per-request cache
     */
    public static function getAll(bool $onlyActive = true): array
    {
        static $cache = null;
        if ($cache === null) {
            $rows = [];
            foreach ((new self())->find([], ['sort_order ASC', 'name ASC']) as $row) {
                $rows[(int)$row['id']] = $row;
            }
            $cache = self::treeOrder($rows);
        }
        if (!$onlyActive) {
            return $cache;
        }
        // An active child under an inactive parent still shows, as top level.
        $active = array_filter($cache, fn($r) => (int)($r['is_active'] ?? 1) === 1);
        foreach ($active as $id => $row) {
            $pid = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
            if ($pid > 0 && !isset($active[$pid])) {
                $active[$id]['level'] = 0;
                $active[$id]['plugin_sprint_sprintcategories_id'] = 0;
            }
        }
        return $active;
    }

    /**
     * Sort-order pass: parents first, each followed by its children. Deeper
     * nesting (bad data) is clamped to level 1 and cycles are promoted to top
     * level, so no category can ever drop out of the list.
     */
    private static function treeOrder(array $rows): array
    {
        $children = [];
        $top      = [];
        foreach ($rows as $id => $row) {
            $pid = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
            if ($pid > 0 && isset($rows[$pid]) && $pid !== $id) {
                $children[$pid][$id] = $row;
            } else {
                $row['plugin_sprint_sprintcategories_id'] = 0;
                $top[$id] = $row;
            }
        }
        $ordered = [];
        $emit = function (int $id, array $row, int $level) use (&$ordered, &$emit, &$children): void {
            $row['level'] = min(1, $level);
            $ordered[$id] = $row;
            foreach ($children[$id] ?? [] as $cid => $child) {
                if (!isset($ordered[$cid])) {
                    $emit($cid, $child, $level + 1);
                }
            }
            unset($children[$id]);
        };
        foreach ($top as $id => $row) {
            $emit($id, $row, 0);
        }
        foreach ($children as $descendants) {
            foreach ($descendants as $cid => $child) {
                if (!isset($ordered[$cid])) {
                    $child['plugin_sprint_sprintcategories_id'] = 0;
                    $child['level'] = 0;
                    $ordered[$cid] = $child;
                }
            }
        }
        return $ordered;
    }

    public static function getNameFor(int $id): string
    {
        $all = self::getAll(false);
        return $id > 0 && isset($all[$id]) ? (string)$all[$id]['name'] : '';
    }

    /** Parent category id, 0 for top-level or unknown categories. */
    public static function getParentFor(int $id): int
    {
        $all = self::getAll(false);
        return $id > 0 && isset($all[$id]) ? (int)($all[$id]['plugin_sprint_sprintcategories_id'] ?? 0) : 0;
    }

    /** @return int[] ids of the subcategories of $id */
    public static function getChildrenOf(int $id, bool $onlyActive = true): array
    {
        $out = [];
        foreach (self::getAll($onlyActive) as $cid => $row) {
            if ((int)($row['plugin_sprint_sprintcategories_id'] ?? 0) === $id) {
                $out[] = (int)$cid;
            }
        }
        return $out;
    }

    /** "Parent › Child" for subcategories, plain name otherwise. */
    public static function getFullNameFor(int $id): string
    {
        $name = self::getNameFor($id);
        if ($name === '') {
            return '';
        }
        $parent = self::getParentFor($id);
        return $parent > 0 ? self::getNameFor($parent) . ' › ' . $name : $name;
    }

    /** `<option>` list in tree order, subcategories indented. */
    public static function dropdownOptions(int $selected = 0, bool $onlyActive = true): string
    {
        $html = '';
        foreach (self::getAll($onlyActive) as $cid => $cat) {
            $indent = ((int)($cat['level'] ?? 0) > 0) ? '&nbsp;&nbsp;&nbsp;— ' : '';
            $html  .= "<option value='" . (int)$cid . "'" . ((int)$cid === $selected ? ' selected' : '') . ">"
                . $indent . htmlescape((string)$cat['name']) . "</option>";
        }
        return $html;
    }

    public static function getColorFor(int $id): string
    {
        $all = self::getAll(false);
        return $id > 0 && isset($all[$id]) ? (string)$all[$id]['color'] : '#6c757d';
    }

    /** Small colored category pill, '' when the item has no category. */
    public static function renderPill(int $id): string
    {
        $name = self::getFullNameFor($id);
        if ($name === '') {
            return '';
        }
        $color = htmlescape(self::getColorFor($id));
        return " <span class='sprint-category-pill' style='background:color-mix(in srgb,{$color} 18%,transparent);"
            . "border:1px solid {$color};color:{$color};'>"
            . "<i class='fas fa-folder' style='font-size:0.75em;'></i> "
            . htmlescape($name) . "</span>";
    }

    /** Purging a category resets referencing items to "no category". */
    public function post_purgeItem()
    {
        global $DB;
        $DB->update(SprintItem::getTable(), ['plugin_sprint_sprintcategories_id' => 0], [
            'plugin_sprint_sprintcategories_id' => (int)$this->getID(),
        ]);
        $DB->delete(self::CAPS_TABLE, [
            'plugin_sprint_sprintcategories_id' => (int)$this->getID(),
        ]);
        // Orphaned subcategories become top-level instead of vanishing.
        $DB->update(self::getTable(), ['plugin_sprint_sprintcategories_id' => 0], [
            'plugin_sprint_sprintcategories_id' => (int)$this->getID(),
        ]);
    }

    /**
     * Valid parent id for a category: must exist, be top-level and differ from
     * the category itself; a category that has subcategories stays top-level
     * (one level of nesting only). Returns 0 when the parent is not allowed.
     */
    private static function sanitizeParent(int $parentId, int $selfId): int
    {
        if ($parentId <= 0 || $parentId === $selfId) {
            return 0;
        }
        $all = self::getAll(false);
        if (!isset($all[$parentId]) || (int)($all[$parentId]['plugin_sprint_sprintcategories_id'] ?? 0) > 0) {
            return 0;
        }
        if ($selfId > 0 && self::getChildrenOf($selfId, false) !== []) {
            return 0;
        }
        return $parentId;
    }

    // === Config-form CRUD ===

    /** Persist the category manager form: update, delete and add rows. */
    public static function saveFromConfigForm(array $input): void
    {
        if (!Session::haveRight('config', UPDATE)) {
            return;
        }

        $names    = (array)($input['cat_name'] ?? []);
        $colors   = (array)($input['cat_color'] ?? []);
        $sorts    = (array)($input['cat_sort'] ?? []);
        $parents  = (array)($input['cat_parent'] ?? []);
        $limitMin = (array)($input['cat_limit_min'] ?? []);
        $limitMax = (array)($input['cat_limit_max'] ?? []);
        $delete   = array_map('intval', (array)($input['cat_delete'] ?? []));

        $defaultLimits = [];
        foreach ($names as $id => $unused) {
            $id = (int)$id;
            if ($id <= 0 || in_array($id, $delete, true)) {
                continue;
            }
            $defaultLimits[$id] = [
                'min' => (float)($limitMin[$id] ?? 0),
                'max' => (float)($limitMax[$id] ?? 0),
            ];
        }

        foreach ($names as $id => $name) {
            $id = (int)$id;
            if ($id <= 0 || in_array($id, $delete, true)) {
                continue;
            }
            $cat = new self();
            if (!$cat->getFromDB($id)) {
                continue;
            }
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $cat->update([
                'id'         => $id,
                'name'       => $name,
                'color'      => self::normalizeColor((string)($colors[$id] ?? $cat->fields['color'])),
                'sort_order' => (int)($sorts[$id] ?? $cat->fields['sort_order']),
                'plugin_sprint_sprintcategories_id' => self::sanitizeParent((int)($parents[$id] ?? 0), $id),
            ]);
        }

        foreach ($delete as $id) {
            if ($id <= 0) {
                continue;
            }
            $cat = new self();
            if ($cat->getFromDB($id)) {
                $cat->delete(['id' => $id], true);
            }
        }

        $newName = trim((string)($input['cat_new_name'] ?? ''));
        if ($newName !== '') {
            $existing = array_map(
                fn($r) => mb_strtolower((string)$r['name']),
                self::getAll(false)
            );
            if (!in_array(mb_strtolower($newName), $existing, true)) {
                $newId = (new self())->add([
                    'name'       => $newName,
                    'color'      => self::normalizeColor((string)($input['cat_new_color'] ?? '#0d6efd')),
                    'sort_order' => (int)($input['cat_new_sort'] ?? 0),
                    'is_active'  => 1,
                    'plugin_sprint_sprintcategories_id' => self::sanitizeParent((int)($input['cat_new_parent'] ?? 0), 0),
                ]);
                if ($newId) {
                    $defaultLimits[(int)$newId] = [
                        'min' => (float)($input['cat_new_limit_min'] ?? 0),
                        'max' => (float)($input['cat_new_limit_max'] ?? 0),
                    ];
                }
            }
        }

        Config::saveCategoryDefaultLimits($defaultLimits);
    }

    private static function normalizeColor(string $color): string
    {
        $color = trim($color);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#0d6efd';
    }

    /** Category manager UI, its own form below the settings form. */
    public static function showManager(): void
    {
        $canedit = Session::haveRight('config', UPDATE);
        $all     = self::getAll(false);

        echo "<div class='center' style='margin-top:18px;'>";
        if ($canedit) {
            echo "<form method='post' action='" . \Plugin::getWebDir('sprint') . "/front/config.form.php'>";
        }
        echo "<table class='tab_cadre_fixe sprint-themed'>";
        echo "<tr class='tab_bg_2'><th colspan='7'>"
            . "<i class='" . self::getIcon() . "' style='margin-right:6px;'></i>"
            . __('Backlog categories', 'sprint') . "</th></tr>";
        echo "<tr class='tab_bg_1'><td colspan='7' class='text-muted' style='font-size:0.85em;'>"
            . __('Group backlog items into categories (e.g. Projects, R&D, Internal, Requests). The backlog shows a section per category and the capacity dashboard aggregates and caps estimated capacity per category per sprint.', 'sprint')
            . ' '
            . __('Pick a parent to turn a category into a subcategory: it nests under its parent on the backlog and in the planning matrix (one level deep).', 'sprint')
            . ' '
            . __('Default min/max limits apply to every sprint without its own limits for that category; 0 = no limit.', 'sprint')
            . "</td></tr>";

        echo "<tr class='tab_bg_2'>"
            . "<th>" . __('Name') . "</th>"
            . "<th style='width:200px;'>" . __('Parent category', 'sprint') . "</th>"
            . "<th style='width:110px;'>" . __('Color', 'sprint') . "</th>"
            . "<th style='width:90px;'>" . __('Order', 'sprint') . "</th>"
            . "<th style='width:100px;'>" . __('Default min %', 'sprint') . "</th>"
            . "<th style='width:100px;'>" . __('Default max %', 'sprint') . "</th>"
            . "<th style='width:90px;'>" . __('Delete') . "</th></tr>";

        $defaultLimits = Config::getCategoryDefaultLimits();

        // A category that has children cannot itself become a child.
        $parentSelect = function (string $fieldName, int $selfId, int $selected, bool $enabled) use ($all, $canedit): string {
            $html = "<select class='form-select' name='{$fieldName}'" . (($canedit && $enabled) ? '' : ' disabled') . ">";
            $html .= "<option value='0'>-----</option>";
            foreach ($all as $pid => $parent) {
                if ((int)$pid === $selfId || (int)($parent['plugin_sprint_sprintcategories_id'] ?? 0) > 0) {
                    continue;
                }
                $html .= "<option value='" . (int)$pid . "'" . ((int)$pid === $selected ? ' selected' : '') . ">"
                    . htmlescape((string)$parent['name']) . "</option>";
            }
            return $html . "</select>";
        };

        foreach ($all as $id => $row) {
            $name     = htmlescape((string)$row['name']);
            $color    = htmlescape((string)$row['color']);
            $sort     = (int)$row['sort_order'];
            $parentId = (int)($row['plugin_sprint_sprintcategories_id'] ?? 0);
            $isParent = self::getChildrenOf((int)$id, false) !== [];
            $indent   = ((int)($row['level'] ?? 0) > 0)
                ? "<i class='fas fa-turn-up fa-rotate-90 text-muted me-1' style='margin-left:14px;'></i>"
                : '';
            echo "<tr class='tab_bg_1'>";
            echo "<td><div class='d-flex align-items-center'>{$indent}<input type='text' class='form-control' name='cat_name[{$id}]' value='{$name}'" . ($canedit ? '' : ' readonly') . "></div></td>";
            echo "<td class='center'" . ($isParent ? " title='" . __s('This category has subcategories, so it stays top-level.', 'sprint') . "'" : '') . ">"
                . $parentSelect("cat_parent[{$id}]", (int)$id, $parentId, !$isParent) . "</td>";
            echo "<td class='center'><input type='color' class='form-control form-control-color' name='cat_color[{$id}]' value='{$color}'" . ($canedit ? '' : ' disabled') . "></td>";
            echo "<td class='center'><input type='number' class='form-control' name='cat_sort[{$id}]' value='{$sort}' style='width:80px;'" . ($canedit ? '' : ' readonly') . "></td>";
            $defMin = (float)($defaultLimits[(int)$id]['min'] ?? 0);
            $defMax = (float)($defaultLimits[(int)$id]['max'] ?? 0);
            echo "<td class='center'><input type='number' min='0' max='9999' step='0.5' class='form-control' name='cat_limit_min[{$id}]' value='" . ($defMin > 0 ? $defMin : 0) . "' style='width:90px;'" . ($canedit ? '' : ' readonly') . "></td>";
            echo "<td class='center'><input type='number' min='0' max='9999' step='0.5' class='form-control' name='cat_limit_max[{$id}]' value='" . ($defMax > 0 ? $defMax : 0) . "' style='width:90px;'" . ($canedit ? '' : ' readonly') . "></td>";
            echo "<td class='center'><input type='checkbox' name='cat_delete[]' value='{$id}'" . ($canedit ? '' : ' disabled') . "></td>";
            echo "</tr>";
        }

        if ($canedit) {
            echo "<tr class='tab_bg_1'>";
            echo "<td><input type='text' class='form-control' name='cat_new_name' placeholder='" . __s('New category…', 'sprint') . "'></td>";
            echo "<td class='center'>" . $parentSelect('cat_new_parent', 0, 0, true) . "</td>";
            echo "<td class='center'><input type='color' class='form-control form-control-color' name='cat_new_color' value='#0d6efd'></td>";
            echo "<td class='center'><input type='number' class='form-control' name='cat_new_sort' value='0' style='width:80px;'></td>";
            echo "<td class='center'><input type='number' min='0' max='9999' step='0.5' class='form-control' name='cat_new_limit_min' value='0' style='width:90px;'></td>";
            echo "<td class='center'><input type='number' min='0' max='9999' step='0.5' class='form-control' name='cat_new_limit_max' value='0' style='width:90px;'></td>";
            echo "<td></td>";
            echo "</tr>";
            echo "<tr class='tab_bg_1'><td colspan='7' class='center'>";
            echo \Html::submit(__('Save'), [
                'name'  => 'save_sprint_categories',
                'class' => 'btn btn-primary',
            ]);
            echo "</td></tr>";
        }

        echo "</table>";
        if ($canedit) {
            \Html::closeForm();
        }
        echo "</div>";
    }

    // === Per-sprint capacity limits ===

    /** @return array<int,array{min:float,max:float}> category id => limits, all-zero rows omitted */
    public static function getLimitsForSprint(int $sprintId): array
    {
        global $DB;
        $limits = [];
        if ($sprintId <= 0) {
            return $limits;
        }
        foreach ($DB->request(['FROM' => self::CAPS_TABLE, 'WHERE' => ['plugin_sprint_sprints_id' => $sprintId]]) as $row) {
            $min = (float)$row['min_percent'];
            $max = (float)$row['max_percent'];
            if ($min > 0 || $max > 0) {
                $limits[(int)$row['plugin_sprint_sprintcategories_id']] = ['min' => $min, 'max' => $max];
            }
        }
        return $limits;
    }

    /**
     * Per-sprint limits with the configured per-category defaults filled in
     * for categories that have no explicit row for this sprint.
     *
     * @return array<int,array{min:float,max:float}> category id => limits
     */
    public static function getEffectiveLimitsForSprint(int $sprintId): array
    {
        return self::getLimitsForSprint($sprintId) + Config::getCategoryDefaultLimits();
    }

    /** Upsert min/max for one category; both 0 clears the row. */
    public static function setLimits(int $sprintId, int $categoryId, float $minPercent, float $maxPercent): bool
    {
        global $DB;
        if ($sprintId <= 0 || $categoryId <= 0) {
            return false;
        }
        $minPercent = max(0.0, min(9999.0, $minPercent));
        $maxPercent = max(0.0, min(9999.0, $maxPercent));
        if ($minPercent > 0 && $maxPercent > 0 && $minPercent > $maxPercent) {
            [$minPercent, $maxPercent] = [$maxPercent, $minPercent];
        }
        $existing = $DB->request([
            'FROM'  => self::CAPS_TABLE,
            'WHERE' => [
                'plugin_sprint_sprints_id'          => $sprintId,
                'plugin_sprint_sprintcategories_id' => $categoryId,
            ],
        ])->current();

        if ($existing === null) {
            if ($minPercent <= 0 && $maxPercent <= 0) {
                return true;
            }
            return (bool)$DB->insert(self::CAPS_TABLE, [
                'plugin_sprint_sprints_id'          => $sprintId,
                'plugin_sprint_sprintcategories_id' => $categoryId,
                'min_percent'                       => $minPercent,
                'max_percent'                       => $maxPercent,
                'date_mod'                          => date('Y-m-d H:i:s'),
            ]);
        }
        if ($minPercent <= 0 && $maxPercent <= 0) {
            return (bool)$DB->delete(self::CAPS_TABLE, ['id' => (int)$existing['id']]);
        }
        return (bool)$DB->update(self::CAPS_TABLE, [
            'min_percent' => $minPercent,
            'max_percent' => $maxPercent,
            'date_mod'    => date('Y-m-d H:i:s'),
        ], ['id' => (int)$existing['id']]);
    }
}
