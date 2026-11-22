<?php

declare(strict_types=1);

namespace Aio\Jobs;

use Aio\Util\Time;
use PDO;

/**
 * Fills flow_conn_hourly from net_flows (M-054, MS-DAT-04, MS-UI-08).
 *
 * Works through ingest batches in order. A batch is taken only once it is
 * `settle_s` old: every batch row is written at the start of its transaction
 * and requests end within 60 s, so by then it is committed or gone, and a
 * slower batch with a lower number can't be skipped. Each (host, hour) the
 * new batches touched is rebuilt whole in one transaction, so a row always
 * equals the GROUP BY over net_flows for that hour and a re-run changes
 * nothing. Where it got to is kept in job_state.
 *
 * Rebuilding whole hours suits the development fleet; at 500 hosts the open
 * hour would be read again every minute, so production may need an
 * incremental upsert instead (noted for MS-ING-10 on production hardware).
 */
final class FlowRollup
{
    public const STATE = 'flow_rollup.batch_pk';

    public function __construct(private readonly PDO $pdo, private readonly int $settleS = 90)
    {
    }

    /** @return array{from: int, to: int, batches: int, hours: int, rows: int} */
    public function runOnce(int $maxBatches = 2000): array
    {
        $from = $this->position();
        $s = $this->pdo->prepare(
            'SELECT MAX(id) AS upto, COUNT(*) AS n FROM (
                SELECT id FROM ingest_batches
                 WHERE id > ? AND received_at < UTC_TIMESTAMP(6) - INTERVAL ? SECOND
                 ORDER BY id LIMIT ?) t'
        );
        $s->bindValue(1, $from, PDO::PARAM_INT);
        $s->bindValue(2, $this->settleS, PDO::PARAM_INT);
        $s->bindValue(3, $maxBatches, PDO::PARAM_INT);
        $s->execute();
        $r = $s->fetch();
        if (!is_array($r) || $r['upto'] === null) {
            return ['from' => $from, 'to' => $from, 'batches' => 0, 'hours' => 0, 'rows' => 0];
        }
        $to = (int) $r['upto'];

        $h = $this->pdo->prepare(
            "SELECT DISTINCT host_pk, DATE_FORMAT(ts, '%Y-%m-%d %H:00:00') AS hour
               FROM net_flows WHERE batch_pk > ? AND batch_pk <= ? ORDER BY hour, host_pk"
        );
        $h->execute([$from, $to]);
        $hours = $h->fetchAll();
        $rows = 0;
        foreach ($hours as $x) {
            $rows += $this->rebuild((int) $x['host_pk'], (string) $x['hour']);
        }
        $this->setPosition($to);
        return ['from' => $from, 'to' => $to, 'batches' => (int) $r['n'], 'hours' => count($hours), 'rows' => $rows];
    }

    /** Rebuilds one host's hour; returns the connection rows written. */
    public function rebuild(int $hostPk, string $hour): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM flow_conn_hourly WHERE host_pk = ? AND hour = ?')->execute([$hostPk, $hour]);
            // flow_key fixes proto, PID, addresses and ports (D-016), so MAX()
            // of those is exact; process details are the same unless a PID was
            // reused within the hour, when one of them is kept.
            $ins = $this->pdo->prepare(
                "INSERT INTO flow_conn_hourly
                    (host_pk, hour, flow_key, proto, local_addr, local_ip, local_port, remote_addr, remote_ip, remote_port,
                     pid, process_name, exe, `user`, user_source, direction, direction_source, first_seen, last_seen,
                     windows, connects, packets_sent, packets_recv, bytes_sent, bytes_recv, closed)
                 SELECT host_pk, ?, flow_key, MAX(proto), MAX(local_addr), MAX(local_ip), MAX(local_port),
                        MAX(remote_addr), MAX(remote_ip), MAX(remote_port), MAX(pid), MAX(process_name), MAX(exe),
                        MAX(`user`), MAX(user_source), MAX(direction), MAX(direction_source),
                        MIN(COALESCE(first_seen, window_start)), MAX(COALESCE(last_seen, window_end)), COUNT(*),
                        COALESCE(SUM(connects), 0), COALESCE(SUM(packets_sent), 0), COALESCE(SUM(packets_recv), 0),
                        COALESCE(SUM(bytes_sent), 0), COALESCE(SUM(bytes_recv), 0), MAX(COALESCE(closed, 0))
                   FROM net_flows
                  WHERE host_pk = ? AND ts >= CAST(? AS DATETIME) AND ts < CAST(? AS DATETIME) + INTERVAL 1 HOUR
                  GROUP BY host_pk, flow_key"
            );
            $ins->execute([$hour, $hostPk, $hour, $hour]);
            $n = $ins->rowCount();
            $this->pdo->commit();
            return $n;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function position(): int
    {
        $s = $this->pdo->prepare('SELECT value FROM job_state WHERE name = ?');
        $s->execute([self::STATE]);
        $v = $s->fetchColumn();
        return $v === false ? 0 : (int) $v;
    }

    private function setPosition(int $batchPk): void
    {
        $this->pdo->prepare(
            'INSERT INTO job_state (name, value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)'
        )->execute([self::STATE, (string) $batchPk, Time::nowDb()]);
    }
}
