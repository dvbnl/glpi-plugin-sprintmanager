<?php

namespace GlpiPlugin\Sprint;

/**
 * Translated runtime messages for js/sprint.js. The static script cannot call
 * gettext, so the page emits `window.sprintI18n` once and sprint.js reads it
 * through window.sprintT(key, fallback). Missing keys fall back to English.
 */
final class SprintI18n
{
    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'networkError'        => __('Network error', 'sprint'),
            'requestFailed'       => __('Request failed', 'sprint'),
            'assigned'            => __('Assigned', 'sprint'),
            'assignFailed'        => __('Could not assign the item', 'sprint'),
            'selectSprintFirst'   => __('Select a sprint first', 'sprint'),
            'meetingUpdateFailed' => __('Could not update the meeting', 'sprint'),
            'saveFailed'          => __('Could not save', 'sprint'),
            'voteFailed'          => __('Could not vote', 'sprint'),
            'updateFailed'        => __('Could not update', 'sprint'),
            'anonymous'           => __('Anonymous', 'sprint'),
            'vote'                => __('Vote', 'sprint'),
            'unvote'              => __('Remove my vote', 'sprint'),
            'done'                => __('Done', 'sprint'),
            'open'                => __('Open', 'sprint'),
            'toggleDone'          => __('Toggle done', 'sprint'),
            // %d = planned minutes of the meeting phase
            'minutes'             => __('%d min', 'sprint'),
            'confirmTitle'        => __('Please confirm', 'sprint'),
            'confirm'             => __('Confirm', 'sprint'),
            'cancel'              => __('Cancel'),
            'showLess'            => __('Show fewer tags', 'sprint'),
            // %d = number of tags folded away
            'showMore'            => __('Show %d more tags', 'sprint'),
        ];
    }

    /** Emit the translation map once per page. */
    public static function render(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        $json = json_encode(self::messages(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        echo "<script>window.sprintI18n = Object.assign(window.sprintI18n || {}, " . $json . ");</script>";
    }
}
