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

// A saved product or folder lands back on the catalogue: that is where the
// next entry is added or the next edit starts. A refused save stays on the
// form so the input can be corrected.
if (isset($_POST['add'])) {
    $product->check(-1, CREATE, $_POST);
    if ($product->add($_POST)) {
        Html::redirect(SprintCreditProduct::getSearchURL());
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $product->check((int)$_POST['id'], UPDATE);
    if ($product->update($_POST)) {
        Html::redirect(SprintCreditProduct::getSearchURL());
    }
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
