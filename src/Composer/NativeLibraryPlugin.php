<?php

declare(strict_types=1);

namespace Loro\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\RemoteFilesystem;
use Composer\Util\Url;
use PharData;
use RuntimeException;
use Throwable;

final class NativeLibraryPlugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'huanghantao/loro-php';
    private const RELEASE_BASE_URL = 'https://github.com/huanghantao/loro-php/releases/download';

    public function activate(Composer $composer, IOInterface $io) {}

    public function deactivate(Composer $composer, IOInterface $io) {}

    public function uninstall(Composer $composer, IOInterface $io) {}

    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_AUTOLOAD_DUMP => 'installNativeLibrary',
        ];
    }

    public function installNativeLibrary(Event $event): void
    {
        if ($this->envFlagEnabled('LORO_PHP_SKIP_NATIVE_INSTALL')) {
            $event->getIO()->write('<info>Skipping loro-php native library install.</info>', true, IOInterface::VERBOSE);
            return;
        }

        $root = dirname(__DIR__, 2);
        $targetFile = $this->nativeLibraryPath($root);

        if (is_file($targetFile)) {
            $event->getIO()->write('<info>loro-php native library is already installed.</info>', true, IOInterface::VERBOSE);
            return;
        }

        $release = $this->nativeReleaseTag($event->getComposer());
        if ($release === null) {
            $event->getIO()->writeError(
                '<warning>Unable to infer a loro-php release tag for native library download. Set LORO_PHP_NATIVE_RELEASE to install one.</warning>'
            );
            return;
        }

        $platform = $this->nativePlatform();
        $assetName = "loro-php-native-{$platform}.tar.gz";
        $baseUrl = rtrim((string) (getenv('LORO_PHP_NATIVE_BASE_URL') ?: self::RELEASE_BASE_URL), '/');
        $assetUrl = "{$baseUrl}/{$release}/{$assetName}";
        $checksumUrl = "{$assetUrl}.sha256";

        $event->getIO()->write("<info>Installing loro-php native library for {$platform} from {$release}.</info>");

        $tmpBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . uniqid('loro-php-native-', true);
        $archivePath = "{$tmpBase}.tar.gz";
        $checksumPath = "{$archivePath}.sha256";

        try {
            $remoteFilesystem = new RemoteFilesystem($event->getIO(), $event->getComposer()->getConfig());
            $remoteFilesystem->copy(Url::getOrigin($event->getComposer()->getConfig(), $assetUrl), $assetUrl, $archivePath);
            $remoteFilesystem->copy(Url::getOrigin($event->getComposer()->getConfig(), $checksumUrl), $checksumUrl, $checksumPath);

            $this->verifyChecksum($archivePath, $checksumPath);
            $this->extractArchive($archivePath, $root);

            if (!is_file($targetFile)) {
                throw new RuntimeException("Native library archive did not contain {$targetFile}.");
            }

            @chmod($targetFile, 0755);
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Unable to install the loro-php native library. You can set LORO_PHP_LIBRARY to an existing library path instead. '
                . $throwable->getMessage(),
                0,
                $throwable
            );
        } finally {
            @unlink($archivePath);
            @unlink($checksumPath);
            @unlink(substr($archivePath, 0, -3));
        }
    }

    private function nativeReleaseTag(Composer $composer): ?string
    {
        $configured = getenv('LORO_PHP_NATIVE_RELEASE');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        foreach ($composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            if ($package->getName() === self::PACKAGE_NAME) {
                return $this->stableReleaseTag($package->getPrettyVersion());
            }
        }

        $rootPackage = $composer->getPackage();
        if ($rootPackage->getName() === self::PACKAGE_NAME) {
            return $this->stableReleaseTag($rootPackage->getPrettyVersion());
        }

        return null;
    }

    private function stableReleaseTag(string $prettyVersion): ?string
    {
        if (str_contains($prettyVersion, 'dev')) {
            return null;
        }

        return $prettyVersion;
    }

    private function nativeLibraryPath(string $root): string
    {
        return $this->joinPath($root, 'native', $this->nativePlatform(), $this->nativeLibraryFileName());
    }

    private function nativeLibraryFileName(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'libloro_php.dylib',
            'Windows' => 'loro_php.dll',
            default => 'libloro_php.so',
        };
    }

    private function nativePlatform(): string
    {
        return $this->nativePlatformName() . '-' . $this->nativeArchitectureName();
    }

    private function nativePlatformName(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Windows' => 'windows',
            'Linux' => 'linux',
            default => strtolower(PHP_OS_FAMILY),
        };
    }

    private function nativeArchitectureName(): string
    {
        return match (strtolower(php_uname('m'))) {
            'amd64', 'x86_64' => 'x64',
            'aarch64', 'arm64' => 'arm64',
            default => strtolower(php_uname('m')),
        };
    }

    private function verifyChecksum(string $archivePath, string $checksumPath): void
    {
        $checksum = trim((string) file_get_contents($checksumPath));
        if (preg_match('/^[a-fA-F0-9]{64}/', $checksum, $matches) !== 1) {
            throw new RuntimeException('Native library checksum file is invalid.');
        }

        $expected = strtolower($matches[0]);
        $actual = hash_file('sha256', $archivePath);

        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            throw new RuntimeException('Native library checksum verification failed.');
        }
    }

    private function extractArchive(string $archivePath, string $root): void
    {
        $tarPath = substr($archivePath, 0, -3);

        $compressed = new PharData($archivePath);
        $compressed->decompress();

        $archive = new PharData($tarPath);
        $archive->extractTo($root, null, true);
    }

    private function envFlagEnabled(string $name): bool
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            return false;
        }

        return !in_array(strtolower($value), ['0', 'false', 'no', 'off'], true);
    }

    private function joinPath(string ...$parts): string
    {
        return implode(DIRECTORY_SEPARATOR, $parts);
    }
}
