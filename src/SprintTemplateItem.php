<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;
use CommonGLPI;
use Html;
use Session;
use Dropdown;

/**
 * SprintTemplateItem - Default backlog items for a sprint template
 */
class SprintTemplateItem extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_sprint';

    public static function getTypeName($nb = 0): string
    {
        return _n('Template Item', 'Template Items', $nb, 'sprint');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof SprintTemplate) {
            $count = countElementsInTable(
                self::getTable(),
                ['plugin_sprint_sprinttemplates_id' => $item->getID()]
            );
            return self::createTabEntry(__('Items', 'sprint'), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof SprintTemplate) {
            self::showForTemplate($item);
            return true;
        }
        return false;
    }

    public static function showForTemplate(SprintTemplate $template): void
    {
        $ID      = $template->getID();
        $canedit = SprintTemplate::canUpdate();

        $priorities = [
            1 => __('Very low'), 2 => __('Low'), 3 => __('Medium'),
            4 => __('High'), 5 => __('Very high'),
        ];

        if ($canedit) {
            echo "<div class='center'>";
            echo "<form method='post' action='" . static::getFormURL() . "'>";
            echo Html::hidden('plugin_sprint_sprinttemplates_id', ['value' => $ID]);

            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_2'><th colspan='6'>" .
                __('Add a default item', 'sprint') . "</th></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Name') . "</td>";
            echo "<td>" . Html::input('name', ['size' => 30]) . "</td>";
            echo "<td>" . __('Story Points', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showNumber('story_points', ['value' => 0, 'min' => 0, 'max' => 100]);
            echo "</td>";
            echo "<td>" . __('Priority') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('priority', $priorities, ['value' => 3]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Description') . "</td>";
            echo "<td colspan='3'><textarea name='description' rows='2' cols='60'></textarea></td>";
            echo "<td colspan='2'>";
            echo Html::submit(__('Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";

            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        // List items
        $item  = new self();
        $items = $item->find(
            ['plugin_sprint_sprinttemplates_id' => $ID],
            ['sort_order ASC', 'priority DESC']
        );

        echo "<div class='center'><table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Name') . "</th>";
        echo "<th>" . __('Priority') . "</th>";
        echo "<th>" . __('Story Points', 'sprint') . "</th>";
        echo "<th>" . __('Description') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($items) === 0) {
            $cols = $canedit ? 5 : 4;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('No default items', 'sprint') . "</td></tr>";
        }

        foreach ($items as $row) {
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . htmlescape($row['name']) . "</td>";
            echo "<td>" . ($priorities[$row['priority']] ?? $row['priority']) . "</td>";
            echo "<td class='center'>" . (int)$row['story_points'] . "</td>";
            echo "<td>" . htmlescape(mb_strimwidth($row['description'] ?? '', 0, 80, '...')) . "</td>";
            if ($canedit) {
                echo "<td class='center'>";
                echo "<form method='post' action='" . static::getFormURL() . "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::submit(__('Delete'), [
                    'name'    => 'purge',
                    'class'   => 'btn btn-sm btn-outline-danger',
                    'confirm' => __('Confirm deletion?'),
                ]);
                Html::closeForm();
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</table></div>";
    }
}
