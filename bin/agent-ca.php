<?php

/**
 * Prints the agent CA certificate and its SHA-256 fingerprint, the two values
 * the agent installer takes (--ca-cert and --ca-sha256, MSI properties;
 * ingest-api.md §2.1 step 2, M-002, M-059). Both are public; no sudo needed.
 *
 *   php bin/agent-ca.php            # both, with labels
 *   php bin/agent-ca.php --pem      # the PEM only:  > agent-ca.crt
 *   php bin/agent-ca.php --sha256   # the fingerprint only, 64 lower-case hex digits
 *
 * The fingerprint is the SHA-256 of the certificate's DER bytes, the same
 * value as `openssl x509 -fingerprint -sha256` (shown with colons as well)
 * and as fingerprints.txt from setup/07-pki.sh.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aio\Config;
use Aio\Pki\CaExport;

$mode = $argv[1] ?? '';
if (!in_array($mode, ['', '--pem', '--sha256'], true) || count($argv) > 2) {
    fwrite(STDERR, "usage: php bin/agent-ca.php [--pem | --sha256]\n");
    exit(2);
}
$path = Config::load()->string('enrol', 'agent_ca_public');
$raw = @file_get_contents($path);
if ($raw === false) {
    fwrite(STDERR, "can't read $path (made by setup/07-pki.sh)\n");
    exit(1);
}
try {
    $ca = CaExport::read($raw);
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . " ($path)\n");
    exit(1);
}

if ($mode === '--pem') {
    fwrite(STDOUT, $ca['pem']);
} elseif ($mode === '--sha256') {
    fwrite(STDOUT, $ca['sha256'] . "\n");
} else {
    fwrite(STDOUT, "Agent CA: {$ca['subject']}, valid until {$ca['not_after']} (public, not secret)\n"
        . "File:     $path\n\n"
        . "SHA-256:  {$ca['sha256']}\n"
        . "          {$ca['sha256_colons']}\n\n"
        . $ca['pem']);
}
exit(0);
