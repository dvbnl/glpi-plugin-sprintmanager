<?php

/**
 * Credit booking handler: add/delete rows in a customer's credit ledger.
 * Always posted from the customer's Credits tab, so it redirects back.
 * Each action carries its own right: CREATE books, UPDATE edits, PURGE
 * deletes permanently (a named credit manager holds all three).
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

use GlpiPlugin\Sprint\SprintCredit;
use GlpiPlugin\Sprint\SprintCustomer;

if (!SprintCustomer::canViewCredits()) {
    Html::displayRightError();
}

$credit = new SprintCredit();

/** A booking may only touch a customer the session can actually see. */
function plugin_sprint_credit_customer_allowed(int $customerId): bool
{
    return $customerId > 0 && SprintCustomer::isVisible($customerId);
}

/** The customer a stored booking belongs to, 0 when the row is unknown. */
function plugin_sprint_credit_owner(int $creditId): int
{
    $row = new SprintCredit();
    return $creditId > 0 && $row->getFromDB($creditId)
        ? (int)$row->fields['plugin_sprint_sprintcustomers_id']
        : 0;
}

if (isset($_POST['add'])) {
    if (
        !SprintCredit::canCreate()
        || !plugin_sprint_credit_customer_allowed((int)($_POST['plugin_sprint_sprintcustomers_id'] ?? 0))
    ) {
        Html::displayRightError();
    }
    $credit->add($_POST);
} elseif (isset($_POST['purge'])) {
    $id = (int)($_POST['id'] ?? 0);
    if (!SprintCredit::canPurge() || !plugin_sprint_credit_customer_allowed(plugin_sprint_credit_owner($id))) {
        Html::displayRightError();
    }
    $credit->delete(['id' => $id], true);
} elseif (isset($_POST['update'])) {
    $id = (int)($_POST['id'] ?? 0);
    // Both the row's current customer and any new one must be reachable.
    $target = (int)($_POST['plugin_sprint_sprintcustomers_id'] ?? plugin_sprint_credit_owner($id));
    if (
        !SprintCredit::canUpdate()
        || !plugin_sprint_credit_customer_allowed(plugin_sprint_credit_owner($id))
        || !plugin_sprint_credit_customer_allowed($target)
    ) {
        Html::displayRightError();
    }
    $credit->update($_POST);
}

Html::back();
