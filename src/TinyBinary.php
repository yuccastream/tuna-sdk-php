<?php

declare(strict_types=1);

namespace Tuna;

/**
 * Finds the tuna-tiny binary the SDK runs: an explicit path, tuna-tiny in
 * PATH, or a release download verified against the checksums pinned in
 * TinyChecksums.
 */
final class TinyBinary
{
    public const BASE_URL = 'https://releases.tuna.am/tuna-tiny';

    public const PLATFORMS = [
        'linux_amd64', 'linux_arm64',
        'darwin_amd64', 'darwin_arm64',
        'windows_amd64', 'windows_arm64',
        'freebsd_amd64', 'freebsd_arm64',
        'openbsd_amd64', 'openbsd_arm64',
    ];

    /**
     * Returns the path to tuna-tiny: $path if given, tuna-tiny from PATH,
     * otherwise the pinned release downloaded into $cacheDir.
     */
    public static function locate(?string $path = null, ?string $cacheDir = null): string
    {
        if ($path !== null && $path !== '') {
            if (!is_file($path)) {
                throw new TunaException("tuna: tuna-tiny binary {$path} does not exist");
            }

            return $path;
        }
        $found = self::findInPath(PHP_OS_FAMILY === 'Windows' ? 'tuna-tiny.exe' : 'tuna-tiny', (string) getenv('PATH'));
        if ($found !== null) {
            return $found;
        }

        return self::cached($cacheDir ?? self::defaultCacheDir());
    }

    /**
     * Returns the pinned release binary for this platform from $cacheDir,
     * downloading it first if it is missing or does not match its checksum.
     */
    public static function cached(string $cacheDir, string $baseURL = self::BASE_URL, ?string $platform = null): string
    {
        $platform ??= self::platform();
        $sha256 = TinyChecksums::SHA256[$platform] ?? null;
        if ($sha256 === null) {
            throw new TunaException(sprintf(
                'tuna: no pinned tuna-tiny %s checksum for %s, install tuna-tiny into PATH or pass the binary option',
                TinyChecksums::VERSION,
                $platform,
            ));
        }
        $name = self::fileName($platform);
        $dest = $cacheDir . DIRECTORY_SEPARATOR . 'tuna-tiny-' . TinyChecksums::VERSION . DIRECTORY_SEPARATOR . $name;
        if (is_file($dest) && hash_equals($sha256, (string) hash_file('sha256', $dest))) {
            return $dest;
        }
        self::download($baseURL . '/v' . TinyChecksums::VERSION . '/' . $name, $sha256, $dest);

        return $dest;
    }

    /**
     * Downloads $url to $dest and makes it executable. Nothing is left at
     * $dest unless the content matches $sha256.
     */
    public static function download(string $url, string $sha256, string $dest): void
    {
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new TunaException("tuna: cannot create {$dir}");
        }
        $ctx = stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'ignore_errors' => false]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            $err = error_get_last()['message'] ?? 'unknown error';
            throw new TunaException("tuna: download {$url}: {$err}");
        }
        $got = hash('sha256', $data);
        if (!hash_equals($sha256, $got)) {
            throw new TunaException("tuna: checksum mismatch for {$url}: got {$got}, want {$sha256}");
        }
        $tmp = $dest . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $data) !== strlen($data) || !@chmod($tmp, 0755) || !@rename($tmp, $dest)) {
            @unlink($tmp);
            throw new TunaException("tuna: cannot write {$dest}");
        }
    }

    /**
     * Maps an OS name (PHP_OS) and a machine type (php_uname('m')) to a
     * release platform such as "linux_amd64".
     */
    public static function platform(string $os = PHP_OS, ?string $machine = null): string
    {
        $machine ??= php_uname('m');
        $goos = match (strtolower($os)) {
            'linux' => 'linux',
            'darwin' => 'darwin',
            'freebsd' => 'freebsd',
            'openbsd' => 'openbsd',
            'winnt', 'windows', 'win32' => 'windows',
            default => throw new TunaException("tuna: tuna-tiny is not built for {$os}"),
        };
        $goarch = match (strtolower($machine)) {
            'x86_64', 'amd64', 'x64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            default => throw new TunaException("tuna: tuna-tiny is not built for {$os} on {$machine}"),
        };

        return $goos . '_' . $goarch;
    }

    /**
     * Returns the release file name of the platform, e.g. "tuna-tiny_windows_amd64.exe".
     */
    public static function fileName(string $platform): string
    {
        return 'tuna-tiny_' . $platform . (str_starts_with($platform, 'windows_') ? '.exe' : '');
    }

    /**
     * Returns the first executable $name in the PATH-style list $path.
     */
    public static function findInPath(string $name, string $path): ?string
    {
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
            if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function defaultCacheDir(): string
    {
        $base = getenv('XDG_CACHE_HOME') ?: getenv('LOCALAPPDATA') ?: (getenv('HOME') ? getenv('HOME') . '/.cache' : sys_get_temp_dir());

        return $base . DIRECTORY_SEPARATOR . 'tuna-sdk-php';
    }
}
