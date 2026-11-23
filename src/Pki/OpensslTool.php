<?php

declare(strict_types=1);

namespace Aio\Pki;

/**
 * Runs the openssl command-line tool with an argument list (never a shell
 * string), a time limit and a small environment. The signer uses it for what
 * PHP's openssl extension can't do: verify a CSR's own signature, and sign
 * with a chosen subject, notBefore and extensions (ingest-api.md §2.3).
 */
final class OpensslTool
{
    /** @param array<string, string>|null $env null: inherit (tests on the PC) */
    public function __construct(
        private readonly string $binary = '/usr/bin/openssl',
        private readonly ?array $env = ['PATH' => '/usr/bin:/bin', 'LANG' => 'C'],
        private readonly float $timeoutS = 10.0,
    ) {
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    public function run(array $args): array
    {
        $proc = proc_open(
            [$this->binary, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $this->env,
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException('could not start openssl');
        }
        fclose($pipes[0]);
        if (PHP_OS_FAMILY === 'Windows') {
            // Unit tests on the PC only: Windows can't select() on pipes. The
            // output is a few KB, so reading one pipe after the other is safe.
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($proc), $out, $err];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $deadline = microtime(true) + $this->timeoutS;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $w = $e = null;
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                proc_terminate($proc, 9);
                proc_close($proc);
                throw new \RuntimeException('openssl timed out');
            }
            if (@stream_select($read, $w, $e, 0, (int) min(200_000, $left * 1_000_000)) === false) {
                break;
            }
            foreach ($read as $r) {
                $chunk = (string) fread($r, 65_536);
                $r === $pipes[1] ? $out .= $chunk : $err .= $chunk;
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
            if (strlen($out) + strlen($err) > 1_048_576) {
                proc_terminate($proc, 9);
                proc_close($proc);
                throw new \RuntimeException('openssl output too large');
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return [$code, $out, $err];
    }
}
