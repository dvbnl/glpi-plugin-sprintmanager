<?php

namespace GlpiPlugin\Sprint;

use CommonDBRelation;
use CommonGLPI;
use Html;
use Session;
use Ticket;
use Dropdown;

/**
 * SprintTicket - Link table between Sprints and Tickets
 */
class SprintTicket extends CommonDBRelation
{
    public static $itemtype_1 = 'GlpiPlugin\Sprint\Sprint';
    public static $items_id_1 = 'plugin_sprint_sprints_id';
    public static $itemtype_2 = 'Ticket';
    public static $items_id_2 = 'tickets_id';

    public static $rightname  = 'plugin_sprint_sprint';

    public static function getTypeName($nb = 0): string
    {
        return _n('Linked Ticket', 'Linked Tickets', $nb, 'sprint');
    }

    public static function getIcon(): string
    {
        return 'fas fa-ticket-alt';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Ticket) {
            $count = countElementsInTable(
                self::getTable(),
                ['tickets_id' => $item->getID()]
            );
            return self::createTabEntry(__('Sprints', 'sprint'), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof Ticket) {
            self::showForTicket($item);
            return true;
        }
        return false;
    }

    /**
     * Show linked tickets for a sprint
     */
    public static function showForSprint(Sprint $sprint): void
    {
        $ID      = $sprint->getID();
        $canedit = Sprint::canUpdate();

        // Add form
        if ($canedit) {
            $memberOptions = SprintMember::getSprintMemberOptions($ID);

            echo "<div class='center'>";
            echo "<form method='post' action='" . static::getFormURL() . "'>";
            echo Html::hidden('plugin_sprint_sprints_id', ['value' => $ID]);

            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_2'><th colspan='4'>" .
                __('Link a ticket', 'sprint') . "</th></tr>";
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Ticket') . "</td>";
            echo "<td>";
            Ticket::dropdown([
                'name'      => 'tickets_id',
                'condition'  => [],
                'displaywith' => ['id'],
            ]);
            echo "</td>";
            echo "<td>" . __('Assign to member', 'sprint') . "</td>";
            echo "<td>";
            Dropdown::showFromArray('users_id', $memberOptions);
            echo "</td></tr>";
            echo "<tr class='tab_bg_1'><td colspan='4' class='center'>";
            echo Html::submit(__('Link'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
            echo "</div>";
        }

        // List linked tickets
        $link    = new self();
        $links   = $link->find(['plugin_sprint_sprints_id' => $ID]);

        echo "<div class='center'><table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('ID') . "</th>";
        echo "<th>" . __('Title') . "</th>";
        echo "<th>" . __('Status') . "</th>";
        echo "<th>" . __('Priority') . "</th>";
        echo "<th>" . __('Sprint Member', 'sprint') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($links) === 0) {
            $cols = $canedit ? 6 : 5;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('No tickets linked', 'sprint') . "</td></tr>";
        }

        foreach ($links as $row) {
            $ticket = new Ticket();
            if (!$ticket->getFromDB($row['tickets_id'])) {
                continue;
            }

            $assignedMember = ((int)$row['users_id'] > 0)
                ? getUserName($row['users_id'])
                : '<span style="color:#999;">' . __('Unassigned', 'sprint') . '</span>';

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . $ticket->getID() . "</td>";
            echo "<td><a href='" . Ticket::getFormURLWithID($ticket->getID()) . "'>" .
                htmlescape($ticket->fields['name']) . "</a></td>";
            echo "<td>" . Ticket::getStatus($ticket->fields['status']) . "</td>";
            echo "<td>" . Ticket::getPriorityName($ticket->fields['priority']) . "</td>";
            echo "<td>" . $assignedMember . "</td>";
            if ($canedit) {
                echo "<td class='center'>";
                echo "<form method='post' action='" . static::getFormURL() .
                    "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::submit(__('Unlink', 'sprint'), [
                    'name'    => 'purge',
                    'class'   => 'btn btn-sm btn-outline-danger',
                    'confirm' => __('Remove this link?', 'sprint'),
                ]);
                Html::closeForm();
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</table></div>";
    }

    /**
     * Show linked sprints on a ticket's tab
     */
    public static function showForTicket(Ticket $ticket): void
    {
        $ticketID = $ticket->getID();
        $canedit  = Sprint::canUpdate();

        // Add form
        if ($canedit) {
            echo "<div class='center'>";
            echo "<form method='post' action='" . static::getFormURL() . "'>";
            echo Html::hidden('tickets_id', ['value' => $ticketID]);

            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_2'><th colspan='3'>" .
                __('Link to a sprint', 'sprint') . "</th></tr>";
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Sprint', 'sprint') . "</td>";
            echo "<td>";
            Sprint::dropdown(['name' => 'plugin_sprint_sprints_id']);
            echo "</td>";
            echo "<td>";
            echo Html::submit(__('Link'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
            echo "</div>";

            Backlog::showAddToBacklogButton('Ticket', $ticketID);
        }

        // List
        $link  = new self();
        $links = $link->find(['tickets_id' => $ticketID]);

        echo "<div class='center'><table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Sprint', 'sprint') . "</th>";
        echo "<th>" . __('Status') . "</th>";
        echo "<th>" . __('Start date', 'sprint') . "</th>";
        echo "<th>" . __('End date', 'sprint') . "</th>";
        if ($canedit) {
            echo "<th>" . __('Actions') . "</th>";
        }
        echo "</tr>";

        if (count($links) === 0) {
            $cols = $canedit ? 5 : 4;
            echo "<tr class='tab_bg_1'><td colspan='{$cols}' class='center'>" .
                __('Not linked to any sprint', 'sprint') . "</td></tr>";
        }

        $statuses = Sprint::getAllStatuses();
        foreach ($links as $row) {
            $sprint = new Sprint();
            if (!$sprint->getFromDB($row['plugin_sprint_sprints_id'])) {
                continue;
            }

            echo "<tr class='tab_bg_1'>";
            echo "<td><a href='" . Sprint::getFormURLWithID($sprint->getID()) . "'>" .
                htmlescape($sprint->fields['name']) . "</a></td>";
            echo "<td>" . ($statuses[$sprint->fields['status']] ?? $sprint->fields['status']) . "</td>";
            echo "<td>" . Html::convDateTime($sprint->fields['date_start']) . "</td>";
            echo "<td>" . Html::convDateTime($sprint->fields['date_end']) . "</td>";
            if ($canedit) {
                echo "<td class='center'>";
                echo "<form method='post' action='" . static::getFormURL() .
                    "' style='display:inline;'>";
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::submit(__('Unlink', 'sprint'), [
                    'name'    => 'purge',
                    'class'   => 'btn btn-sm btn-outline-danger',
                    'confirm' => __('Remove this link?', 'sprint'),
                ]);
                Html::closeForm();
                echo "</td>";
            }
            echo "</tr>";
        }

        echo "</table></div>";
    }

    public function post_addItem()
    {
        $ticket = new Ticket();
        if ($ticket->getFromDB($this->fields['tickets_id'])) {
            $item = new SprintItem();
            $item->add([
                'plugin_sprint_sprints_id' => $this->fields['plugin_sprint_sprints_id'],
                'name'                     => $ticket->fields['name'],
                'itemtype'                 => 'Ticket',
                'items_id'                 => $this->fields['tickets_id'],
                'status'                   => SprintItem::STATUS_TODO,
                'priority'                 => (int)($ticket->fields['priority'] ?? 3),
                'users_id'                 => (int)($this->fields['users_id'] ?? 0),
            ]);
        }
    }

    public function post_purgeItem()
    {
        $item = new SprintItem();
        $items = $item->find([
            'plugin_sprint_sprints_id' => $this->fields['plugin_sprint_sprints_id'],
            'itemtype'                 => 'Ticket',
            'items_id'                 => $this->fields['tickets_id'],
        ]);
        foreach ($items as $row) {
            $item->delete(['id' => $row['id']], 1);
        }
    }

    /**
     * Clean relation when a ticket is purged
     */
    public static function cleanForItem(\CommonDBTM $item): void
    {
        $temp = new self();
        $temp->deleteByCriteria(['tickets_id' => $item->getID()]);
    }
}
