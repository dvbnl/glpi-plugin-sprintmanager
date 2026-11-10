<?php

namespace GlpiPlugin\Sprint;

/**
 * Small shared pieces of page chrome, so every card reads the same.
 */
final class SprintUi
{
    /**
     * The head of a card: icon and title on the left, actions (buttons, a
     * legend, a picker) on the right, and one muted line of explanation below.
     * Echo it as the first thing inside `<div class='card-body'>`.
     *
     * @param string $icon        Font Awesome classes, for example 'fas fa-chart-line'
     * @param string $title       plain text, escaped here
     * @param string $hint        plain text shown under the title, '' for none
     * @param string $actionsHtml trusted HTML placed right of the title, '' for none
     */
    public static function cardHeader(string $icon, string $title, string $hint = '', string $actionsHtml = ''): string
    {
        $out = "<div class='sprint-card-head'>"
            . "<h3 class='card-title sprint-card-title'>"
            . ($icon !== '' ? "<i class='" . htmlescape($icon) . " me-2' aria-hidden='true'></i>" : '')
            . htmlescape($title) . "</h3>";
        if ($actionsHtml !== '') {
            $out .= "<div class='sprint-card-actions'>" . $actionsHtml . "</div>";
        }
        $out .= "</div>";
        if ($hint !== '') {
            $out .= "<div class='sprint-card-hint'>" . htmlescape($hint) . "</div>";
        }
        return $out;
    }
}
