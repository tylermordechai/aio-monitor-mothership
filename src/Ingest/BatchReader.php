<?php

declare(strict_types=1);

namespace Aio\Ingest;

use Aio\Config;
use Aio\Http\ApiError;

/**
 * Turns an ingest request body into lines (ingest-api.md §3.1, MS-ING-01, 09).
 *
 * The gzip body is inflated in steps and the decompressed size is checked as
 * it grows, so a small zip bomb can't use up memory. A request-level failure
 * stores nothing (§3.2 step 2).
 */
final class BatchReader
{
    // Deflate expands at most about 1032:1, so a 4 KB step can't add more
    // than about 4 MB before the size check runs.
    private const CHUNK = 4096;

    /** Decompressed size of the last batch read, for ingest_batches.bytes_raw. */
    public int $rawBytes = 0;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return list<string> the non-empty lines, without line endings
     * @throws ApiError 400 bad_gzip or empty_batch, 413 batch_too_large
     */
    public function read(string $gzip): array
    {
        $maxCompressed = $this->config->int('ingest', 'max_compressed_bytes');
        if (strlen($gzip) > $maxCompressed) {
            throw ApiError::batchTooLarge('compressed size');
        }
        $raw = $this->inflate($gzip, $this->config->int('ingest', 'max_raw_bytes'));
        $this->rawBytes = strlen($raw);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            throw new ApiError(400, 'bad_gzip', 'The batch starts with a byte-order mark', false);
        }
        $maxLines = $this->config->int('ingest', 'max_lines');
        $lines = [];
        $offset = 0;
        $len = strlen($raw);
        while ($offset < $len) {
            $nl = strpos($raw, "\n", $offset);
            $end = $nl === false ? $len : $nl;
            $line = substr($raw, $offset, $end - $offset);
            if ($line !== '' && $line[-1] === "\r") {
                $line = substr($line, 0, -1);
            }
            if ($line !== '' && trim($line) !== '') {
                if (count($lines) >= $maxLines) {
                    throw ApiError::batchTooLarge('line count');
                }
                $lines[] = $line;
            }
            $offset = $end + 1;
        }
        if ($lines === []) {
            throw ApiError::emptyBatch();
        }
        return $lines;
    }

    private function inflate(string $gzip, int $maxRaw): string
    {
        if (strlen($gzip) < 18 || $gzip[0] !== "\x1f" || $gzip[1] !== "\x8b") {
            throw ApiError::badGzip();
        }
        $ctx = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($ctx === false) {
            throw ApiError::internal();
        }
        $out = '';
        $len = strlen($gzip);
        $ended = false;
        for ($pos = 0; $pos < $len && !$ended; $pos += self::CHUNK) {
            $part = @inflate_add($ctx, substr($gzip, $pos, self::CHUNK), ZLIB_SYNC_FLUSH);
            if ($part === false) {
                throw ApiError::badGzip();
            }
            $out .= $part;
            if (strlen($out) > $maxRaw) {
                throw ApiError::batchTooLarge('decompressed size');
            }
            $ended = inflate_get_status($ctx) === ZLIB_STREAM_END;
        }
        // The stream must end exactly once, at the end of the body.
        if (!$ended || inflate_get_read_len($ctx) !== $len) {
            throw ApiError::badGzip();
        }
        return $out;
    }
}
