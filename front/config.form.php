<?php

/**
 * SprintManager settings form handler (Setup > General > SprintManager tab).
 * Every settings group posts its own form; the handler saves that group,
 * names it in the result message and returns to its card.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

use GlpiPlugin\Sprint\Config;

Session::checkRight('config', UPDATE);

/** Back to the page the form was on, scrolled to the saved group. */
$backTo = static function (string $anchor): void {
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer === '') {
        Html::back();
    }
    Html::redirect(preg_replace('/#.*$/', '', $referer) . '#sprint-cfg-' . $anchor);
};

if (isset($_POST['save_sprint_categories'])) {
    GlpiPlugin\Sprint\SprintCategory::saveFromConfigForm($_POST);
    Config::markSaved('categories');
    Session::addMessageAfterRedirect(__('Backlog categories saved', 'sprint'));
    $backTo('tags');
}

if (isset($_POST['save_sprint_credit_managers'])) {
    Config::saveCreditManagers($_POST);
    Config::markSaved('credits');
    Session::addMessageAfterRedirect(__('Credit managers saved', 'sprint'));
    $backTo('credits');
}

if (isset($_POST['update_sprint_config'])) {
    $sections = Config::getSettingsSections();
    $section  = (string)($_POST['sprint_config_section'] ?? '');
    Config::saveConfig($_POST);
    if (isset($sections[$section])) {
        Config::markSaved($section);
        Session::addMessageAfterRedirect(sprintf(__('%s settings saved', 'sprint'), $sections[$section][0]));
        $backTo($section);
    }
    Session::addMessageAfterRedirect(__('SprintManager settings saved', 'sprint'));
    Html::back();
}

Html::back();
