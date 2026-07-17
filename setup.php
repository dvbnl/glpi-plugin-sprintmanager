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

use Glpi\Plugin\Hooks;

define('PLUGIN_SPRINT_VERSION', '1.1.5');
define('PLUGIN_SPRINT_MIN_GLPI', '10.0.0');
define('PLUGIN_SPRINT_MAX_GLPI', '11.99.99');

// Polyfill htmlescape() (added mid-GLPI-10) for older GLPI 10 installs.
if (!function_exists('htmlescape')) {
    function htmlescape(?string $string): string
    {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/**
 * @return array
 */
function plugin_version_sprint(): array
{
    return [
        'name'           => __('SprintManager - Agile/Scrum Management', 'sprint'),
        'version'        => PLUGIN_SPRINT_VERSION,
        'author'         => 'DVBNL',
        'license'        => 'GPLv3',
        'homepage'       => 'https://github.com/dvbnl/glpi-plugin-sprintmanager',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_SPRINT_MIN_GLPI,
                'max' => PLUGIN_SPRINT_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.1',
            ],
        ],
    ];
}

/**
 * @return void
 */
function plugin_init_sprint(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['sprint'] = true;

    // "Configure" wrench icon in the Plugins list → opens the settings page.
    $PLUGIN_HOOKS['config_page']['sprint'] = 'front/config.php';

    // Assets: public/ on GLPI 11, css/js/ on GLPI 10. Use __DIR__ (not a
    // hardcoded plugins/ path) so it also resolves under marketplace/.
    // Don't append `?v=...`: GLPI treats the hook value as a literal
    // filename and adds its own cache-busting fingerprint.
    if (is_dir(__DIR__ . '/public/')) {
        $PLUGIN_HOOKS['add_css']['sprint'] = 'public/sprint.css';
        $PLUGIN_HOOKS['add_javascript']['sprint'] = 'public/sprint.js';
    } else {
        $PLUGIN_HOOKS['add_css']['sprint'] = 'css/sprint.css';
        $PLUGIN_HOOKS['add_javascript']['sprint'] = 'js/sprint.js';
    }

    // Menu entries under Assistance (helpdesk): SprintManager and Backlog.
    $PLUGIN_HOOKS['menu_toadd']['sprint'] = [
        'helpdesk' => [
            'GlpiPlugin\Sprint\Sprint',
            'GlpiPlugin\Sprint\Backlog',
        ],
    ];

    // Register profile tab for RBAC
    Plugin::registerClass(
        'GlpiPlugin\Sprint\Profile',
        ['addtabon' => ['Profile']]
    );

    // Register plugin settings tab on Config (Setup > General)
    Plugin::registerClass(
        'GlpiPlugin\Sprint\Config',
        ['addtabon' => ['Config']]
    );

    // Register classes
    Plugin::registerClass(
        'GlpiPlugin\Sprint\Sprint',
        ['addtabon' => []]
    );

    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintMember',
        ['addtabon' => ['GlpiPlugin\Sprint\Sprint']]
    );

    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintItem',
        ['addtabon' => ['GlpiPlugin\Sprint\Sprint']]
    );

    // Kanban board: virtual tab on Sprint grouping items by status.
    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintBoard',
        ['addtabon' => ['GlpiPlugin\Sprint\Sprint']]
    );

    // Fastlane: virtual tab on Sprint listing fastlane items.
    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintFastlane',
        ['addtabon' => ['GlpiPlugin\Sprint\Sprint']]
    );

    // Fastlane member junction: tab on SprintItem (only when is_fastlane).
    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintFastlaneMember',
        ['addtabon' => ['GlpiPlugin\Sprint\SprintItem']]
    );

    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintItemDependency',
        ['addtabon' => ['GlpiPlugin\Sprint\SprintItem']]
    );

    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintMeeting',
        ['addtabon' => ['GlpiPlugin\Sprint\Sprint']]
    );

    // Ticket/Change/ProjectTask tabs only on the GLPI objects (not on Sprint)
    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintTicket',
        ['addtabon' => ['Ticket']]
    );

    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintChange',
        ['addtabon' => ['Change']]
    );

    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintProjectTask',
        ['addtabon' => ['ProjectTask']]
    );

    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintProblem',
        ['addtabon' => ['Problem']]
    );

    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintDashboard',
        ['addtabon' => ['GlpiPlugin\Sprint\Sprint']]
    );

    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintAudit',
        ['addtabon' => ['GlpiPlugin\Sprint\Sprint']]
    );

    // Registered after SprintAudit so it appears just below it in the tab rail.
    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintExport',
        ['addtabon' => ['GlpiPlugin\Sprint\Sprint']]
    );

    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintTemplate',
        ['addtabon' => []]
    );

    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintTemplateMember',
        ['addtabon' => ['GlpiPlugin\Sprint\SprintTemplate']]
    );

    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintTemplateItem',
        ['addtabon' => ['GlpiPlugin\Sprint\SprintTemplate']]
    );

    Plugin::registerClass(
        'GlpiPlugin\Sprint\SprintTemplateMeeting',
        ['addtabon' => ['GlpiPlugin\Sprint\SprintTemplate']]
    );

    // Item actions hooks
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['sprint'] = [
        'Ticket'      => ['GlpiPlugin\Sprint\SprintTicket', 'cleanForItem'],
        'Change'      => ['GlpiPlugin\Sprint\SprintChange', 'cleanForItem'],
        'Problem'     => ['GlpiPlugin\Sprint\SprintProblem', 'cleanForItem'],
        'ProjectTask' => ['GlpiPlugin\Sprint\SprintProjectTask', 'cleanForItem'],
    ];

    // Rights
    $PLUGIN_HOOKS['rights']['sprint'] = 'GlpiPlugin\Sprint\Profile';
}
