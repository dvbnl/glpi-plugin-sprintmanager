<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;

/**
 * Request-scoped memo table for the list renderers, which resolve the same
 * linked GLPI object and the same user names once per column per row.
 * Nothing survives the request. Write-then-rerender paths call forget().
 */
final class SprintCache
{
    /** @var array<string, CommonDBTM|null> keyed "Itemtype#id"; null memoizes a miss */
    private static array $objects = [];

    /** @var array<int, string> */
    private static array $userNames = [];

    /** The returned instance is shared — read-only. */
    public static function getObject(string $itemtype, int $itemsId): ?CommonDBTM
    {
        if ($itemtype === '' || $itemsId <= 0 || !class_exists($itemtype)) {
            return null;
        }

        $key = $itemtype . '#' . $itemsId;
        if (!\array_key_exists($key, self::$objects)) {
            $object = new $itemtype();
            if (!($object instanceof CommonDBTM) || !$object->getFromDB($itemsId)) {
                $object = null;
            }
            self::$objects[$key] = $object;
        }

        return self::$objects[$key];
    }

    /** Drop-in for getUserName(), memoized — same result for every input. */
    public static function userName(int $usersId): string
    {
        if (!isset(self::$userNames[$usersId])) {
            self::$userNames[$usersId] = (string)\getUserName($usersId);
        }
        return self::$userNames[$usersId];
    }

    public static function forget(string $itemtype, int $itemsId): void
    {
        unset(self::$objects[$itemtype . '#' . $itemsId]);
    }
}
