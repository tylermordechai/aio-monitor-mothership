<?php
/**
 * The agent CA signing service (ingest-api.md §2.1 step 6; MS-SEC-02).
 *
 * Not run by hand. systemd starts one copy per connection on
 * /run/aio-signer.sock (setup/templates/aio-signer.socket, Accept=yes) as the
 * system user aio-signer, the only user besides root that can read the agent
 * CA key. The connection is this process's stdin and stdout: one JSON request
 * in, one JSON answer out (Aio\Pki\SignerProtocol). Log lines go to stderr,
 * which is the journal: agent ID, serial and outcome, never the CSR or the key
 * (MS-SEC-10).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aio\Pki\OpensslTool;
use Aio\Pki\Signer;
use Aio\Pki\SignerProtocol;

const CA_DIR = '/etc/aio-mothership/pki/agent-ca';

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "aio-signer: refusing to run as root; systemd runs it as aio-signer\n");
    exit(2);
}

$raw = (string) stream_get_contents(STDIN, SignerProtocol::MAX_REQUEST_BYTES + 1);
$signer = new Signer(
    CA_DIR . '/ca.crt',
    CA_DIR . '/ca.key',
    new OpensslTool(),
    sys_get_temp_dir(),
);
$log = static function (string $line): void {
    fwrite(STDERR, 'aio-signer: ' . $line . "\n");
};
fwrite(STDOUT, SignerProtocol::handle($raw, $signer, $log));
exit(0);
