<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\PreAutoload;

use JsonException;
use RuntimeException;
use Throwable;

/**
 * This file is the pre-autoload trust root. It must be installed root-owned and
 * invoked with an absolute PHP executable and the -n switch.
 */
final class PreAutoloadLauncher
{
    private const PolicyPath = '/etc/cieplik206/fakturownia-live-evidence/preautoload-policy.json';

    private const TrustedOwnerUid = 0;

    private const RequireDistinctRuntimeOwner = true;

    private const EnforceRuntimeAncestorOwnership = true;

    private const NativeSupervisorDeploymentAvailable = false;

    private const PolicyContract = 'cieplik206.fakturownia.preauthenticated-policy';

    private const ManifestContract = 'cieplik206.fakturownia.preauthenticated-snapshot';

    private const Version = 1;

    private const MaximumPolicyBytes = 65_536;

    private const MaximumPolicyDepth = 16;

    private const MaximumPolicyNodes = 1_024;

    private const MaximumManifestBytes = 16_777_216;

    private const MaximumManifestDepth = 64;

    private const MaximumManifestNodes = 500_000;

    private const MaximumTreeFiles = 100_000;

    private const MaximumTreeDirectories = 50_000;

    private const MaximumPathBytes = 1_024;

    private const MaximumFileBytes = 536_870_912;

    private const MaximumTreeBytes = 4_294_967_296;

    private const MaximumSecretBytes = 4_194_304;

    private const SignatureBytes = \SODIUM_CRYPTO_SIGN_BYTES;

    private const PublicKeyBytes = \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;

    /** @param list<string> $arguments */
    public static function run(array $arguments): int
    {
        try {
            self::assertBootstrapRuntimeIsClean();
            self::clearInheritedEnvironment();
            self::assertNativeSupervisorDeploymentAvailable(self::NativeSupervisorDeploymentAvailable);
            self::assertTrustedLauncherFile();

            $input = self::parseArguments($arguments);
            $policy = self::loadPolicy();

            self::assertCurrentRuntimeMatchesPolicy($policy);

            $verified = self::verifySnapshot($policy, $input['manifest_sha256']);

            // A complete second pass closes mutable-tree races before secrets are opened.
            self::verifySnapshot($policy, $input['manifest_sha256']);

            return self::executeVerifiedProbe(
                $policy,
                $verified,
                $input['credential_file'],
                $input['authorization_file'],
            );
        } catch (Throwable $throwable) {
            self::writeError("pre-autoload verification denied: {$throwable->getMessage()}\n");

            return 78;
        }
    }

    private static function assertBootstrapRuntimeIsClean(): void
    {
        if (\PHP_SAPI !== 'cli') {
            throw new RuntimeException('the launcher is CLI-only');
        }

        if (\php_ini_loaded_file() !== false) {
            throw new RuntimeException('PHP must be invoked with -n (loaded ini detected)');
        }

        $scanned = \php_ini_scanned_files();

        if ($scanned !== false && \trim($scanned) !== '') {
            throw new RuntimeException('PHP must be invoked with -n (scanned ini detected)');
        }

        if ((string) \ini_get('auto_prepend_file') !== '') {
            throw new RuntimeException('auto_prepend_file must be empty');
        }

        if ((string) \ini_get('auto_append_file') !== '') {
            throw new RuntimeException('auto_append_file must be empty');
        }

        if (! \function_exists('posix_geteuid')) {
            throw new RuntimeException('the POSIX extension is required');
        }

        if (! \function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('the Sodium extension is required');
        }

        self::assertRuntimeOwnerIsDistinct(self::RequireDistinctRuntimeOwner);
    }

    private static function clearInheritedEnvironment(): void
    {
        $environment = \getenv();

        foreach (\array_keys($environment) as $name) {
            if (! self::isEnvironmentName($name)) {
                throw new RuntimeException('invalid inherited environment name');
            }

            if (! \putenv($name)) {
                throw new RuntimeException('cannot clear the inherited environment');
            }
        }

        $_ENV = [];
    }

    private static function assertRuntimeOwnerIsDistinct(bool $required): void
    {
        if ($required && \posix_geteuid() === self::TrustedOwnerUid) {
            throw new RuntimeException('the probe must not run as the trust-root owner');
        }
    }

    private static function assertNativeSupervisorDeploymentAvailable(bool $available): void
    {
        if (! $available) {
            throw new RuntimeException('native root supervisor deployment is unavailable; live execution is fail-closed');
        }
    }

    private static function assertTrustedLauncherFile(): void
    {
        self::assertTrustedRegularFile(__FILE__, false, null);
        self::assertTrustedAncestors(\dirname(__FILE__));
    }

    /**
     * @param  list<string>  $arguments
     * @return array{manifest_sha256: string, credential_file: string, authorization_file: string}
     */
    private static function parseArguments(array $arguments): array
    {
        if (\count($arguments) !== 4) {
            throw new RuntimeException('expected manifest hash, credential file, and authorization file');
        }

        $manifestSha256 = self::argumentValue($arguments[1], '--manifest-sha256=', 64);
        $credentialFile = self::argumentValue($arguments[2], '--credential-file=', 4_096);
        $authorizationFile = self::argumentValue($arguments[3], '--authorization-file=', 4_096);

        self::assertSha256($manifestSha256, 'manifest hash');
        self::assertCanonicalAbsoluteInputPath($credentialFile, 'credential file');
        self::assertCanonicalAbsoluteInputPath($authorizationFile, 'authorization file');

        return [
            'manifest_sha256' => $manifestSha256,
            'credential_file' => $credentialFile,
            'authorization_file' => $authorizationFile,
        ];
    }

    private static function argumentValue(string $argument, string $prefix, int $maximumBytes): string
    {
        if (\strlen($argument) > $maximumBytes + \strlen($prefix)) {
            throw new RuntimeException('an argument exceeds its byte limit');
        }

        if (! \str_starts_with($argument, $prefix)) {
            throw new RuntimeException("expected {$prefix}");
        }

        $value = \substr($argument, \strlen($prefix));

        if ($value === '') {
            throw new RuntimeException("{$prefix} cannot be empty");
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function loadPolicy(): array
    {
        self::assertTrustedAncestors(\dirname(self::PolicyPath));
        $raw = self::readTrustedFile(self::PolicyPath, self::MaximumPolicyBytes, false);
        $policy = StrictJson::decode(
            $raw,
            self::MaximumPolicyDepth,
            self::MaximumPolicyNodes,
        );

        self::assertExactKeys($policy, [
            'contract',
            'version',
            'manifest_root',
            'snapshot_root',
            'public_key_path',
            'public_key_sha256',
            'php_executable',
            'php_executable_sha256',
            'launcher_sha256',
            'probe_entrypoint',
            'limits',
        ], 'policy');

        if ($policy['contract'] !== self::PolicyContract || $policy['version'] !== self::Version) {
            throw new RuntimeException('unsupported policy contract or version');
        }

        foreach (['manifest_root', 'snapshot_root', 'public_key_path', 'php_executable'] as $field) {
            self::assertCanonicalAbsolutePolicyPath($policy[$field], "policy.{$field}");
        }

        self::assertSha256Value($policy['public_key_sha256'], 'policy.public_key_sha256');
        self::assertSha256Value($policy['php_executable_sha256'], 'policy.php_executable_sha256');
        self::assertSha256Value($policy['launcher_sha256'], 'policy.launcher_sha256');
        self::assertCanonicalRelativePathValue($policy['probe_entrypoint'], 'policy.probe_entrypoint', self::MaximumPathBytes);

        if (! \str_starts_with($policy['probe_entrypoint'], 'tests/Contract/') || ! \str_ends_with($policy['probe_entrypoint'], '.php')) {
            throw new RuntimeException('the policy entrypoint must be a dedicated Contract PHP file');
        }

        self::assertPolicyLimits($policy['limits']);
        self::assertTrustedDirectory($policy['manifest_root'], false);
        self::assertTrustedDirectory($policy['snapshot_root'], false);
        self::assertTrustedAncestors($policy['manifest_root']);
        self::assertTrustedAncestors($policy['snapshot_root']);

        $publicKey = self::readTrustedFile($policy['public_key_path'], self::PublicKeyBytes, true);

        if (\strlen($publicKey) !== self::PublicKeyBytes) {
            throw new RuntimeException('the pinned public key has an invalid length');
        }

        if (! \hash_equals($policy['public_key_sha256'], \hash('sha256', $publicKey))) {
            throw new RuntimeException('the pinned public key hash does not match policy');
        }

        self::assertProtectedRuntimeFile($policy['php_executable'], $policy['php_executable_sha256']);

        $launcher = self::hashOpenedFile(__FILE__, self::MaximumFileBytes, false);

        if (! \hash_equals($policy['launcher_sha256'], $launcher['sha256'])) {
            throw new RuntimeException('the installed launcher hash does not match policy');
        }

        $policy['_policy_sha256'] = \hash('sha256', $raw);

        return $policy;
    }

    private static function assertPolicyLimits(mixed $limits): void
    {
        if (! \is_array($limits)) {
            throw new RuntimeException('policy.limits must be an object');
        }

        self::assertExactKeys($limits, [
            'manifest_bytes',
            'manifest_depth',
            'manifest_nodes',
            'tree_files',
            'tree_directories',
            'path_bytes',
            'file_bytes',
            'tree_bytes',
            'credential_bytes',
            'authorization_bytes',
        ], 'policy.limits');

        self::assertBoundedPositiveInteger($limits['manifest_bytes'], self::MaximumManifestBytes, 'manifest_bytes');
        self::assertBoundedPositiveInteger($limits['manifest_depth'], self::MaximumManifestDepth, 'manifest_depth');
        self::assertBoundedPositiveInteger($limits['manifest_nodes'], self::MaximumManifestNodes, 'manifest_nodes');
        self::assertBoundedPositiveInteger($limits['tree_files'], self::MaximumTreeFiles, 'tree_files');
        self::assertBoundedPositiveInteger($limits['tree_directories'], self::MaximumTreeDirectories, 'tree_directories');
        self::assertBoundedPositiveInteger($limits['path_bytes'], self::MaximumPathBytes, 'path_bytes');
        self::assertBoundedPositiveInteger($limits['file_bytes'], self::MaximumFileBytes, 'file_bytes');
        self::assertBoundedPositiveInteger($limits['tree_bytes'], self::MaximumTreeBytes, 'tree_bytes');
        self::assertBoundedPositiveInteger($limits['credential_bytes'], self::MaximumSecretBytes, 'credential_bytes');
        self::assertBoundedPositiveInteger($limits['authorization_bytes'], self::MaximumSecretBytes, 'authorization_bytes');
    }

    /** @param array<string, mixed> $policy */
    private static function assertCurrentRuntimeMatchesPolicy(array $policy): void
    {
        $phpBinary = \realpath(\PHP_BINARY);

        if ($phpBinary === false || ! \hash_equals($policy['php_executable'], $phpBinary)) {
            throw new RuntimeException('the current PHP executable does not match policy');
        }

        self::assertProtectedRuntimeFile($phpBinary, $policy['php_executable_sha256']);
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array{snapshot: string, entrypoint: string, manifest_sha256: string}
     */
    private static function verifySnapshot(array $policy, string $manifestSha256): array
    {
        $manifestPath = "{$policy['manifest_root']}/{$manifestSha256}.manifest.json";
        $signaturePath = "{$policy['manifest_root']}/{$manifestSha256}.manifest.sig";
        $snapshotPath = "{$policy['snapshot_root']}/{$manifestSha256}";

        $manifestRaw = self::readTrustedFile($manifestPath, $policy['limits']['manifest_bytes'], true);

        if (! \hash_equals($manifestSha256, \hash('sha256', $manifestRaw))) {
            throw new RuntimeException('raw manifest bytes do not match the content address');
        }

        $signature = self::readTrustedFile($signaturePath, self::SignatureBytes, true);
        $publicKey = self::readTrustedFile($policy['public_key_path'], self::PublicKeyBytes, true);

        if (\strlen($signature) !== self::SignatureBytes) {
            throw new RuntimeException('the detached signature has an invalid length');
        }

        if (\strlen($publicKey) !== self::PublicKeyBytes) {
            throw new RuntimeException('the pinned public key has an invalid length');
        }

        if (! \sodium_crypto_sign_verify_detached($signature, $manifestRaw, $publicKey)) {
            throw new RuntimeException('the raw manifest signature is invalid');
        }

        $manifest = StrictJson::decode(
            $manifestRaw,
            $policy['limits']['manifest_depth'],
            $policy['limits']['manifest_nodes'],
        );

        self::assertManifestSchema($manifest, $policy);
        self::assertTrustedDirectory($snapshotPath, true);
        self::assertTrustedAncestors($snapshotPath);

        $snapshotRealPath = \realpath($snapshotPath);

        if ($snapshotRealPath === false || ! \hash_equals($snapshotPath, $snapshotRealPath)) {
            throw new RuntimeException('snapshot path is not canonical');
        }

        $tree = self::scanSnapshot($snapshotPath, $policy);

        if ($tree['directories'] !== $manifest['directories']) {
            throw new RuntimeException('snapshot directory inventory does not match manifest');
        }

        if ($tree['files'] !== $manifest['files']) {
            throw new RuntimeException('snapshot file inventory does not match manifest');
        }

        self::assertManifestBindings($manifest, $tree, $snapshotPath, $policy);

        $entrypoint = "{$snapshotPath}/{$manifest['entrypoint']}";
        $entrypointRealPath = \realpath($entrypoint);

        if ($entrypointRealPath === false || ! \hash_equals($entrypoint, $entrypointRealPath)) {
            throw new RuntimeException('probe entrypoint is not a canonical regular file');
        }

        return [
            'snapshot' => $snapshotPath,
            'entrypoint' => $entrypoint,
            'manifest_sha256' => $manifestSha256,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $policy
     */
    private static function assertManifestSchema(array $manifest, array $policy): void
    {
        self::assertExactKeys($manifest, [
            'contract',
            'version',
            'repository',
            'entrypoint',
            'bindings',
            'runtime',
            'directories',
            'files',
        ], 'manifest');

        if ($manifest['contract'] !== self::ManifestContract || $manifest['version'] !== self::Version) {
            throw new RuntimeException('unsupported manifest contract or version');
        }

        self::assertRepositorySchema($manifest['repository']);
        self::assertCanonicalRelativePathValue($manifest['entrypoint'], 'manifest.entrypoint', $policy['limits']['path_bytes']);

        if (! \hash_equals($policy['probe_entrypoint'], $manifest['entrypoint'])) {
            throw new RuntimeException('manifest entrypoint does not match pinned policy');
        }

        self::assertBindingsSchema($manifest['bindings'], $policy['limits']['path_bytes']);
        self::assertRuntimeSchema($manifest['runtime'], $policy);
        self::assertDirectoryRecords($manifest['directories'], $policy);
        self::assertFileRecords($manifest['files'], $policy);

        if (! \in_array($manifest['entrypoint'], $manifest['bindings']['harness_files'], true)) {
            throw new RuntimeException('the entrypoint is not bound as harness source');
        }
    }

    private static function assertRepositorySchema(mixed $repository): void
    {
        if (! \is_array($repository)) {
            throw new RuntimeException('manifest.repository must be an object');
        }

        self::assertExactKeys($repository, ['commit'], 'manifest.repository');

        if (! \is_string($repository['commit']) || \preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $repository['commit']) !== 1) {
            throw new RuntimeException('manifest.repository.commit must be a canonical Git object id');
        }
    }

    private static function assertBindingsSchema(mixed $bindings, int $maximumPathBytes): void
    {
        if (! \is_array($bindings)) {
            throw new RuntimeException('manifest.bindings must be an object');
        }

        self::assertExactKeys($bindings, [
            'snapshot_tree_sha256',
            'composer_lock_sha256',
            'vendor_tree_sha256',
            'installed_packages_sha256',
            'policy_sha256',
            'public_key_sha256',
            'launcher_sha256',
            'source_files',
            'harness_files',
            'behavior_files',
            'composer_bootstrap_files',
        ], 'manifest.bindings');

        foreach ([
            'snapshot_tree_sha256',
            'composer_lock_sha256',
            'vendor_tree_sha256',
            'installed_packages_sha256',
            'policy_sha256',
            'public_key_sha256',
            'launcher_sha256',
        ] as $field) {
            self::assertSha256Value($bindings[$field], "manifest.bindings.{$field}");
        }

        self::assertSortedUniquePathList($bindings['source_files'], 'source_files', $maximumPathBytes);
        self::assertSortedUniquePathList($bindings['harness_files'], 'harness_files', $maximumPathBytes);
        self::assertSortedUniquePathList($bindings['behavior_files'], 'behavior_files', $maximumPathBytes);
        self::assertSortedUniquePathList($bindings['composer_bootstrap_files'], 'composer_bootstrap_files', $maximumPathBytes);
    }

    /** @param array<string, mixed> $policy */
    private static function assertRuntimeSchema(mixed $runtime, array $policy): void
    {
        if (! \is_array($runtime)) {
            throw new RuntimeException('manifest.runtime must be an object');
        }

        self::assertExactKeys($runtime, [
            'php_executable',
            'php_executable_sha256',
            'php_version',
            'php_version_id',
            'sapi',
            'arguments',
            'ini',
            'extensions',
            'zend_extensions',
        ], 'manifest.runtime');

        if (! \is_array($runtime['arguments']) || $runtime['arguments'] !== ['-n']) {
            throw new RuntimeException('manifest.runtime.arguments must be exactly [-n]');
        }

        if (! \is_array($runtime['ini'])) {
            throw new RuntimeException('manifest.runtime.ini must be an object');
        }

        self::assertExactKeys($runtime['ini'], [
            'loaded_file',
            'scanned_files',
            'auto_prepend_file',
            'auto_append_file',
        ], 'manifest.runtime.ini');

        $expectedIni = [
            'loaded_file' => false,
            'scanned_files' => false,
            'auto_prepend_file' => '',
            'auto_append_file' => '',
        ];

        $expected = [
            'php_executable' => $policy['php_executable'],
            'php_executable_sha256' => $policy['php_executable_sha256'],
            'php_version' => \PHP_VERSION,
            'php_version_id' => \PHP_VERSION_ID,
            'sapi' => 'cli',
            'arguments' => ['-n'],
            'ini' => $expectedIni,
            'extensions' => self::loadedExtensions(false),
            'zend_extensions' => self::loadedExtensions(true),
        ];

        if ($runtime !== $expected) {
            throw new RuntimeException('manifest runtime does not match the verified launcher runtime');
        }
    }

    /** @param array<string, mixed> $policy */
    private static function assertDirectoryRecords(mixed $directories, array $policy): void
    {
        if (! \is_array($directories) || ! \array_is_list($directories)) {
            throw new RuntimeException('manifest.directories must be a list');
        }

        if (\count($directories) > $policy['limits']['tree_directories']) {
            throw new RuntimeException('manifest has too many directories');
        }

        $previous = null;

        foreach ($directories as $record) {
            if (! \is_array($record)) {
                throw new RuntimeException('a directory record is not an object');
            }

            self::assertExactKeys($record, ['path', 'type', 'mode'], 'manifest.directory');
            self::assertCanonicalRelativePathValue($record['path'], 'manifest.directory.path', $policy['limits']['path_bytes']);

            if ($record['type'] !== 'directory') {
                throw new RuntimeException('a directory record has an invalid type');
            }

            self::assertModeValue($record['mode'], 'manifest.directory.mode');

            if (((int) \octdec($record['mode']) & 0o222) !== 0) {
                throw new RuntimeException('snapshot directories must be OS read-only');
            }

            self::assertStrictlyIncreasingPath($previous, $record['path'], 'directories');
            $previous = $record['path'];
        }
    }

    /** @param array<string, mixed> $policy */
    private static function assertFileRecords(mixed $files, array $policy): void
    {
        if (! \is_array($files) || ! \array_is_list($files)) {
            throw new RuntimeException('manifest.files must be a list');
        }

        if (\count($files) > $policy['limits']['tree_files']) {
            throw new RuntimeException('manifest has too many files');
        }

        $previous = null;
        $totalBytes = 0;

        foreach ($files as $record) {
            if (! \is_array($record)) {
                throw new RuntimeException('a file record is not an object');
            }

            self::assertExactKeys($record, ['path', 'type', 'mode', 'size', 'sha256'], 'manifest.file');
            self::assertCanonicalRelativePathValue($record['path'], 'manifest.file.path', $policy['limits']['path_bytes']);

            if ($record['type'] !== 'file') {
                throw new RuntimeException('a file record has an invalid type');
            }

            self::assertModeValue($record['mode'], 'manifest.file.mode');

            if (((int) \octdec($record['mode']) & 0o222) !== 0) {
                throw new RuntimeException('snapshot files must be OS read-only');
            }

            if (! \is_int($record['size']) || $record['size'] < 0 || $record['size'] > $policy['limits']['file_bytes']) {
                throw new RuntimeException('a file record has an invalid size');
            }

            self::assertSha256Value($record['sha256'], 'manifest.file.sha256');
            self::assertStrictlyIncreasingPath($previous, $record['path'], 'files');
            $previous = $record['path'];
            $totalBytes += $record['size'];

            if ($totalBytes > $policy['limits']['tree_bytes']) {
                throw new RuntimeException('manifest tree exceeds its byte limit');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array{directories: list<array{path: string, type: string, mode: string}>, files: list<array{path: string, type: string, mode: string, size: int, sha256: string}>}
     */
    private static function scanSnapshot(string $snapshotPath, array $policy): array
    {
        $directories = [];
        $files = [];
        $totalBytes = 0;

        self::scanDirectory(
            $snapshotPath,
            '',
            $policy,
            $directories,
            $files,
            $totalBytes,
        );

        \usort($directories, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);
        \usort($files, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);

        return ['directories' => $directories, 'files' => $files];
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  list<array{path: string, type: string, mode: string}>  $directories
     * @param  list<array{path: string, type: string, mode: string, size: int, sha256: string}>  $files
     */
    private static function scanDirectory(
        string $absoluteDirectory,
        string $relativeDirectory,
        array $policy,
        array &$directories,
        array &$files,
        int &$totalBytes,
    ): void {
        $handle = \opendir($absoluteDirectory);

        if ($handle === false) {
            throw new RuntimeException('cannot open a snapshot directory');
        }

        try {
            while (($name = \readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $relativePath = $relativeDirectory === '' ? $name : "{$relativeDirectory}/{$name}";
                self::assertCanonicalRelativePath($relativePath, 'snapshot path', $policy['limits']['path_bytes']);
                $absolutePath = "{$absoluteDirectory}/{$name}";
                $stat = self::trustedLstat($absolutePath);
                $type = $stat['mode'] & 0o170000;

                if ($type === 0o040000) {
                    self::assertSnapshotMetadata($stat, false);
                    $directories[] = [
                        'path' => $relativePath,
                        'type' => 'directory',
                        'mode' => self::formatMode($stat['mode']),
                    ];

                    if (\count($directories) > $policy['limits']['tree_directories']) {
                        throw new RuntimeException('snapshot has too many directories');
                    }

                    self::scanDirectory($absolutePath, $relativePath, $policy, $directories, $files, $totalBytes);

                    continue;
                }

                if ($type !== 0o100000) {
                    throw new RuntimeException('snapshot contains a symlink or special file');
                }

                self::assertSnapshotMetadata($stat, true);
                $hashed = self::hashOpenedFile($absolutePath, $policy['limits']['file_bytes'], true);
                $totalBytes += $hashed['size'];

                if ($totalBytes > $policy['limits']['tree_bytes']) {
                    throw new RuntimeException('snapshot exceeds its total byte limit');
                }

                $files[] = [
                    'path' => $relativePath,
                    'type' => 'file',
                    'mode' => self::formatMode($stat['mode']),
                    'size' => $hashed['size'],
                    'sha256' => $hashed['sha256'],
                ];

                if (\count($files) > $policy['limits']['tree_files']) {
                    throw new RuntimeException('snapshot has too many files');
                }
            }
        } finally {
            \closedir($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array{directories: list<array{path: string, type: string, mode: string}>, files: list<array{path: string, type: string, mode: string, size: int, sha256: string}>}  $tree
     * @param  array<string, mixed>  $policy
     */
    private static function assertManifestBindings(array $manifest, array $tree, string $snapshotPath, array $policy): void
    {
        $files = $tree['files'];
        $bindings = $manifest['bindings'];
        $byPath = [];

        foreach ($files as $file) {
            $byPath[$file['path']] = $file;
        }

        foreach ([
            'bin/fakturownia-live-evidence-launcher.php',
            'composer.json',
            'composer.lock',
            'phpunit.xml.dist',
            'tests/Pest.php',
            $manifest['entrypoint'],
            'vendor/composer/installed.json',
            'vendor/autoload.php',
        ] as $required) {
            if (! isset($byPath[$required])) {
                throw new RuntimeException("required dependency binding {$required} is absent");
            }
        }

        if (! \hash_equals($bindings['composer_lock_sha256'], $byPath['composer.lock']['sha256'])) {
            throw new RuntimeException('composer.lock binding does not match');
        }

        if (! \hash_equals($bindings['installed_packages_sha256'], $byPath['vendor/composer/installed.json']['sha256'])) {
            throw new RuntimeException('installed package binding does not match');
        }

        if (! \hash_equals($bindings['policy_sha256'], $policy['_policy_sha256'])) {
            throw new RuntimeException('OS trust policy binding does not match');
        }

        if (! \hash_equals($bindings['public_key_sha256'], $policy['public_key_sha256'])) {
            throw new RuntimeException('operator public-key binding does not match');
        }

        if (! \hash_equals($bindings['launcher_sha256'], $policy['launcher_sha256'])) {
            throw new RuntimeException('pre-autoload launcher binding does not match policy');
        }

        if (! \hash_equals($bindings['launcher_sha256'], $byPath['bin/fakturownia-live-evidence-launcher.php']['sha256'])) {
            throw new RuntimeException('snapshot launcher binding does not match the installed trust root');
        }

        $sourceFiles = self::pathsWithPrefix($files, 'src/');
        $harnessFiles = self::pathsWithPrefix($files, 'tests/Contract/');
        $behaviorFiles = self::recordsWithoutPrefix($files, 'vendor/');
        $vendorFiles = self::recordsWithPrefix($files, 'vendor/');
        $composerBootstrapFiles = [];

        foreach ($files as $file) {
            if ($file['path'] === 'vendor/autoload.php') {
                $composerBootstrapFiles[] = $file['path'];

                continue;
            }

            if (\str_starts_with($file['path'], 'vendor/composer/') && \str_ends_with($file['path'], '.php')) {
                $composerBootstrapFiles[] = $file['path'];
            }
        }

        \sort($composerBootstrapFiles, \SORT_STRING);

        if ($sourceFiles !== $bindings['source_files']) {
            throw new RuntimeException('source-file binding is not exact');
        }

        if ($harnessFiles !== $bindings['harness_files']) {
            throw new RuntimeException('harness-file binding is not exact');
        }

        if (\array_column($behaviorFiles, 'path') !== $bindings['behavior_files']) {
            throw new RuntimeException('first-party behavior-file binding is not exact');
        }

        if ($composerBootstrapFiles !== $bindings['composer_bootstrap_files']) {
            throw new RuntimeException('Composer bootstrap binding is not exact');
        }

        if (! \hash_equals($bindings['vendor_tree_sha256'], self::recordsSha256($vendorFiles))) {
            throw new RuntimeException('vendor tree binding does not match');
        }

        if (! \hash_equals($bindings['snapshot_tree_sha256'], self::recordsSha256($files))) {
            throw new RuntimeException('snapshot tree binding does not match');
        }

        self::assertInstalledPackagesMatchLock($snapshotPath, $policy);
    }

    /**
     * @param  list<array{path: string, type: string, mode: string, size: int, sha256: string}>  $files
     * @return list<string>
     */
    private static function pathsWithPrefix(array $files, string $prefix): array
    {
        $paths = [];

        foreach ($files as $file) {
            if (\str_starts_with($file['path'], $prefix)) {
                $paths[] = $file['path'];
            }
        }

        return $paths;
    }

    /**
     * @param  list<array{path: string, type: string, mode: string, size: int, sha256: string}>  $files
     * @return list<array{path: string, type: string, mode: string, size: int, sha256: string}>
     */
    private static function recordsWithPrefix(array $files, string $prefix): array
    {
        return \array_values(\array_filter(
            $files,
            static fn (array $file): bool => \str_starts_with($file['path'], $prefix),
        ));
    }

    /**
     * @param  list<array{path: string, type: string, mode: string, size: int, sha256: string}>  $files
     * @return list<array{path: string, type: string, mode: string, size: int, sha256: string}>
     */
    private static function recordsWithoutPrefix(array $files, string $prefix): array
    {
        return \array_values(\array_filter(
            $files,
            static fn (array $file): bool => ! \str_starts_with($file['path'], $prefix),
        ));
    }

    /** @param array<string, mixed> $policy */
    private static function assertInstalledPackagesMatchLock(string $snapshotPath, array $policy): void
    {
        $lockRaw = self::readTrustedFile("{$snapshotPath}/composer.lock", $policy['limits']['file_bytes'], true);
        $installedRaw = self::readTrustedFile("{$snapshotPath}/vendor/composer/installed.json", $policy['limits']['file_bytes'], true);
        $lock = StrictJson::decode($lockRaw, $policy['limits']['manifest_depth'], $policy['limits']['manifest_nodes']);
        $installed = StrictJson::decode($installedRaw, $policy['limits']['manifest_depth'], $policy['limits']['manifest_nodes']);

        if (! isset($lock['packages'], $lock['packages-dev']) || ! \is_array($lock['packages']) || ! \array_is_list($lock['packages']) || ! \is_array($lock['packages-dev']) || ! \array_is_list($lock['packages-dev'])) {
            throw new RuntimeException('composer.lock has an invalid package inventory');
        }

        if (! isset($installed['packages']) || ! \is_array($installed['packages']) || ! \array_is_list($installed['packages'])) {
            throw new RuntimeException('installed.json has an invalid package inventory');
        }

        $lockedPackages = self::normalizePackageSet([...$lock['packages'], ...$lock['packages-dev']], 'composer.lock');
        $installedPackages = self::normalizePackageSet($installed['packages'], 'installed.json');

        if ($lockedPackages !== $installedPackages) {
            throw new RuntimeException('installed package set does not exactly match composer.lock');
        }
    }

    /**
     * @param  list<mixed>  $packages
     * @return list<array{name: string, version: string, source: array{type: ?string, url: ?string, reference: ?string, shasum: ?string}, dist: array{type: ?string, url: ?string, reference: ?string, shasum: ?string}}>
     */
    private static function normalizePackageSet(array $packages, string $document): array
    {
        $normalized = [];

        foreach ($packages as $package) {
            if (! \is_array($package) || ! isset($package['name'], $package['version']) || ! \is_string($package['name']) || ! \is_string($package['version'])) {
                throw new RuntimeException("{$document} contains an invalid package record");
            }

            if (isset($normalized[$package['name']])) {
                throw new RuntimeException("{$document} contains a duplicate package name");
            }

            $normalized[$package['name']] = [
                'name' => $package['name'],
                'version' => $package['version'],
                'source' => self::normalizePackageOrigin($package['source'] ?? null, false, $document),
                'dist' => self::normalizePackageOrigin($package['dist'] ?? null, true, $document),
            ];
        }

        \ksort($normalized, \SORT_STRING);

        return \array_values($normalized);
    }

    /** @return array{type: ?string, url: ?string, reference: ?string, shasum: ?string} */
    private static function normalizePackageOrigin(mixed $origin, bool $distribution, string $document): array
    {
        if ($origin === null) {
            return ['type' => null, 'url' => null, 'reference' => null, 'shasum' => null];
        }

        if (! \is_array($origin)) {
            throw new RuntimeException("{$document} contains an invalid package origin");
        }

        $result = [
            'type' => self::nullableStringField($origin, 'type', $document),
            'url' => self::nullableStringField($origin, 'url', $document),
            'reference' => self::nullableStringField($origin, 'reference', $document),
            'shasum' => null,
        ];

        if ($distribution) {
            $result['shasum'] = self::nullableStringField($origin, 'shasum', $document);
        }

        return $result;
    }

    /** @param array<mixed> $value */
    private static function nullableStringField(array $value, string $field, string $document): ?string
    {
        $fieldValue = $value[$field] ?? null;

        if ($fieldValue !== null && ! \is_string($fieldValue)) {
            throw new RuntimeException("{$document} contains a non-string package {$field}");
        }

        return $fieldValue;
    }

    /** @return list<string> */
    private static function loadedExtensions(bool $zend): array
    {
        $extensions = \get_loaded_extensions($zend);
        \sort($extensions, \SORT_STRING);

        return $extensions;
    }

    /** @param list<array{path: string, type: string, mode: string, size: int, sha256: string}> $records */
    private static function recordsSha256(array $records): string
    {
        return \hash('sha256', CanonicalJson::encode([
            'contract' => 'cieplik206.fakturownia.snapshot-file-set',
            'version' => self::Version,
            'files' => $records,
        ]));
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  array{snapshot: string, entrypoint: string, manifest_sha256: string}  $verified
     */
    private static function executeVerifiedProbe(
        array $policy,
        array $verified,
        string $credentialPath,
        string $authorizationPath,
    ): int {
        $credential = self::openSecretAfterVerification($credentialPath, $policy['limits']['credential_bytes']);
        $authorization = self::openSecretAfterVerification($authorizationPath, $policy['limits']['authorization_bytes']);

        $descriptors = [
            0 => \STDIN,
            1 => \STDOUT,
            2 => \STDERR,
            3 => $credential,
            4 => $authorization,
        ];
        $environment = [
            'PATH' => \dirname($policy['php_executable']),
            'LANG' => 'C',
            'LC_ALL' => 'C',
            'FAKTUROWNIA_PREAUTOLOAD_VERIFIED_MANIFEST_SHA256' => $verified['manifest_sha256'],
            'FAKTUROWNIA_CREDENTIAL_FD' => '3',
            'FAKTUROWNIA_AUTHORIZATION_FD' => '4',
        ];
        $command = [
            $policy['php_executable'],
            '-n',
            $verified['entrypoint'],
        ];
        $pipes = [];

        try {
            $process = \proc_open(
                $command,
                $descriptors,
                $pipes,
                $verified['snapshot'],
                $environment,
                ['bypass_shell' => true],
            );

            if (! \is_resource($process)) {
                throw new RuntimeException('cannot execute the verified probe');
            }

            return \proc_close($process);
        } finally {
            \fclose($credential);
            \fclose($authorization);
        }
    }

    /** @return resource */
    private static function openSecretAfterVerification(string $path, int $maximumBytes)
    {
        self::assertCanonicalAbsoluteInputPath($path, 'secret input');
        $before = self::trustedLstat($path);

        if (($before['mode'] & 0o170000) !== 0o100000 || $before['nlink'] !== 1) {
            throw new RuntimeException('secret input must be a non-hardlinked regular file');
        }

        if ($before['size'] < 1 || $before['size'] > $maximumBytes) {
            throw new RuntimeException('secret input exceeds its byte bounds');
        }

        if (($before['mode'] & 0o077) !== 0) {
            throw new RuntimeException('secret input must not be accessible by group or others');
        }

        $stream = \fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException('cannot open secret input');
        }

        $opened = \fstat($stream);

        if (! \is_array($opened) || ! self::sameFileIdentity($before, $opened)) {
            \fclose($stream);

            throw new RuntimeException('secret input changed while opening');
        }

        return $stream;
    }

    private static function assertProtectedRuntimeFile(string $path, string $sha256): void
    {
        self::assertRuntimeAncestors($path, self::EnforceRuntimeAncestorOwnership);

        $hashed = self::hashOpenedFile($path, self::MaximumFileBytes, false);

        if (! \hash_equals($sha256, $hashed['sha256'])) {
            throw new RuntimeException('the PHP executable hash does not match policy');
        }
    }

    private static function assertRuntimeAncestors(string $path, bool $required): void
    {
        if (! $required) {
            return;
        }

        self::assertTrustedAncestors(\dirname($path));
    }

    private static function readTrustedFile(string $path, int $maximumBytes, bool $mustBeOsReadonly): string
    {
        self::assertTrustedRegularFile($path, $mustBeOsReadonly, $maximumBytes);
        $before = self::trustedLstat($path);
        $stream = \fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException('cannot open a trusted file');
        }

        try {
            $opened = \fstat($stream);

            if (! \is_array($opened) || ! self::sameFileIdentity($before, $opened)) {
                throw new RuntimeException('trusted file changed while opening');
            }

            $contents = '';

            while (! \feof($stream)) {
                $chunk = \fread($stream, 65_536);

                if ($chunk === false) {
                    throw new RuntimeException('cannot read a trusted file');
                }

                $contents .= $chunk;

                if (\strlen($contents) > $maximumBytes) {
                    throw new RuntimeException('trusted file exceeds its byte limit');
                }
            }

            $after = \fstat($stream);
            $afterPath = self::trustedLstat($path);

            if (! \is_array($after) || ! self::sameFileIdentity($before, $after) || ! self::sameFileIdentity($before, $afterPath)) {
                throw new RuntimeException('trusted file changed while reading');
            }

            return $contents;
        } finally {
            \fclose($stream);
        }
    }

    /** @return array{size: int, sha256: string} */
    private static function hashOpenedFile(string $path, int $maximumBytes, bool $mustBeOsReadonly): array
    {
        self::assertTrustedRegularFile($path, $mustBeOsReadonly, $maximumBytes);
        $before = self::trustedLstat($path);
        $stream = \fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException('cannot open a file for hashing');
        }

        $hash = \hash_init('sha256');
        $bytes = 0;

        try {
            $opened = \fstat($stream);

            if (! \is_array($opened) || ! self::sameFileIdentity($before, $opened)) {
                throw new RuntimeException('file changed while opening for hashing');
            }

            while (! \feof($stream)) {
                $chunk = \fread($stream, 65_536);

                if ($chunk === false) {
                    throw new RuntimeException('cannot hash a trusted file');
                }

                $bytes += \strlen($chunk);

                if ($bytes > $maximumBytes) {
                    throw new RuntimeException('file exceeds its byte limit');
                }

                \hash_update($hash, $chunk);
            }

            $after = \fstat($stream);
            $afterPath = self::trustedLstat($path);

            if (! \is_array($after) || ! self::sameFileIdentity($before, $after) || ! self::sameFileIdentity($before, $afterPath)) {
                throw new RuntimeException('file changed while hashing');
            }

            return ['size' => $bytes, 'sha256' => \hash_final($hash)];
        } finally {
            \fclose($stream);
        }
    }

    private static function assertTrustedRegularFile(string $path, bool $mustBeOsReadonly, ?int $maximumBytes): void
    {
        $stat = self::trustedLstat($path);

        if (($stat['mode'] & 0o170000) !== 0o100000) {
            throw new RuntimeException('trusted path is not a regular file');
        }

        if ($stat['nlink'] !== 1) {
            throw new RuntimeException('trusted files must not be hardlinked');
        }

        if ($stat['uid'] !== self::TrustedOwnerUid) {
            throw new RuntimeException('trusted file has an unexpected owner');
        }

        if (($stat['mode'] & 0o022) !== 0) {
            throw new RuntimeException('trusted file is writable by group or others');
        }

        if ($mustBeOsReadonly && ($stat['mode'] & 0o222) !== 0) {
            throw new RuntimeException('trusted file is not OS read-only');
        }

        if ($maximumBytes !== null && ($stat['size'] < 0 || $stat['size'] > $maximumBytes)) {
            throw new RuntimeException('trusted file exceeds its byte limit');
        }
    }

    private static function assertTrustedDirectory(string $path, bool $mustBeOsReadonly): void
    {
        $stat = self::trustedLstat($path);

        if (($stat['mode'] & 0o170000) !== 0o040000) {
            throw new RuntimeException('trusted path is not a directory');
        }

        if ($stat['uid'] !== self::TrustedOwnerUid) {
            throw new RuntimeException('trusted directory has an unexpected owner');
        }

        if (($stat['mode'] & 0o022) !== 0) {
            throw new RuntimeException('trusted directory is writable by group or others');
        }

        if ($mustBeOsReadonly && ($stat['mode'] & 0o222) !== 0) {
            throw new RuntimeException('snapshot directory is not OS read-only');
        }
    }

    /** @param array{mode: int, uid: int, nlink: int, size: int, dev: int, ino: int, gid: int, atime: int, mtime: int, ctime: int} $stat */
    private static function assertSnapshotMetadata(array $stat, bool $file): void
    {
        if ($stat['uid'] !== self::TrustedOwnerUid) {
            throw new RuntimeException('snapshot content has an unexpected owner');
        }

        if (($stat['mode'] & 0o222) !== 0) {
            throw new RuntimeException('snapshot content is not OS read-only');
        }

        if ($file && $stat['nlink'] !== 1) {
            throw new RuntimeException('snapshot contains a hardlinked file');
        }
    }

    private static function assertTrustedAncestors(string $path): void
    {
        if (! \str_starts_with($path, '/')) {
            throw new RuntimeException('trusted ancestor path is not absolute');
        }

        $current = '/';

        foreach (\explode('/', \trim($path, '/')) as $part) {
            if ($part === '') {
                continue;
            }

            $current = $current === '/' ? "/{$part}" : "{$current}/{$part}";
            $stat = self::trustedLstat($current);

            if (($stat['mode'] & 0o170000) !== 0o040000) {
                throw new RuntimeException('a trusted ancestor is not a directory');
            }

            if (! self::isTrustedAncestorOwner($stat['uid'], self::TrustedOwnerUid)) {
                throw new RuntimeException('a trusted ancestor has an unexpected owner');
            }

            if (($stat['mode'] & 0o022) !== 0) {
                throw new RuntimeException('a trusted ancestor is writable by group or others');
            }
        }
    }

    private static function isTrustedAncestorOwner(int $actualUid, int $configuredUid): bool
    {
        return $actualUid === 0 || $actualUid === $configuredUid;
    }

    /**
     * @return array{mode: int, uid: int, nlink: int, size: int, dev: int, ino: int, gid: int, atime: int, mtime: int, ctime: int}
     */
    private static function trustedLstat(string $path): array
    {
        \clearstatcache(true, $path);
        $stat = \lstat($path);

        if (! \is_array($stat)) {
            throw new RuntimeException('trusted path does not exist');
        }

        return [
            'dev' => (int) $stat['dev'],
            'ino' => (int) $stat['ino'],
            'mode' => (int) $stat['mode'],
            'nlink' => (int) $stat['nlink'],
            'uid' => (int) $stat['uid'],
            'gid' => (int) $stat['gid'],
            'size' => (int) $stat['size'],
            'atime' => (int) $stat['atime'],
            'mtime' => (int) $stat['mtime'],
            'ctime' => (int) $stat['ctime'],
        ];
    }

    /**
     * @param  array{mode: int, uid: int, nlink: int, size: int, dev: int, ino: int, gid: int, atime: int, mtime: int, ctime: int}  $left
     * @param  array<mixed>  $right
     */
    private static function sameFileIdentity(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
            if (! isset($right[$key]) || (int) $right[$key] !== $left[$key]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expected
     */
    private static function assertExactKeys(array $value, array $expected, string $label): void
    {
        $actual = \array_keys($value);
        \sort($actual, \SORT_STRING);
        \sort($expected, \SORT_STRING);

        if ($actual !== $expected) {
            throw new RuntimeException("{$label} has an unexpected key set");
        }
    }

    private static function assertSortedUniquePathList(mixed $paths, string $label, int $maximumBytes): void
    {
        if (! \is_array($paths) || ! \array_is_list($paths)) {
            throw new RuntimeException("manifest.bindings.{$label} must be a list");
        }

        $previous = null;

        foreach ($paths as $path) {
            self::assertCanonicalRelativePathValue($path, "manifest.bindings.{$label}", $maximumBytes);
            self::assertStrictlyIncreasingPath($previous, $path, $label);
            $previous = $path;
        }
    }

    private static function assertStrictlyIncreasingPath(?string $previous, string $current, string $label): void
    {
        if ($previous !== null && \strcmp($previous, $current) >= 0) {
            throw new RuntimeException("manifest {$label} must be sorted and unique");
        }
    }

    private static function assertCanonicalRelativePathValue(mixed $path, string $label, int $maximumBytes): void
    {
        if (! \is_string($path)) {
            throw new RuntimeException("{$label} must be a string");
        }

        self::assertCanonicalRelativePath($path, $label, $maximumBytes);
    }

    private static function assertCanonicalRelativePath(string $path, string $label, int $maximumBytes): void
    {
        if ($path === '' || \strlen($path) > $maximumBytes || \str_starts_with($path, '/')) {
            throw new RuntimeException("{$label} is not a bounded repository-relative path");
        }

        if (\str_contains($path, '\\') || \str_contains($path, "\0") || \preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new RuntimeException("{$label} contains forbidden bytes");
        }

        foreach (\explode('/', $path) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new RuntimeException("{$label} contains a non-canonical component");
            }
        }
    }

    private static function assertCanonicalAbsolutePolicyPath(mixed $path, string $label): void
    {
        if (! \is_string($path)) {
            throw new RuntimeException("{$label} must be a string");
        }

        self::assertCanonicalAbsoluteInputPath($path, $label);
        $realPath = \realpath($path);

        if ($realPath === false || ! \hash_equals($path, $realPath)) {
            throw new RuntimeException("{$label} must already be canonical");
        }
    }

    private static function assertCanonicalAbsoluteInputPath(string $path, string $label): void
    {
        if ($path === '' || \strlen($path) > 4_096 || ! \str_starts_with($path, '/')) {
            throw new RuntimeException("{$label} must be a bounded absolute path");
        }

        if (\str_contains($path, "\0") || \preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new RuntimeException("{$label} contains forbidden bytes");
        }

        foreach (\explode('/', \substr($path, 1)) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new RuntimeException("{$label} contains a non-canonical component");
            }
        }
    }

    private static function assertSha256Value(mixed $value, string $label): void
    {
        if (! \is_string($value)) {
            throw new RuntimeException("{$label} must be a string");
        }

        self::assertSha256($value, $label);
    }

    private static function assertSha256(string $value, string $label): void
    {
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new RuntimeException("{$label} must be a lowercase SHA-256 digest");
        }
    }

    private static function assertModeValue(mixed $mode, string $label): void
    {
        if (! \is_string($mode) || \preg_match('/\A[0-7]{4}\z/D', $mode) !== 1) {
            throw new RuntimeException("{$label} must be a four-digit octal mode");
        }
    }

    private static function assertBoundedPositiveInteger(mixed $value, int $maximum, string $label): void
    {
        if (! \is_int($value) || $value < 1 || $value > $maximum) {
            throw new RuntimeException("policy limit {$label} is invalid");
        }
    }

    private static function formatMode(int $mode): string
    {
        return \sprintf('%04o', $mode & 0o7777);
    }

    private static function isEnvironmentName(string $name): bool
    {
        return \preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) === 1;
    }

    private static function writeError(string $message): void
    {
        \fwrite(\STDERR, $message);
    }
}

final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        try {
            return \json_encode(
                self::normalize($value),
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('cannot canonicalize JSON', 0, $exception);
        }
    }

    private static function normalize(mixed $value): mixed
    {
        if (! \is_array($value)) {
            return $value;
        }

        if (\array_is_list($value)) {
            return \array_map(self::normalize(...), $value);
        }

        \ksort($value, \SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}

final class StrictJson
{
    private int $offset = 0;

    private int $nodes = 0;

    private function __construct(
        private readonly string $json,
        private readonly int $maximumDepth,
        private readonly int $maximumNodes,
    ) {}

    /** @return array<string, mixed> */
    public static function decode(string $json, int $maximumDepth, int $maximumNodes): array
    {
        $parser = new self($json, $maximumDepth, $maximumNodes);
        $value = $parser->parseValue(1);
        $parser->skipWhitespace();

        if ($parser->offset !== \strlen($json)) {
            throw new RuntimeException('JSON has trailing data');
        }

        if (! \is_array($value) || \array_is_list($value)) {
            throw new RuntimeException('JSON root must be an object');
        }

        return $value;
    }

    private function parseValue(int $depth): mixed
    {
        if ($depth > $this->maximumDepth) {
            throw new RuntimeException('JSON exceeds its depth limit');
        }

        $this->nodes++;

        if ($this->nodes > $this->maximumNodes) {
            throw new RuntimeException('JSON exceeds its node limit');
        }

        $this->skipWhitespace();
        $character = $this->json[$this->offset] ?? null;

        return match ($character) {
            '{' => $this->parseObject($depth),
            '[' => $this->parseArray($depth),
            '"' => $this->parseString(),
            't' => $this->parseLiteral('true', true),
            'f' => $this->parseLiteral('false', false),
            'n' => $this->parseLiteral('null', null),
            default => $this->parseNumber(),
        };
    }

    /** @return array<string, mixed> */
    private function parseObject(int $depth): array
    {
        $this->offset++;
        $this->skipWhitespace();
        $result = [];

        if (($this->json[$this->offset] ?? null) === '}') {
            $this->offset++;

            return $result;
        }

        while (true) {
            if (($this->json[$this->offset] ?? null) !== '"') {
                throw new RuntimeException('JSON object key must be a string');
            }

            $key = $this->parseString();

            if (\array_key_exists($key, $result)) {
                throw new RuntimeException('JSON contains a duplicate object key');
            }

            $this->skipWhitespace();

            if (($this->json[$this->offset] ?? null) !== ':') {
                throw new RuntimeException('JSON object is missing a colon');
            }

            $this->offset++;
            $result[$key] = $this->parseValue($depth + 1);
            $this->skipWhitespace();
            $separator = $this->json[$this->offset] ?? null;

            if ($separator === '}') {
                $this->offset++;

                return $result;
            }

            if ($separator !== ',') {
                throw new RuntimeException('JSON object is missing a separator');
            }

            $this->offset++;
            $this->skipWhitespace();
        }
    }

    /** @return list<mixed> */
    private function parseArray(int $depth): array
    {
        $this->offset++;
        $this->skipWhitespace();
        $result = [];

        if (($this->json[$this->offset] ?? null) === ']') {
            $this->offset++;

            return $result;
        }

        while (true) {
            $result[] = $this->parseValue($depth + 1);
            $this->skipWhitespace();
            $separator = $this->json[$this->offset] ?? null;

            if ($separator === ']') {
                $this->offset++;

                return $result;
            }

            if ($separator !== ',') {
                throw new RuntimeException('JSON array is missing a separator');
            }

            $this->offset++;
        }
    }

    private function parseString(): string
    {
        $start = $this->offset;
        $this->offset++;
        $escaped = false;
        $length = \strlen($this->json);

        while ($this->offset < $length) {
            $character = $this->json[$this->offset];
            $this->offset++;

            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($character === '\\') {
                $escaped = true;

                continue;
            }

            if ($character === '"') {
                $raw = \substr($this->json, $start, $this->offset - $start);

                try {
                    $decoded = \json_decode($raw, true, 2, \JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException('JSON string is invalid', 0, $exception);
                }

                if (! \is_string($decoded)) {
                    throw new RuntimeException('JSON string did not decode as a string');
                }

                return $decoded;
            }

            if (\ord($character) < 0x20) {
                throw new RuntimeException('JSON string contains a control byte');
            }
        }

        throw new RuntimeException('JSON string is unterminated');
    }

    private function parseLiteral(string $literal, mixed $value): mixed
    {
        if (\substr($this->json, $this->offset, \strlen($literal)) !== $literal) {
            throw new RuntimeException('JSON literal is invalid');
        }

        $this->offset += \strlen($literal);

        return $value;
    }

    private function parseNumber(): int|float
    {
        if (\preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $this->json, $matches, 0, $this->offset) !== 1) {
            throw new RuntimeException('JSON value is invalid');
        }

        $raw = $matches[0];
        $this->offset += \strlen($raw);

        try {
            $decoded = \json_decode($raw, true, 2, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new RuntimeException('JSON number is invalid', 0, $exception);
        }

        if (! \is_int($decoded) && ! \is_float($decoded)) {
            throw new RuntimeException('JSON number is outside the supported integer range');
        }

        return $decoded;
    }

    private function skipWhitespace(): void
    {
        $length = \strlen($this->json);

        while ($this->offset < $length) {
            $character = $this->json[$this->offset];

            if ($character !== ' ' && $character !== "\n" && $character !== "\r" && $character !== "\t") {
                return;
            }

            $this->offset++;
        }
    }
}

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

exit(PreAutoloadLauncher::run($arguments));
