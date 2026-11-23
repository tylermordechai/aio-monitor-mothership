<?php

declare(strict_types=1);

namespace Aio\Pki;

/**
 * The enrol and renew endpoints' side of the signing service (MS-SEC-02):
 * the PHP pool never reads the CA key, it asks the signer over the local
 * socket. Only root and the aio-ingest group can open the socket.
 */
final class SignerClient
{
    public const DEFAULT_SOCKET = '/run/aio-signer.sock';

    public function __construct(
        private readonly string $socket = self::DEFAULT_SOCKET,
        private readonly float $timeoutS = 15.0,
    ) {
    }

    /**
     * @return array{certificate: string, serial_hex: string}
     * @throws CsrInvalid the signer refused the CSR
     * @throws SignerUnavailable the signer couldn't be reached or failed
     */
    public function sign(string $csrPem, string $agentId): array
    {
        $conn = @stream_socket_client('unix://' . $this->socket, $errno, $errstr, $this->timeoutS);
        if ($conn === false) {
            throw new SignerUnavailable('could not connect to the signer');
        }
        try {
            stream_set_timeout($conn, (int) ceil($this->timeoutS));
            $req = json_encode(
                ['v' => SignerProtocol::VERSION, 'op' => 'sign', 'agent_id' => $agentId, 'csr' => $csrPem],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            if (@fwrite($conn, $req) !== strlen($req)) {
                throw new SignerUnavailable('could not write to the signer');
            }
            stream_socket_shutdown($conn, STREAM_SHUT_WR);
            $raw = (string) stream_get_contents($conn, SignerProtocol::MAX_ANSWER_BYTES + 1);
            if (stream_get_meta_data($conn)['timed_out']) {
                throw new SignerUnavailable('the signer timed out');
            }
        } finally {
            fclose($conn);
        }
        $a = json_decode($raw, true);
        if (!is_array($a)) {
            throw new SignerUnavailable('no answer from the signer');
        }
        if (($a['ok'] ?? false) === true && is_string($a['certificate'] ?? null) && is_string($a['serial_hex'] ?? null)) {
            return ['certificate' => $a['certificate'], 'serial_hex' => $a['serial_hex']];
        }
        if (($a['error'] ?? null) === 'csr_invalid') {
            throw new CsrInvalid(is_string($a['reason'] ?? null) ? $a['reason'] : 'pem');
        }
        throw new SignerUnavailable('the signer answered ' . (is_string($a['error'] ?? null) ? $a['error'] : 'nothing usable'));
    }
}
