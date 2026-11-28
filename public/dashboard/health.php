<?php

/**
 * Dashboard health page (443), milestone 1 setup only (M-038).
 *
 * Shows that Apache, TLS, the web CA certificate and PHP-FPM work.
 * It reads no database and no secret. Kept beside the dashboard (milestone 5)
 * as the plain health check that setup/08 and 10 use; it needs no sign-in.
 *
 * reboot_required: Ubuntu's flag file after an update that needs a reboot;
 * nothing reboots on its own (M-039, MS-OPS-05).
 *
 * agent_crl (M-071, M-068): the state the CRL job left (public, no secret).
 * A CRL that expires within 7 days, has expired, or whose last rebuild
 * failed is listed under warnings; status stays "ok" (this page says the web
 * server works), and milestone 4's health checks will alert on warnings.
 */

declare(strict_types=1);

// 08-apache-php.sh runs before 09 installs vendor/: then there is no CRL state yet.
$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
$crl = ['state' => 'never', 'message' => 'The revocation list has not been built yet.', 'built_at' => null, 'next_update' => null, 'entries' => null];
if (is_file($autoload)) {
    require $autoload;
    $crl = Aio\Pki\CrlTrigger::fromConfig(Aio\Config::load())->status(time());
}

header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode([
    'status' => 'ok',
    'service' => 'dashboard',
    'time' => gmdate('Y-m-d\TH:i:s\Z'),
    'tls_protocol' => $_SERVER['SSL_PROTOCOL'] ?? null,
    'reboot_required' => is_file('/run/reboot-required'),
    'agent_crl' => ['state' => $crl['state'], 'built_at' => $crl['built_at'], 'next_update' => $crl['next_update'], 'entries' => $crl['entries']],
    'warnings' => $crl['state'] === 'ok' ? [] : ['agent_crl: ' . $crl['message']],
], JSON_UNESCAPED_SLASHES) . "\n";
