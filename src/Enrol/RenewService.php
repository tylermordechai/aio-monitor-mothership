<?php

declare(strict_types=1);

namespace Aio\Enrol;

use Aio\Auth\AgentContext;
use Aio\Http\ApiError;
use Aio\Http\Request;
use Aio\Http\Response;
use Aio\Pki\CertSigner;
use Aio\Pki\CsrCheck;
use Aio\Pki\CsrInvalid;
use Aio\Util\Time;
use Aio\Web\Audit;
use PDO;

/**
 * POST /api/v1/renew (ingest-api.md §2.6; MS-ING-11, MS-SEC-02, M-018, M-064).
 *
 * The agent is the one its current client certificate names (ClientCertAuth
 * has already refused a revoked certificate or agent). The body is
 * {"csr": "<PEM>"} with a NEW key and the same CN. The new certificate is
 * added as valid; every other valid certificate of the agent becomes
 * superseded, which still authenticates until it expires, so a lost answer
 * costs nothing: the agent simply renews again with the certificate it has.
 * Audited as agent_certificate_renewed (old and new serial; never the CSR or
 * the certificate, MS-SEC-10).
 */
final class RenewService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CertSigner $signer,
        private readonly string $caPem,
    ) {
    }

    /** @throws ApiError */
    public function handle(Request $req, AgentContext $agent): Response
    {
        $csr = CertIssue::jsonBody($req, ['csr'], 'Renewal')['csr'];
        try {
            CsrCheck::check($csr, $agent->agentId);
        } catch (CsrInvalid $e) {
            throw CertIssue::csrInvalid($e->reason);
        }
        $current = @openssl_pkey_get_public((string) $req->clientCertPem);
        $currentKey = $current === false ? null : (openssl_pkey_get_details($current)['key'] ?? null);
        if ($currentKey === null || CsrCheck::publicKeyPem($csr) === $currentKey) {
            throw CertIssue::csrInvalid('same_key');
        }
        $oldFp = openssl_x509_fingerprint((string) $req->clientCertPem, 'sha256', true);

        $nowDb = Time::nowDb();
        $this->pdo->beginTransaction();
        try {
            // The agent row is locked so two renewals of one agent run one
            // after the other, and a revocation in between is seen.
            $s = $this->pdo->prepare(
                'SELECT a.status, g.name AS group_name FROM agents a JOIN hosts h ON h.id = a.host_pk
                   JOIN host_groups g ON g.id = h.group_id WHERE a.id = ? FOR UPDATE'
            );
            $s->execute([$agent->agentPk]);
            $a = $s->fetch();
            $c = $this->pdo->prepare('SELECT serial_hex, status FROM agent_certificates WHERE fingerprint_sha256 = ? AND agent_pk = ? FOR UPDATE');
            $c->bindValue(1, $oldFp, PDO::PARAM_LOB);
            $c->bindValue(2, $agent->agentPk, PDO::PARAM_INT);
            $c->execute();
            $old = $c->fetch();
            if (!is_array($a) || !is_array($old) || $a['status'] === 'revoked' || $old['status'] === 'revoked') {
                throw ApiError::agentRevoked();
            }

            $signed = CertIssue::signAndStore($this->pdo, $this->signer, $csr, $agent->agentId, $agent->agentPk, 'renew', $nowDb);
            $sup = $this->pdo->prepare('UPDATE agent_certificates SET status = \'superseded\' WHERE agent_pk = ? AND status = \'valid\' AND id <> ?');
            $sup->execute([$agent->agentPk, $signed['id']]);

            Audit::write($this->pdo, 'agent_certificate_renewed', null, 'agent:' . $agent->agentId, $req->remoteAddr, 'agent', $agent->agentId, [
                'old_serial' => $old['serial_hex'],
                'serial' => $signed['serial_hex'],
                'superseded' => $sup->rowCount(),
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return CertIssue::answer(200, $agent->agentId, $signed['certificate'], $signed['not_after'], $this->caPem, (string) $a['group_name']);
    }
}
