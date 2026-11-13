<?php

/**
 * Dashboard health page (443), milestone 1 setup only (M-038).
 *
 * Shows that Apache, TLS, the web CA certificate and PHP-FPM work.
 * It reads no database and no secret. Replaced by the dashboard in milestone 5.
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode([
    'status' => 'ok',
    'service' => 'dashboard',
    'time' => gmdate('Y-m-d\TH:i:s\Z'),
    'tls_protocol' => $_SERVER['SSL_PROTOCOL'] ?? null,
], JSON_UNESCAPED_SLASHES) . "\n";
