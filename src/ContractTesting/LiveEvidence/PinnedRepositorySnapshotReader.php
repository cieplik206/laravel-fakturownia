<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use RuntimeException;
use SensitiveParameter;

final class PinnedRepositorySnapshotReader
{
    private const MaximumBytes = 65_536;

    private function __construct() {}

    public static function read(
        #[SensitiveParameter] string $repositoryRoot,
        #[SensitiveParameter] string $relativePath,
    ): string {
        self::assertCanonicalRelativePath($relativePath);

        $providedRoot = \rtrim($repositoryRoot, '/');
        $root = \realpath($repositoryRoot);

        if (! \is_string($root)
            || ! \is_dir($root)
            || $providedRoot === ''
            || \is_link($providedRoot)) {
            throw new RuntimeException('The verified live-evidence repository root is invalid.');
        }

        $current = $root;
        $segments = \explode('/', $relativePath);

        foreach (\array_slice($segments, 0, -1) as $segment) {
            $current .= '/'.$segment;
            $snapshot = \lstat($current);

            if (! \is_array($snapshot)
                || ($snapshot['mode'] & 0170000) !== 0040000
                || \is_link($current)) {
                throw new RuntimeException('The pinned repository snapshot has an unsafe path component.');
            }
        }

        $path = $root.'/'.$relativePath;
        $resolvedPath = \realpath($path);

        if (! \is_string($resolvedPath)
            || $resolvedPath !== $path
            || ! \str_starts_with($resolvedPath, $root.'/')) {
            throw new RuntimeException('The pinned repository snapshot escapes the verified repository root.');
        }

        \clearstatcache(true, $path);
        $before = \lstat($path);

        self::assertRegularSnapshot($before, 'The pinned repository snapshot is missing or unsafe.');

        if (\is_link($path)) {
            throw new RuntimeException('The pinned repository snapshot is missing or unsafe.');
        }

        $handle = \fopen($path, 'rb');

        if (! \is_resource($handle)) {
            throw new RuntimeException('The pinned repository snapshot cannot be opened securely.');
        }

        try {
            $opened = \fstat($handle);
            $contents = \stream_get_contents($handle, self::MaximumBytes + 1);
            $afterHandle = \fstat($handle);
        } finally {
            \fclose($handle);
        }

        \clearstatcache(true, $path);
        $afterPath = \lstat($path);

        foreach ([$opened, $afterHandle, $afterPath] as $snapshot) {
            self::assertSameSnapshot($before, $snapshot);
        }

        if (! \is_string($contents)
            || \strlen($contents) !== $before['size']
            || \strlen($contents) > self::MaximumBytes) {
            throw new RuntimeException('The pinned repository snapshot could not be read atomically.');
        }

        return $contents;
    }

    /**
     * @param  array{dev: int, ino: int, mode: int, nlink: int, size: int, mtime: int, ctime: int, ...}|false  $snapshot
     *
     * @phpstan-assert array{dev: int, ino: int, mode: int, nlink: int, size: int, mtime: int, ctime: int, ...} $snapshot
     */
    private static function assertRegularSnapshot(array|false $snapshot, string $message): void
    {
        if (! \is_array($snapshot)
            || ($snapshot['mode'] & 0170000) !== 0100000
            || $snapshot['nlink'] !== 1
            || $snapshot['size'] < 1
            || $snapshot['size'] > self::MaximumBytes) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @param  array{dev: int, ino: int, mode: int, nlink: int, size: int, mtime: int, ctime: int, ...}  $before
     * @param  array{dev: int, ino: int, mode: int, nlink: int, size: int, mtime: int, ctime: int, ...}|false  $snapshot
     */
    private static function assertSameSnapshot(array $before, array|false $snapshot): void
    {
        self::assertRegularSnapshot($snapshot, 'The pinned repository snapshot changed while it was being read.');

        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if ($snapshot[$field] !== $before[$field]) {
                throw new RuntimeException('The pinned repository snapshot changed while it was being read.');
            }
        }
    }

    private static function assertCanonicalRelativePath(#[SensitiveParameter] string $relativePath): void
    {
        if ($relativePath === ''
            || \str_starts_with($relativePath, '/')
            || \str_contains($relativePath, '\\')
            || \preg_match('/[\x00-\x1F\x7F]/D', $relativePath) === 1) {
            throw new RuntimeException('The pinned repository snapshot path is not canonical.');
        }

        foreach (\explode('/', $relativePath) as $segment) {
            if ($segment === ''
                || $segment === '.'
                || $segment === '..'
                || \preg_match('/^[A-Za-z0-9._-]+$/D', $segment) !== 1) {
                throw new RuntimeException('The pinned repository snapshot path is not canonical.');
            }
        }
    }
}
