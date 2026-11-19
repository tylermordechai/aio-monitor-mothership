<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Config;
use Aio\Http\ApiError;
use Aio\Ingest\BatchReader;
use PHPUnit\Framework\TestCase;

/** ingest-api.md §3.1 limits (MS-ING-01, MS-ING-09). */
final class BatchReaderTest extends TestCase
{
    private function reader(array $ingest = []): BatchReader
    {
        return new BatchReader(Config::fromArray(['ingest' => $ingest]));
    }

    public function testLinesCrLfTrailingNewlineAndEmptyLines(): void
    {
        $lines = $this->reader()->read(gzencode("{\"a\":1}\r\n\n{\"b\":2}\n   \n{\"c\":3}\n"));
        self::assertSame(['{"a":1}', '{"b":2}', '{"c":3}'], $lines);
    }

    public function testNotGzipIs400BadGzip(): void
    {
        $this->expectCode(400, 'bad_gzip', fn () => $this->reader()->read('{"plain":"json"} not gzipped'));
    }

    public function testTruncatedGzipIs400(): void
    {
        $gz = gzencode(str_repeat("{\"x\":1}\n", 1000));
        $this->expectCode(400, 'bad_gzip', fn () => $this->reader()->read(substr($gz, 0, (int) (strlen($gz) / 2))));
    }

    public function testEmptyBatchIs400(): void
    {
        $this->expectCode(400, 'empty_batch', fn () => $this->reader()->read(gzencode("\n\n")));
    }

    public function testCompressedLimit(): void
    {
        $this->expectCode(413, 'batch_too_large', fn () => $this->reader(['max_compressed_bytes' => 100])->read(gzencode(random_bytes(500))));
    }

    public function testZipBombStopsAtTheRawLimit(): void
    {
        // 32 MB of zeros compresses to about 32 KB.
        $gz = gzencode(str_repeat('0', 32 * 1024 * 1024), 9);
        $this->expectCode(413, 'batch_too_large', fn () => $this->reader(['max_raw_bytes' => 1024 * 1024])->read($gz));
    }

    public function testLineCountLimit(): void
    {
        $this->expectCode(413, 'batch_too_large', fn () => $this->reader(['max_lines' => 3])->read(gzencode("1\n2\n3\n4\n")));
    }

    public function testByteOrderMarkRefused(): void
    {
        $this->expectCode(400, 'bad_gzip', fn () => $this->reader()->read(gzencode("\xEF\xBB\xBF{\"a\":1}\n")));
    }

    private function expectCode(int $status, string $code, callable $f): void
    {
        try {
            $f();
            self::fail("expected $status $code");
        } catch (ApiError $e) {
            self::assertSame($status, $e->status);
            self::assertSame($code, $e->errorCode);
        }
    }
}
