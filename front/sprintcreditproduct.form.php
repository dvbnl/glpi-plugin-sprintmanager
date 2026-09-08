<?php

/**
 * Credit product form handler.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

use GlpiPlugin\Sprint\SprintCreditProduct;

if (!SprintCreditProduct::canView()) {
    Html::displayRightError();
}

$product = new SprintCreditProduct();

if (isset($_POST['add'])) {
    $product->check(-1, CREATE, $_POST);
    $product->add($_POST);
    Html::back();
} elseif (isset($_POST['update'])) {
    $product->check((int)$_POST['id'], UPDATE);
    $product->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $product->check((int)$_POST['id'], PURGE);
    $product->delete($_POST, true);
    Html::redirect(SprintCreditProduct::getSearchURL());
} else {
    Html::header(
        SprintCreditProduct::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'helpdesk',
        'GlpiPlugin\Sprint\Sprint',
        'credits'
    );
    $product->display(['id' => (int)($_GET['id'] ?? -1)]);
    Html::footer();
}
