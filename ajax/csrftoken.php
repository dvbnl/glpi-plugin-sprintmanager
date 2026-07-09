<?php

/**
 * Mint a fresh one-shot CSRF token for AJAX calls. GLPI 11 tokens are
 * single-use, so modals posting multiple times per page must fetch a new one
 * before each POST. checkCSRF is intentionally not called here — this mints.
 */

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkLoginUser();

echo json_encode([
    'token' => Session::getNewCSRFToken(),
]);
