<?php

declare(strict_types=1);

namespace Aio\Enrol;

use Aio\Http\ApiError;
use Aio\Http\Request;
use Aio\Http\Response;
use Aio\Pki\CertProfile;
use Aio\Pki\CertSigner;
use Aio\Pki\CsrInvalid;
use Aio\Util\Time;
use PDO;

/**
 * What enrolment and renewal share (ingest-api.md §2.2, §2.6; MS-ING-06,
 * MS-ING-11, M-062, M-064): the JSON body, the csr_invalid answer, signing
 * and registering a certificate, and the answer with the certificate.
 */
final class CertIssue
{
    public const MAX_BODY = 16_384;

    /**
     * @param list<string> $fields required, non-empty strings
     * @return array<string, string>
     * @throws ApiError 415, 413, 400 invalid_json or missing_field
     */
    public static function jsonBody(Request $req, array $fields, string $what): array
    {
        $ct = strtolower(trim(explode(';', (string) $req->header('content-type'))[0]));
        if ($ct !== 'application/json') {
            throw ApiError::unsupportedMediaType('Content-Type must be application/json');
        }
        $raw = $req->body(self::MAX_BODY);
        if (strlen($raw) > self::MAX_BODY) {
            throw new ApiError(413, 'batch_too_large', "$what body over " . self::MAX_BODY . ' bytes', false);
        }
        try {
            $o = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiError::invalidJson();
        }
        if (!is_array($o) || array_is_list($o)) {
            throw ApiError::invalidJson();
        }
        $out = [];
        foreach ($fields as $f) {
            if (!isset($o[$f]) || !is_string($o[$f]) || $o[$f] === '') {
                throw new ApiError(400, 'missing_field', "Missing or not a string: $f", false);
            }
            $out[$f] = $o[$f];
        }
        return $out;
    }

    public static function csrInvalid(string $reason): ApiError
    {
        $why = CsrInvalid::REASONS[$reason] ?? 'the CSR is not acceptable';
        return new ApiError(400, 'csr_invalid', "CSR refused: $why", false);
    }

    /**
     * Signs the CSR and adds the certificate to agent_certificates as valid.
     * Runs inside the caller's transaction.
     *
     * @param 'enroll'|'renew' $via
     * @return array{certificate: string, serial_hex: string, not_after: int, id: int}
     * @throws ApiError 400 csr_invalid
     */
    public static function signAndStore(PDO $pdo, CertSigner $signer, string $csr, string $agentId, int $agentPk, string $via, string $nowDb): array
    {
        try {
            $signed = $signer->sign($csr, $agentId);
        } catch (CsrInvalid $e) {
            throw self::csrInvalid($e->reason);
        }
        $cert = openssl_x509_parse($signed['certificate']);
        $fp = openssl_x509_fingerprint($signed['certificate'], 'sha256', true);
        if ($cert === false || $fp === false || ($cert['subject']['CN'] ?? null) !== $agentId) {
            throw new \RuntimeException('the signer returned an unusable certificate');
        }
        $notAfter = (int) $cert['validTo_time_t'];
        $c = $pdo->prepare(
            'INSERT INTO agent_certificates (agent_pk, serial_hex, fingerprint_sha256, not_before, not_after, issued_at, issued_via)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $c->bindValue(1, $agentPk, PDO::PARAM_INT);
        $c->bindValue(2, $signed['serial_hex']);
        $c->bindValue(3, $fp, PDO::PARAM_LOB);
        $c->bindValue(4, Time::nowDb((int) $cert['validFrom_time_t']));
        $c->bindValue(5, Time::nowDb($notAfter));
        $c->bindValue(6, $nowDb);
        $c->bindValue(7, $via);
        $c->execute();
        return ['certificate' => $signed['certificate'], 'serial_hex' => $signed['serial_hex'], 'not_after' => $notAfter, 'id' => (int) $pdo->lastInsertId()];
    }

    /** The §2.2 answer, also used by renewal (§2.6). */
    public static function answer(int $status, string $agentId, string $certificate, int $notAfter, string $caPem, string $group): Response
    {
        return new Response($status, [
            'agent_id' => $agentId,
            'certificate' => $certificate,
            'ca_certificate' => $caPem,
            'not_after' => Time::rfc3339($notAfter),
            'renew_after' => Time::rfc3339(CertProfile::renewAfter($notAfter)),
            'host_group' => $group,
        ]);
    }
}
