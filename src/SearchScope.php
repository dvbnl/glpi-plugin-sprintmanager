<?php

namespace GlpiPlugin\Sprint;

use DBmysql;

/**
 * Entity scoping for GLPI's generic search engine (ajax/search.php,
 * front/report.dynamic.php, saved searches, dashboards).
 *
 * The engine only restricts on entities when the searched table has its own
 * entities_id. Most plugin tables hang off a parent that carries it — a
 * sprint, a sprint item, a template or a customer — so without this hook a
 * search returns rows of every entity, even though the forms and ajax
 * endpoints refuse them. Called from plugin_sprint_addDefaultWhere().
 */
class SearchScope
{
    /**
     * Global configuration lists (no entity by design), searchable as-is.
     */
    private const UNSCOPED = [
        SprintCategory::class,
    ];

    /**
     * Link column → callable returning the parent ids the session may see.
     * The first column the table has wins.
     */
    private static function parents(): array
    {
        return [
            'plugin_sprint_sprints_id'         => [self::class, 'visibleSprintIds'],
            'plugin_sprint_sprintitems_id'     => [self::class, 'visibleItemIds'],
            'plugin_sprint_sprinttemplates_id' => [self::class, 'visibleTemplateIds'],
            'plugin_sprint_sprintcustomers_id' => [SprintCustomer::class, 'visibleIds'],
        ];
    }

    /**
     * SQL condition for the search engine, '' when the table is entity-aware
     * itself (or not ours). Unknown shapes fail closed.
     */
    public static function defaultWhere(string $itemtype): string
    {
        global $DB;

        if (
            !str_starts_with($itemtype, __NAMESPACE__ . '\\')
            || !is_subclass_of($itemtype, \CommonDBTM::class)
        ) {
            return '';
        }
        $table = $itemtype::getTable();
        if (!($DB instanceof DBmysql) || !$DB->tableExists($table) || $DB->fieldExists($table, 'entities_id', false)) {
            return '';
        }

        foreach (self::parents() as $column => $resolver) {
            if (!$DB->fieldExists($table, $column, false)) {
                continue;
            }
            $ids = array_map('intval', $resolver());
            // Backlog rows (no sprint) are global by design, as on the
            // plugin's own pages.
            if ($column === 'plugin_sprint_sprints_id') {
                $ids[] = 0;
            }
            $ids = array_values(array_unique($ids)) ?: [-1];
            return '`' . $table . '`.`' . $column . '` IN (' . implode(',', $ids) . ')';
        }

        // Plugin table without entity or known parent: nothing to scope on,
        // and nothing the search engine should hand out either.
        return in_array($itemtype, self::UNSCOPED, true) ? '' : '1 = 0';
    }

    /** @var array<string,int[]> per-request cache */
    private static array $cache = [];

    /** @return int[] */
    private static function visibleIds(string $class): array
    {
        global $DB;

        if (!isset(self::$cache[$class])) {
            $table = $class::getTable();
            $ids   = [];
            foreach ($DB->request([
                'SELECT' => 'id',
                'FROM'   => $table,
                'WHERE'  => getEntitiesRestrictCriteria($table, '', '', 'auto'),
            ]) as $row) {
                $ids[] = (int)$row['id'];
            }
            self::$cache[$class] = $ids;
        }
        return self::$cache[$class];
    }

    /** @return int[] */
    public static function visibleSprintIds(): array
    {
        return self::visibleIds(Sprint::class);
    }

    /** @return int[] */
    public static function visibleTemplateIds(): array
    {
        return self::visibleIds(SprintTemplate::class);
    }

    /** @return int[] sprint items of a visible sprint, or of the backlog */
    public static function visibleItemIds(): array
    {
        global $DB;

        if (!isset(self::$cache[SprintItem::class])) {
            $ids = [];
            foreach ($DB->request([
                'SELECT' => 'id',
                'FROM'   => SprintItem::getTable(),
                'WHERE'  => ['plugin_sprint_sprints_id' => array_merge([0], self::visibleSprintIds())],
            ]) as $row) {
                $ids[] = (int)$row['id'];
            }
            self::$cache[SprintItem::class] = $ids;
        }
        return self::$cache[SprintItem::class];
    }
}
