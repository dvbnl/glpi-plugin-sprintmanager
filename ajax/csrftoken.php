<?php

/**
 * Mint a fresh one-shot CSRF token for AJAX calls.
 *
 * GLPI 11 CSRF tokens are single-use: the token rendered into a page form
 * is consumed by the first successful Session::checkCSRF() call. Modals
 * that post multiple times per page load therefore need to fetch a new
 * token before every POST — which is what this endpoint is for.
 */

include(dirname(__DIR__, 3) . '/inc/includes.php');

header('Content-Type: application/json');

Session::checkLoginUser();

echo json_encode([
    'token' => Session::getNewCSRFToken(),
]);
