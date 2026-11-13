<?php

/**
 * Dashboard health page (443), milestone 1 setup only (M-038).
 *
 * Shows that Apache, TLS, the web CA certificate and PHP-FPM work.
 * It reads no database and no secret. Replaced by the dashboard in milestone 5.
 *
 * reboot_required: Ubuntu's flag file after an update that needs a reboot;
 * nothing reboots on its own (M-039, MS-OPS-05).
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode([
    'status' => 'ok',
    'service' => 'dashboard',
    'time' => gmdate('Y-m-d\TH:i:s\Z'),
    'tls_protocol' => $_SERVER['SSL_PROTOCOL'] ?? null,
    'reboot_required' => is_file('/run/reboot-required'),
], JSON_UNESCAPED_SLASHES) . "\n";
