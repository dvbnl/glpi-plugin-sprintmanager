<?php

namespace GlpiPlugin\Sprint;

/**
 * One icon per action, so the same action looks the same on every screen and
 * no icon stands for two different things. PHP and Twig read the same source
 * (Twig: `constant('GlpiPlugin\\Sprint\\SprintIcon::EDIT')`).
 */
final class SprintIcon
{
    const EDIT      = 'fas fa-pen';
    const BACKLOG   = 'fas fa-inbox';
    const WITHDRAW  = 'fas fa-rotate-left';
    const CARRYOVER = 'fas fa-forward';
    const REJECT    = 'fas fa-xmark';
    const DELETE    = 'fas fa-trash';

    /**
     * Decorative `<i>` for an action: hidden from assistive technology, the
     * button or link around it carries the label.
     */
    public static function html(string $icon, string $extraClass = ''): string
    {
        $class = trim($icon . ' ' . $extraClass);
        return "<i class='" . htmlescape($class) . "' aria-hidden='true'></i>";
    }
}
