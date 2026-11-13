<?php

/**
 * Agent API health page (8443), milestone 1 setup only (M-038).
 *
 * Shows that Apache, TLS 1.3, the client-certificate request and PHP-FPM work.
 * It reads no database and no secret. Replaced by the real API in milestone 2.
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode([
    'status' => 'ok',
    'service' => 'agent-api',
    'time' => gmdate('Y-m-d\TH:i:s\Z'),
    'tls_protocol' => $_SERVER['SSL_PROTOCOL'] ?? null,
    // NONE without a certificate, SUCCESS with one from the agent CA.
    'client_verify' => $_SERVER['SSL_CLIENT_VERIFY'] ?? null,
    'client_cn' => $_SERVER['SSL_CLIENT_S_DN_CN'] ?? null,
], JSON_UNESCAPED_SLASHES) . "\n";
