<?php

/**
 * Profile rights form handler for Sprint plugin
 */

include(dirname(__DIR__, 3) . '/inc/includes.php');

Session::checkRight('profile', UPDATE);

if (isset($_POST['update_sprint_rights'])) {
    global $DB;

    $profileId = (int)($_POST['profiles_id'] ?? 0);
    if ($profileId <= 0) {
        Html::back();
    }

    $rights = GlpiPlugin\Sprint\Profile::getAllRights();

    foreach ($rights as $right) {
        $field = $right['field'];
        $value = 0;
        if (isset($_POST[$field]) && is_array($_POST[$field])) {
            foreach ($_POST[$field] as $v) {
                $value |= (int)$v;
            }
        }

        $existing = $DB->request([
            'FROM'  => 'glpi_profilerights',
            'WHERE' => [
                'profiles_id' => $profileId,
                'name'        => $field,
            ],
        ]);

        if (count($existing) > 0) {
            $DB->update('glpi_profilerights', [
                'rights' => $value,
            ], [
                'profiles_id' => $profileId,
                'name'        => $field,
            ]);
        } else {
            $DB->insert('glpi_profilerights', [
                'profiles_id' => $profileId,
                'name'        => $field,
                'rights'      => $value,
            ]);
        }
    }

    // Reload rights in session if editing own profile
    if ($profileId == $_SESSION['glpiactiveprofile']['id']) {
        Session::changeProfile($profileId);
    }

    Session::addMessageAfterRedirect(__('Rights saved successfully', 'sprint'));
}

Html::back();
