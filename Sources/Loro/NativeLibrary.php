<?php
declare(strict_types=1);

namespace Loro;

final class NativeLibrary
{
    public const ENV_VAR = 'LORO_PHP_LIBRARY';

    public static function path(): string
    {
        $configuredPath = self::configuredPath();
        if ($configuredPath !== null) {
            return $configuredPath;
        }

        foreach (self::candidatePaths() as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException(sprintf(
            'Unable to locate the loro-php native library. Set %s to the absolute path of %s, or place it in one of: %s',
            self::ENV_VAR,
            self::libraryFileName(),
            implode(', ', self::candidatePaths()),
        ));
    }

    private static function configuredPath(): ?string
    {
        $path = getenv(self::ENV_VAR);
        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        if (!is_file($path)) {
            throw new \RuntimeException(sprintf(
                '%s is set to "%s", but that file does not exist.',
                self::ENV_VAR,
                $path,
            ));
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private static function candidatePaths(): array
    {
        $root = dirname(__DIR__, 2);
        $fileName = self::libraryFileName();
        $platform = self::platformName();
        $arch = self::architectureName();

        return [
            self::join($root, 'native', "{$platform}-{$arch}", $fileName),
            self::join($root, 'native', $fileName),
        ];
    }

    private static function libraryFileName(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'libloro_php.dylib',
            'Windows' => 'loro_php.dll',
            default => 'libloro_php.so',
        };
    }

    private static function platformName(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Windows' => 'windows',
            'Linux' => 'linux',
            default => strtolower(PHP_OS_FAMILY),
        };
    }

    private static function architectureName(): string
    {
        return match (strtolower(php_uname('m'))) {
            'amd64', 'x86_64' => 'x64',
            'aarch64', 'arm64' => 'arm64',
            default => strtolower(php_uname('m')),
        };
    }

    private static function join(string ...$parts): string
    {
        return implode(DIRECTORY_SEPARATOR, $parts);
    }
}
