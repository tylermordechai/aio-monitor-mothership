<?php

declare(strict_types=1);

namespace Aio\Pki;

/**
 * The signing service's wire format (MS-SEC-02). One request per connection
 * on the local socket /run/aio-signer.sock: the client writes one JSON object
 * and closes its side; the signer answers with one JSON object and exits.
 *
 *   request:  {"v":1,"op":"sign","agent_id":"<uuid v4>","csr":"<PEM>"}
 *   answers:  {"ok":true,"certificate":"<PEM>","serial_hex":"<32 hex>"}
 *             {"ok":false,"error":"csr_invalid","reason":"<CsrInvalid code>"}
 *             {"ok":false,"error":"bad_request"} | {"ok":false,"error":"signer_error"}
 */
final class SignerProtocol
{
    public const VERSION = 1;
    public const MAX_REQUEST_BYTES = 16_384;
    public const MAX_ANSWER_BYTES = 65_536;

    /**
     * The signer's side: one request in, one answer out. Logs go to $log as
     * one line each: agent ID, serial and outcome, never the CSR (MS-SEC-10).
     *
     * @param \Closure(string): void $log
     */
    public static function handle(string $raw, Signer $signer, \Closure $log): string
    {
        if (strlen($raw) > self::MAX_REQUEST_BYTES) {
            $log('refused: request too large');
            return self::answer(['ok' => false, 'error' => 'bad_request']);
        }
        $req = json_decode($raw, true);
        if (
            !is_array($req) || ($req['v'] ?? null) !== self::VERSION || ($req['op'] ?? null) !== 'sign'
            || !is_string($req['agent_id'] ?? null) || !is_string($req['csr'] ?? null)
        ) {
            $log('refused: bad request');
            return self::answer(['ok' => false, 'error' => 'bad_request']);
        }
        $id = preg_match(CsrCheck::UUID_V4, $req['agent_id']) ? $req['agent_id'] : '(not a uuid)';
        try {
            $r = $signer->sign($req['csr'], $req['agent_id']);
            $log("signed agent_id=$id serial={$r['serial_hex']}");
            return self::answer(['ok' => true, 'certificate' => $r['certificate'], 'serial_hex' => $r['serial_hex']]);
        } catch (CsrInvalid $e) {
            $log("refused agent_id=$id: csr_invalid ({$e->reason})");
            return self::answer(['ok' => false, 'error' => 'csr_invalid', 'reason' => $e->reason]);
        } catch (\Throwable $e) {
            $log("error agent_id=$id: " . $e->getMessage());
            return self::answer(['ok' => false, 'error' => 'signer_error']);
        }
    }

    /** @param array<string, mixed> $a */
    private static function answer(array $a): string
    {
        return json_encode($a, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
