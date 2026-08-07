<?php

namespace GlpiPlugin\Sprint;

use CommonDBTM;

/**
 * SprintTemplateAvailability - Fixed weekly leave per template member.
 * Materialized into dated sprint availability exceptions when a sprint is
 * created from the template (see SprintTemplate::applyToSprint()).
 */
class SprintTemplateAvailability extends CommonDBTM
{
    public static $rightname = 'plugin_sprint_sprint';

    public static function getTypeName($nb = 0): string
    {
        return _n('Fixed leave', 'Fixed leave', $nb, 'sprint');
    }

    public static function getWeekdays(): array
    {
        return [
            1 => __('Monday'),
            2 => __('Tuesday'),
            3 => __('Wednesday'),
            4 => __('Thursday'),
            5 => __('Friday'),
        ];
    }

    public function prepareInputForAdd($input)
    {
        return $this->normalizeInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->normalizeInput($input);
    }

    private function normalizeInput($input)
    {
        if (isset($input['weekday'])) {
            $input['weekday'] = max(1, min(5, (int)$input['weekday']));
        }
        if (isset($input['availability_percent'])) {
            $input['availability_percent'] = SprintMember::normalizeCapacity($input['availability_percent']);
        }
        return $input;
    }
}
