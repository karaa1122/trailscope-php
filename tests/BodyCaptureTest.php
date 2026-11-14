<?php

declare(strict_types=1);

namespace Trailscope\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trailscope\BodyCapture;

final class BodyCaptureTest extends TestCase
{
    private const JSON = '{"data":[{"type":"tasks","id":"1","attributes":{"title":"Write the brief","done":false}}]}';

    public function test_passes_bodies_without_an_encoding_through(): void
    {
        self::assertSame(self::JSON, BodyCapture::decode(self::JSON, null, 8192));
        self::assertSame(self::JSON, BodyCapture::decode(self::JSON, '', 8192));
        self::assertSame(self::JSON, BodyCapture::decode(self::JSON, ' Identity ', 8192));
        self::assertSame(['done' => false], BodyCapture::decode(['done' => false], null, 8192));
    }

    /** @return iterable<string, array{string, string}> */
    public static function encoded(): iterable
    {
        yield 'gzip' => ['gzip', self::gzip(self::JSON)];
        yield 'x-gzip' => ['x-gzip', self::gzip(self::JSON)];
        yield 'upper case' => ['GZIP', self::gzip(self::JSON)];
        yield 'deflate' => ['deflate', (string) gzcompress(self::JSON)];
    }

    #[DataProvider('encoded')]
    public function test_decodes_gzip_and_deflate(string $encoding, string $body): void
    {
        self::assertSame(self::JSON, BodyCapture::decode($body, $encoding, 8192));
    }

    public function test_stops_one_byte_past_the_limit_so_the_cut_is_seen(): void
    {
        $text = str_repeat('{"id":"123","title":"Write the brief"},', 2000);

        $decoded = BodyCapture::decode(self::gzip($text), 'gzip', 100);

        self::assertSame(substr($text, 0, 101), $decoded);
        self::assertSame([substr($text, 0, 100), true], BodyCapture::serialize($decoded, 'application/json', 100));
    }

    public function test_keeps_what_a_cut_stream_holds(): void
    {
        $text = str_repeat('{"id":"123","title":"Write the brief"},', 2000);
        $gzip = self::gzip($text);

        $decoded = BodyCapture::decode(substr($gzip, 0, intdiv(strlen($gzip), 2)), 'gzip', 1_000_000);

        self::assertIsString($decoded);
        self::assertNotSame('', $decoded);
        self::assertSame(substr($text, 0, strlen($decoded)), $decoded);
    }

    public function test_a_highly_compressed_body_does_not_inflate_past_the_limit(): void
    {
        // About 32 KB of gzip that inflates to 32 MB.
        $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
        self::assertNotFalse($deflate);
        $block = str_repeat('a', 1 << 20);
        $bomb = '';
        for ($i = 0; $i < 32; $i++) {
            $bomb .= (string) deflate_add($deflate, $block, ZLIB_NO_FLUSH);
        }
        $bomb .= (string) deflate_add($deflate, '', ZLIB_FINISH);
        unset($block);
        $peak = memory_get_peak_usage();

        $decoded = BodyCapture::decode($bomb, 'gzip', 8192);

        self::assertIsString($decoded);
        self::assertSame(8193, strlen($decoded));
        self::assertLessThan(4 << 20, memory_get_peak_usage() - $peak);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function unreadable(): iterable
    {
        yield 'brotli' => [self::gzip(self::JSON), 'br'];
        yield 'several encodings' => [self::gzip(self::JSON), 'gzip, br'];
        yield 'corrupt' => ['not compressed at all', 'gzip'];
        yield 'empty' => ['', 'gzip'];
        yield 'not a string' => [['done' => false], 'gzip'];
    }

    #[DataProvider('unreadable')]
    public function test_skips_bodies_it_cannot_decode(mixed $body, string $encoding): void
    {
        self::assertNull(BodyCapture::decode($body, $encoding, 8192));
    }

    private static function gzip(string $text): string
    {
        return (string) gzencode($text);
    }
}
