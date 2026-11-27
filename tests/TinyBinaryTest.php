<?php

declare(strict_types=1);

namespace Tuna\Tests;

use PHPUnit\Framework\TestCase;
use Tuna\TinyBinary;
use Tuna\TunaException;

final class TinyBinaryTest extends TestCase
{
    /** @var resource|null */
    private static $server;
    private static string $root;
    private static string $base;
    private string $cache;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/tuna-sdk-test-www-' . bin2hex(random_bytes(4));
        mkdir(self::$root . '/v1.0.0', 0755, true);
        file_put_contents(self::$root . '/v1.0.0/tuna-tiny_linux_amd64', 'binary');
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        self::$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', self::$root], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::$base = "http://127.0.0.1:{$port}";
        for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(50_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        proc_close(self::$server);
        exec('rm -rf ' . escapeshellarg(self::$root));
    }

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/tuna-sdk-test-cache-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->cache));
    }

    public function testDownloadVerifiesChecksum(): void
    {
        $dest = $this->cache . '/tuna-tiny';
        TinyBinary::download(self::$base . '/v1.0.0/tuna-tiny_linux_amd64', hash('sha256', 'binary'), $dest);
        $this->assertSame('binary', file_get_contents($dest));
        $this->assertTrue(is_executable($dest));
    }

    public function testDownloadRejectsWrongChecksum(): void
    {
        $dest = $this->cache . '/tuna-tiny';
        try {
            TinyBinary::download(self::$base . '/v1.0.0/tuna-tiny_linux_amd64', hash('sha256', 'other'), $dest);
            $this->fail('no exception');
        } catch (TunaException $e) {
            $this->assertStringContainsString('checksum mismatch', $e->getMessage());
        }
        $this->assertSame([], glob($this->cache . '/*'));
    }

    public function testDownloadFailsOnMissingFile(): void
    {
        $this->expectException(TunaException::class);
        $this->expectExceptionMessage('404');
        TinyBinary::download(self::$base . '/v1.0.0/tuna-tiny_plan9_amd64', hash('sha256', 'binary'), $this->cache . '/tuna-tiny');
    }

    public function testCachedFailsWithoutPinnedChecksum(): void
    {
        $this->expectException(TunaException::class);
        $this->expectExceptionMessage('no pinned tuna-tiny');
        TinyBinary::cached($this->cache, self::$base, 'plan9_amd64');
    }

    public function testPlatform(): void
    {
        $this->assertSame('linux_amd64', TinyBinary::platform('Linux', 'x86_64'));
        $this->assertSame('darwin_arm64', TinyBinary::platform('Darwin', 'arm64'));
        $this->assertSame('linux_arm64', TinyBinary::platform('Linux', 'aarch64'));
        $this->assertSame('windows_amd64', TinyBinary::platform('WINNT', 'AMD64'));
        $this->assertSame('freebsd_amd64', TinyBinary::platform('FreeBSD', 'amd64'));
        $this->assertSame('openbsd_arm64', TinyBinary::platform('OpenBSD', 'arm64'));
        $this->assertSame('tuna-tiny_windows_arm64.exe', TinyBinary::fileName('windows_arm64'));
        $this->assertSame('tuna-tiny_linux_amd64', TinyBinary::fileName('linux_amd64'));
        $this->expectException(TunaException::class);
        TinyBinary::platform('Linux', 'riscv64');
    }

    public function testPlatformsCoverPinnedChecksums(): void
    {
        $this->assertSame([], array_diff(array_keys(\Tuna\TinyChecksums::SHA256), TinyBinary::PLATFORMS));
    }

    public function testFindInPath(): void
    {
        mkdir($this->cache . '/a', 0755, true);
        mkdir($this->cache . '/b', 0755, true);
        file_put_contents($this->cache . '/a/tuna-tiny', '');
        file_put_contents($this->cache . '/b/tuna-tiny', '');
        chmod($this->cache . '/b/tuna-tiny', 0755);
        $path = implode(PATH_SEPARATOR, ['', $this->cache . '/a', $this->cache . '/b']);
        $this->assertSame($this->cache . '/b/tuna-tiny', TinyBinary::findInPath('tuna-tiny', $path));
        $this->assertNull(TinyBinary::findInPath('tuna-tiny', $this->cache . '/a'));
    }

    public function testLocateRejectsMissingExplicitPath(): void
    {
        $this->expectException(TunaException::class);
        TinyBinary::locate($this->cache . '/nope');
    }
}
