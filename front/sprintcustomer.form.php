<?php

/**
 * Customer form handler.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

use GlpiPlugin\Sprint\SprintCustomer;
use GlpiPlugin\Sprint\SprintRetainer;

if (!GlpiPlugin\Sprint\SprintCustomer::canViewCredits()) {
    Html::displayRightError();
}

$customer = new SprintCustomer();

if (isset($_POST['delete_retainer'])) {
    // The rules table sits inside the customer form; its remove buttons are
    // named submits that reach here before any update.
    $customer->check((int)$_POST['id'], UPDATE);
    $rule = new SprintRetainer();
    if (
        $rule->getFromDB((int)$_POST['delete_retainer'])
        && (int)$rule->fields['plugin_sprint_sprintcustomers_id'] === (int)$_POST['id']
    ) {
        $rule->delete(['id' => (int)$_POST['delete_retainer']], true);
    }
    Html::back();
} elseif (isset($_POST['add'])) {
    $customer->check(-1, CREATE, $_POST);
    $customer->add($_POST);
    Html::back();
} elseif (isset($_POST['update'])) {
    $customer->check((int)$_POST['id'], UPDATE);
    $customer->update($_POST);
    SprintRetainer::applyPostedRules((int)$_POST['id'], $_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $customer->check((int)$_POST['id'], PURGE);
    $customer->delete($_POST, true);
    $customer->redirectToList();
} else {
    Html::header(
        SprintCustomer::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'helpdesk',
        'GlpiPlugin\Sprint\Sprint',
        'credits'
    );
    $customer->display(['id' => (int)($_GET['id'] ?? -1)]);
    Html::footer();
}
