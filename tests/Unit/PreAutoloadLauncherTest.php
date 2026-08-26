<?php

declare(strict_types=1);

/**
 * @return array{
 *     base: string,
 *     launcher: string,
 *     manifest: string,
 *     signature: string,
 *     snapshot: string,
 *     credential: string,
 *     authorization: string,
 *     sentinel: string,
 *     result: string,
 *     source: string,
 *     source_original: string,
 *     manifest_sha256: string
 * }
 */
function fakturowniaPreAutoloadFixture(?string $signedMutation = null): array
{
    $temporaryRoot = \realpath(\sys_get_temp_dir());

    if (! \is_string($temporaryRoot)) {
        throw new RuntimeException('Cannot resolve the system temporary directory.');
    }

    $base = $temporaryRoot.'/fakturownia-preautoload-'.\bin2hex(\random_bytes(12));
    $policyPath = "{$base}/preautoload-policy.json";
    $manifestRoot = "{$base}/manifests";
    $snapshotRoot = "{$base}/snapshots";
    $staging = "{$snapshotRoot}/staging";
    $publicKeyPath = "{$base}/operator-ed25519.pub";
    $launcherPath = "{$base}/fakturownia-live-evidence-launcher.php";
    $sentinelPath = "{$base}/autoload-executed";
    $resultPath = "{$base}/probe-result.json";
    $credentialPath = "{$base}/credential.json";
    $authorizationPath = "{$base}/authorization.json";

    foreach ([$base, $manifestRoot, $snapshotRoot, $staging] as $directory) {
        if (! \is_dir($directory) && ! \mkdir($directory, 0o700, true)) {
            throw new RuntimeException('Cannot create a pre-autoload test directory.');
        }
    }

    $repositoryLauncher = \dirname(__DIR__, 2).'/bin/fakturownia-live-evidence-launcher.php';
    $launcher = \file_get_contents($repositoryLauncher);

    if (! \is_string($launcher)) {
        throw new RuntimeException('Cannot read the repository launcher.');
    }

    $uid = \posix_geteuid();
    $instrumented = \str_replace(
        [
            "private const PolicyPath = '/etc/cieplik206/fakturownia-live-evidence/preautoload-policy.json';",
            'private const TrustedOwnerUid = 0;',
            'private const RequireDistinctRuntimeOwner = true;',
            'private const EnforceRuntimeAncestorOwnership = true;',
            'private const NativeSupervisorDeploymentAvailable = false;',
        ],
        [
            "private const PolicyPath = '{$policyPath}';",
            "private const TrustedOwnerUid = {$uid};",
            'private const RequireDistinctRuntimeOwner = false;',
            'private const EnforceRuntimeAncestorOwnership = false;',
            'private const NativeSupervisorDeploymentAvailable = true;',
        ],
        $launcher,
        $replacementCount,
    );

    if ($replacementCount !== 5) {
        throw new RuntimeException('The production launcher constants changed unexpectedly.');
    }

    fakturowniaPreAutoloadWrite($launcherPath, $instrumented, 0o444);

    $keyPair = \sodium_crypto_sign_keypair();
    $secretKey = \sodium_crypto_sign_secretkey($keyPair);
    $publicKey = \sodium_crypto_sign_publickey($keyPair);
    fakturowniaPreAutoloadWrite($publicKeyPath, $publicKey, 0o444);

    $phpExecutable = \realpath(\PHP_BINARY);

    if (! \is_string($phpExecutable)) {
        throw new RuntimeException('Cannot resolve the PHP executable.');
    }

    $policy = [
        'contract' => 'cieplik206.fakturownia.preauthenticated-policy',
        'version' => 1,
        'manifest_root' => $manifestRoot,
        'snapshot_root' => $snapshotRoot,
        'public_key_path' => $publicKeyPath,
        'public_key_sha256' => \hash('sha256', $publicKey),
        'php_executable' => $phpExecutable,
        'php_executable_sha256' => \hash_file('sha256', $phpExecutable),
        'launcher_sha256' => \hash('sha256', $instrumented),
        'probe_entrypoint' => 'tests/Contract/LiveEvidenceProbeEntrypoint.php',
        'limits' => [
            'manifest_bytes' => 1_048_576,
            'manifest_depth' => 32,
            'manifest_nodes' => 20_000,
            'tree_files' => 1_000,
            'tree_directories' => 200,
            'path_bytes' => 512,
            'file_bytes' => 2_097_152,
            'tree_bytes' => 16_777_216,
            'credential_bytes' => 4_096,
            'authorization_bytes' => 65_536,
        ],
    ];
    $policyRaw = fakturowniaPreAutoloadJson($policy);
    fakturowniaPreAutoloadWrite($policyPath, $policyRaw, 0o644);

    $sourceOriginal = "<?php\n\ndeclare(strict_types=1);\n\nreturn 'original';\n";
    $autoload = <<<'PHP'
<?php

declare(strict_types=1);

file_put_contents(%s, 'executed');
$source = dirname(__DIR__).'/src/Example.php';
chmod($source, 0644);
file_put_contents($source, %s);
PHP;
    $autoload = \sprintf($autoload, \var_export($sentinelPath, true), \var_export($sourceOriginal, true));
    $entrypoint = <<<'PHP'
<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

$credentialFd = getenv('FAKTUROWNIA_CREDENTIAL_FD');
$authorizationFd = getenv('FAKTUROWNIA_AUTHORIZATION_FD');
$result = [
    'credential' => file_get_contents("php://fd/{$credentialFd}"),
    'authorization' => file_get_contents("php://fd/{$authorizationFd}"),
    'manifest_sha256' => getenv('FAKTUROWNIA_PREAUTOLOAD_VERIFIED_MANIFEST_SHA256'),
    'inherited_secret' => getenv('LEAK_ME'),
];

file_put_contents(%s, json_encode($result, JSON_THROW_ON_ERROR));
PHP;
    $entrypoint = \sprintf($entrypoint, \var_export($resultPath, true));

    $lockPackages = [
        [
            'name' => 'example/runtime-package',
            'version' => '1.2.3',
            'source' => [
                'type' => 'git',
                'url' => 'https://example.invalid/runtime.git',
                'reference' => \str_repeat('1', 40),
            ],
            'dist' => [
                'type' => 'zip',
                'url' => 'https://example.invalid/runtime.zip',
                'reference' => \str_repeat('1', 40),
                'shasum' => '',
            ],
        ],
    ];
    $lockDevPackages = [
        [
            'name' => 'example/probe-package',
            'version' => '4.5.6',
            'source' => [
                'type' => 'git',
                'url' => 'https://example.invalid/probe.git',
                'reference' => \str_repeat('2', 40),
            ],
            'dist' => [
                'type' => 'zip',
                'url' => 'https://example.invalid/probe.zip',
                'reference' => \str_repeat('2', 40),
                'shasum' => '',
            ],
        ],
    ];
    $installedPackages = [...$lockPackages, ...$lockDevPackages];

    if ($signedMutation === 'package-set') {
        $installedPackages[1]['version'] = '4.5.7';
    }

    $treeFiles = [
        'bin/fakturownia-live-evidence-launcher.php' => $instrumented,
        'composer.json' => fakturowniaPreAutoloadJson([
            'name' => 'example/snapshot',
            'require' => ['example/runtime-package' => '1.2.3'],
            'require-dev' => ['example/probe-package' => '4.5.6'],
        ]),
        'composer.lock' => fakturowniaPreAutoloadJson([
            '_readme' => ['locked for test'],
            'content-hash' => \str_repeat('a', 32),
            'packages' => $lockPackages,
            'packages-dev' => $lockDevPackages,
        ]),
        'phpunit.xml.dist' => "<?xml version=\"1.0\"?><phpunit/>\n",
        'src/Example.php' => $sourceOriginal,
        'tests/Pest.php' => "<?php\n\ndeclare(strict_types=1);\n",
        'tests/Contract/LiveEvidenceProbeEntrypoint.php' => $entrypoint,
        'tests/Contract/Support/Harness.php' => "<?php\n\ndeclare(strict_types=1);\n",
        'vendor/autoload.php' => $autoload,
        'vendor/composer/autoload_real.php' => "<?php\n\ndeclare(strict_types=1);\n",
        'vendor/composer/installed.json' => fakturowniaPreAutoloadJson([
            'packages' => $installedPackages,
            'dev' => true,
            'dev-package-names' => ['example/probe-package'],
        ]),
    ];

    foreach ($treeFiles as $relativePath => $contents) {
        fakturowniaPreAutoloadWrite("{$staging}/{$relativePath}", $contents, 0o444);
    }

    fakturowniaPreAutoloadMakeTreeReadOnly($staging);
    $tree = fakturowniaPreAutoloadInventory($staging);
    $runtimeExtensions = fakturowniaPreAutoloadRuntimeExtensions($phpExecutable);
    $filesByPath = [];

    foreach ($tree['files'] as $file) {
        $filesByPath[$file['path']] = $file;
    }

    $vendorFiles = \array_values(\array_filter(
        $tree['files'],
        static fn (array $file): bool => \str_starts_with($file['path'], 'vendor/'),
    ));
    $sourceFiles = fakturowniaPreAutoloadPathsWithPrefix($tree['files'], 'src/');
    $harnessFiles = fakturowniaPreAutoloadPathsWithPrefix($tree['files'], 'tests/Contract/');
    $behaviorFiles = \array_values(\array_map(
        static fn (array $file): string => $file['path'],
        \array_filter(
            $tree['files'],
            static fn (array $file): bool => ! \str_starts_with($file['path'], 'vendor/'),
        ),
    ));
    $composerBootstrapFiles = \array_values(\array_map(
        static fn (array $file): string => $file['path'],
        \array_filter(
            $tree['files'],
            static fn (array $file): bool => $file['path'] === 'vendor/autoload.php'
                || (\str_starts_with($file['path'], 'vendor/composer/') && \str_ends_with($file['path'], '.php')),
        ),
    ));
    \sort($composerBootstrapFiles, \SORT_STRING);

    $manifest = [
        'contract' => 'cieplik206.fakturownia.preauthenticated-snapshot',
        'version' => 1,
        'repository' => ['commit' => \str_repeat('a', 40)],
        'entrypoint' => 'tests/Contract/LiveEvidenceProbeEntrypoint.php',
        'bindings' => [
            'snapshot_tree_sha256' => fakturowniaPreAutoloadRecordsSha256($tree['files']),
            'composer_lock_sha256' => $filesByPath['composer.lock']['sha256'],
            'vendor_tree_sha256' => fakturowniaPreAutoloadRecordsSha256($vendorFiles),
            'installed_packages_sha256' => $filesByPath['vendor/composer/installed.json']['sha256'],
            'policy_sha256' => \hash('sha256', $policyRaw),
            'public_key_sha256' => \hash('sha256', $publicKey),
            'launcher_sha256' => \hash('sha256', $instrumented),
            'source_files' => $sourceFiles,
            'harness_files' => $harnessFiles,
            'behavior_files' => $behaviorFiles,
            'composer_bootstrap_files' => $composerBootstrapFiles,
        ],
        'runtime' => [
            'php_executable' => $phpExecutable,
            'php_executable_sha256' => \hash_file('sha256', $phpExecutable),
            'php_version' => $runtimeExtensions['php_version'],
            'php_version_id' => $runtimeExtensions['php_version_id'],
            'sapi' => 'cli',
            'arguments' => ['-n'],
            'ini' => [
                'loaded_file' => false,
                'scanned_files' => false,
                'auto_prepend_file' => '',
                'auto_append_file' => '',
            ],
            'extensions' => $runtimeExtensions['extensions'],
            'zend_extensions' => $runtimeExtensions['zend_extensions'],
        ],
        'directories' => $tree['directories'],
        'files' => $tree['files'],
    ];

    if ($signedMutation === 'runtime') {
        $manifest['runtime']['php_version'] = '0.0.0-tampered';
    }

    if ($signedMutation === 'unknown-schema') {
        $manifest['unexpected'] = true;
    }

    $manifestRaw = fakturowniaPreAutoloadJson($manifest);
    $manifestSha256 = \hash('sha256', $manifestRaw);
    $snapshot = "{$snapshotRoot}/{$manifestSha256}";

    if (! \rename($staging, $snapshot)) {
        throw new RuntimeException('Cannot content-address the test snapshot.');
    }

    $manifestPath = "{$manifestRoot}/{$manifestSha256}.manifest.json";
    $signaturePath = "{$manifestRoot}/{$manifestSha256}.manifest.sig";
    fakturowniaPreAutoloadWrite($manifestPath, $manifestRaw, 0o444);
    fakturowniaPreAutoloadWrite(
        $signaturePath,
        \sodium_crypto_sign_detached($manifestRaw, $secretKey),
        0o444,
    );
    fakturowniaPreAutoloadWrite($credentialPath, '{"token":"credential-only-after-verify"}', 0o600);
    fakturowniaPreAutoloadWrite($authorizationPath, '{"authorization":"A-only-after-verify"}', 0o600);

    return [
        'base' => $base,
        'launcher' => $launcherPath,
        'manifest' => $manifestPath,
        'signature' => $signaturePath,
        'snapshot' => $snapshot,
        'credential' => $credentialPath,
        'authorization' => $authorizationPath,
        'sentinel' => $sentinelPath,
        'result' => $resultPath,
        'source' => "{$snapshot}/src/Example.php",
        'source_original' => $sourceOriginal,
        'manifest_sha256' => $manifestSha256,
    ];
}

/**
 * @param array{
 *     launcher: string,
 *     credential: string,
 *     authorization: string,
 *     manifest_sha256: string
 * } $fixture
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function fakturowniaRunPreAutoloadLauncher(array $fixture): array
{
    $command = [
        \PHP_BINARY,
        '-n',
        $fixture['launcher'],
        "--manifest-sha256={$fixture['manifest_sha256']}",
        "--credential-file={$fixture['credential']}",
        "--authorization-file={$fixture['authorization']}",
    ];
    $pipes = [];
    $process = \proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        ['LEAK_ME' => 'must-not-reach-the-probe'],
        ['bypass_shell' => true],
    );

    if (! \is_resource($process)) {
        throw new RuntimeException('Cannot start the pre-autoload launcher subprocess.');
    }

    \fclose($pipes[0]);
    $stdout = \stream_get_contents($pipes[1]);
    $stderr = \stream_get_contents($pipes[2]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    $exitCode = \proc_close($process);

    return [
        'exit_code' => $exitCode,
        'stdout' => \is_string($stdout) ? $stdout : '',
        'stderr' => \is_string($stderr) ? $stderr : '',
    ];
}

/** @return array{php_version: string, php_version_id: int, extensions: list<string>, zend_extensions: list<string>} */
function fakturowniaPreAutoloadRuntimeExtensions(string $phpExecutable): array
{
    $command = [
        $phpExecutable,
        '-n',
        '-r',
        '$extensions=get_loaded_extensions(false);sort($extensions,SORT_STRING);$zend=get_loaded_extensions(true);sort($zend,SORT_STRING);echo json_encode(["php_version"=>PHP_VERSION,"php_version_id"=>PHP_VERSION_ID,"extensions"=>$extensions,"zend_extensions"=>$zend],JSON_THROW_ON_ERROR);',
    ];
    $pipes = [];
    $process = \proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [], ['bypass_shell' => true]);

    if (! \is_resource($process)) {
        throw new RuntimeException('Cannot inspect the no-ini PHP runtime.');
    }

    \fclose($pipes[0]);
    $stdout = \stream_get_contents($pipes[1]);
    $stderr = \stream_get_contents($pipes[2]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    $exitCode = \proc_close($process);

    if ($exitCode !== 0 || ! \is_string($stdout)) {
        throw new RuntimeException('Cannot inspect the no-ini PHP runtime: '.(\is_string($stderr) ? $stderr : 'unknown error'));
    }

    $runtime = \json_decode($stdout, true, 16, \JSON_THROW_ON_ERROR);

    $phpVersion = \is_array($runtime) ? ($runtime['php_version'] ?? null) : null;
    $phpVersionId = \is_array($runtime) ? ($runtime['php_version_id'] ?? null) : null;
    $extensions = \is_array($runtime) ? ($runtime['extensions'] ?? null) : null;
    $zendExtensions = \is_array($runtime) ? ($runtime['zend_extensions'] ?? null) : null;

    if (! \is_string($phpVersion)
        || ! \is_int($phpVersionId)
        || ! \is_array($extensions)
        || ! \array_is_list($extensions)
        || ! \is_array($zendExtensions)
        || ! \array_is_list($zendExtensions)) {
        throw new RuntimeException('The no-ini PHP runtime returned invalid JSON.');
    }

    foreach ([...$extensions, ...$zendExtensions] as $extension) {
        if (! \is_string($extension)) {
            throw new RuntimeException('The no-ini PHP runtime returned an invalid extension name.');
        }
    }

    return [
        'php_version' => $phpVersion,
        'php_version_id' => $phpVersionId,
        'extensions' => $extensions,
        'zend_extensions' => $zendExtensions,
    ];
}

/**
 * @return array{
 *     directories: list<array{path: string, type: string, mode: string}>,
 *     files: list<array{path: string, type: string, mode: string, size: int, sha256: string}>
 * }
 */
function fakturowniaPreAutoloadInventory(string $root): array
{
    $directories = [];
    $files = [];
    fakturowniaPreAutoloadInventoryDirectory($root, '', $directories, $files);
    \usort($directories, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);
    \usort($files, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);

    return ['directories' => $directories, 'files' => $files];
}

/**
 * @param  list<array{path: string, type: string, mode: string}>  $directories
 * @param  list<array{path: string, type: string, mode: string, size: int, sha256: string}>  $files
 */
function fakturowniaPreAutoloadInventoryDirectory(string $absolute, string $relative, array &$directories, array &$files): void
{
    $entries = \scandir($absolute);

    if (! \is_array($entries)) {
        throw new RuntimeException('Cannot scan the test snapshot.');
    }

    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $path = "{$absolute}/{$name}";
        $relativePath = $relative === '' ? $name : "{$relative}/{$name}";
        $stat = \lstat($path);

        if (! \is_array($stat)) {
            throw new RuntimeException('Cannot stat the test snapshot.');
        }

        $mode = (int) $stat['mode'];
        $type = $mode & 0o170000;

        if ($type === 0o040000) {
            $directories[] = [
                'path' => $relativePath,
                'type' => 'directory',
                'mode' => \sprintf('%04o', $mode & 0o7777),
            ];
            fakturowniaPreAutoloadInventoryDirectory($path, $relativePath, $directories, $files);

            continue;
        }

        if ($type !== 0o100000) {
            throw new RuntimeException('Unexpected special file while building a test snapshot.');
        }

        $sha256 = \hash_file('sha256', $path);

        if (! \is_string($sha256)) {
            throw new RuntimeException('Cannot hash a test snapshot file.');
        }

        $files[] = [
            'path' => $relativePath,
            'type' => 'file',
            'mode' => \sprintf('%04o', $mode & 0o7777),
            'size' => (int) $stat['size'],
            'sha256' => $sha256,
        ];
    }
}

/**
 * @param  list<array{path: string, type: string, mode: string, size: int, sha256: string}>  $files
 * @return list<string>
 */
function fakturowniaPreAutoloadPathsWithPrefix(array $files, string $prefix): array
{
    return \array_values(\array_map(
        static fn (array $file): string => $file['path'],
        \array_filter($files, static fn (array $file): bool => \str_starts_with($file['path'], $prefix)),
    ));
}

/** @param list<array{path: string, type: string, mode: string, size: int, sha256: string}> $records */
function fakturowniaPreAutoloadRecordsSha256(array $records): string
{
    return \hash('sha256', fakturowniaPreAutoloadCanonicalJson([
        'contract' => 'cieplik206.fakturownia.snapshot-file-set',
        'version' => 1,
        'files' => $records,
    ]));
}

function fakturowniaPreAutoloadCanonicalJson(mixed $value): string
{
    return fakturowniaPreAutoloadJson(fakturowniaPreAutoloadCanonicalize($value));
}

function fakturowniaPreAutoloadCanonicalize(mixed $value): mixed
{
    if (! \is_array($value)) {
        return $value;
    }

    if (\array_is_list($value)) {
        return \array_map(fakturowniaPreAutoloadCanonicalize(...), $value);
    }

    \ksort($value, \SORT_STRING);

    foreach ($value as $key => $item) {
        $value[$key] = fakturowniaPreAutoloadCanonicalize($item);
    }

    return $value;
}

function fakturowniaPreAutoloadJson(mixed $value): string
{
    return \json_encode(
        $value,
        \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION,
    );
}

function fakturowniaPreAutoloadWrite(string $path, string $contents, int $mode): void
{
    $directory = \dirname($path);

    if (! \is_dir($directory) && ! \mkdir($directory, 0o700, true)) {
        throw new RuntimeException('Cannot create a test fixture directory.');
    }

    if (\file_put_contents($path, $contents) !== \strlen($contents) || ! \chmod($path, $mode)) {
        throw new RuntimeException('Cannot write a test fixture file.');
    }
}

function fakturowniaPreAutoloadMakeTreeReadOnly(string $root): void
{
    $entries = \scandir($root);

    if (! \is_array($entries)) {
        throw new RuntimeException('Cannot scan a test fixture directory.');
    }

    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $path = "{$root}/{$name}";

        if (\is_dir($path) && ! \is_link($path)) {
            fakturowniaPreAutoloadMakeTreeReadOnly($path);
        }
    }

    if (! \chmod($root, 0o555)) {
        throw new RuntimeException('Cannot make a test fixture directory read-only.');
    }
}

function fakturowniaPreAutoloadRemoveTree(string $root): void
{
    if (\is_link($root) || (\file_exists($root) && ! \is_dir($root))) {
        \chmod($root, 0o600);
        \unlink($root);

        return;
    }

    if (! \is_dir($root)) {
        return;
    }

    \chmod($root, 0o700);
    $entries = \scandir($root);

    if (\is_array($entries)) {
        foreach ($entries as $name) {
            if ($name !== '.' && $name !== '..') {
                fakturowniaPreAutoloadRemoveTree("{$root}/{$name}");
            }
        }
    }

    \rmdir($root);
}

it('fails closed before parsing caller input when the native root supervisor is not deployed', function (): void {
    $launcher = \dirname(__DIR__, 2).'/bin/fakturownia-live-evidence-launcher.php';
    $sentinel = \realpath(\sys_get_temp_dir()).'/fakturownia-forbidden-prepend-'.\bin2hex(\random_bytes(12)).'.php';
    \file_put_contents($sentinel, "<?php file_put_contents(__FILE__.'.executed', 'yes');");
    $pipes = [];
    $process = \proc_open(
        [
            \PHP_BINARY,
            '-n',
            $launcher,
            '-d',
            "extension={$sentinel}",
            '--manifest-sha256='.\str_repeat('a', 64),
            '--credential-file=/definitely/not/opened/credential',
            '--authorization-file=/definitely/not/opened/authorization-A',
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        ['UNTRUSTED_SECRET' => 'must-be-cleared-before-denial'],
        ['bypass_shell' => true],
    );

    if (! \is_resource($process)) {
        throw new RuntimeException('Cannot start the production fail-closed launcher.');
    }

    \fclose($pipes[0]);
    $stdout = \stream_get_contents($pipes[1]);
    $stderr = \stream_get_contents($pipes[2]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    $exitCode = \proc_close($process);

    try {
        expect($exitCode)->toBe(78)
            ->and($stdout)->toBe('')
            ->and($stderr)->toContain('native root supervisor deployment is unavailable; live execution is fail-closed')
            ->and(\file_exists($sentinel.'.executed'))->toBeFalse();
    } finally {
        \unlink($sentinel);
    }
});

it('keeps the instrumented verification engine capable of executing only a fully verified snapshot', function (): void {
    $fixture = fakturowniaPreAutoloadFixture();

    try {
        $result = fakturowniaRunPreAutoloadLauncher($fixture);

        expect($result['stderr'])->toBe('')
            ->and($result['exit_code'])->toBe(0)
            ->and(\file_exists($fixture['result']))->toBeTrue();

        $probeResult = \json_decode((string) \file_get_contents($fixture['result']), true, 8, \JSON_THROW_ON_ERROR);

        expect(\file_get_contents($fixture['sentinel']))->toBe('executed')
            ->and($probeResult)->toBe([
                'credential' => '{"token":"credential-only-after-verify"}',
                'authorization' => '{"authorization":"A-only-after-verify"}',
                'manifest_sha256' => $fixture['manifest_sha256'],
                'inherited_secret' => false,
            ]);
    } finally {
        fakturowniaPreAutoloadRemoveTree($fixture['base']);
    }
});

it('never lets the instrumented verifier execute a self-restoring autoloader after trust failure', function (string $mutation): void {
    $signedMutation = \in_array($mutation, ['runtime', 'package-set', 'unknown-schema'], true) ? $mutation : null;
    $fixture = fakturowniaPreAutoloadFixture($signedMutation);
    $outsideHardlink = "{$fixture['base']}/outside-hardlink";

    try {
        if ($mutation === 'manifest') {
            \chmod($fixture['manifest'], 0o644);
            \file_put_contents($fixture['manifest'], ' ', \FILE_APPEND);
            \chmod($fixture['manifest'], 0o444);
        }

        if ($mutation === 'signature') {
            $signature = (string) \file_get_contents($fixture['signature']);
            $signature[0] = \chr(\ord($signature[0]) ^ 1);
            \chmod($fixture['signature'], 0o644);
            \file_put_contents($fixture['signature'], $signature);
            \chmod($fixture['signature'], 0o444);
        }

        if ($mutation === 'file') {
            \chmod($fixture['source'], 0o644);
            \file_put_contents($fixture['source'], 'tampered-before-launch');
            \chmod($fixture['source'], 0o444);
        }

        if ($mutation === 'mode') {
            \chmod($fixture['source'], 0o644);
        }

        if ($mutation === 'snapshot-owner-writable') {
            \chmod($fixture['snapshot'], 0o755);
        }

        if ($mutation === 'ancestor-writable') {
            \chmod($fixture['base'], 0o777);
        }

        if ($mutation === 'symlink') {
            $backup = "{$fixture['base']}/source-backup";
            \file_put_contents($backup, $fixture['source_original']);
            \chmod(\dirname($fixture['source']), 0o755);
            \unlink($fixture['source']);
            \symlink($backup, $fixture['source']);
            \chmod(\dirname($fixture['source']), 0o555);
        }

        if ($mutation === 'hardlink') {
            \link($fixture['source'], $outsideHardlink);
        }

        if ($mutation === 'special') {
            \chmod($fixture['snapshot'], 0o755);
            \posix_mkfifo("{$fixture['snapshot']}/unexpected-fifo", 0o444);
            \chmod($fixture['snapshot'], 0o555);
        }

        if ($mutation === 'extra') {
            \chmod($fixture['snapshot'], 0o755);
            \file_put_contents("{$fixture['snapshot']}/unexpected.php", '<?php');
            \chmod("{$fixture['snapshot']}/unexpected.php", 0o444);
            \chmod($fixture['snapshot'], 0o555);
        }

        if ($mutation === 'missing') {
            \chmod(\dirname($fixture['source']), 0o755);
            \unlink($fixture['source']);
            \chmod(\dirname($fixture['source']), 0o555);
        }

        $result = fakturowniaRunPreAutoloadLauncher($fixture);

        expect($result['exit_code'])->toBe(78)
            ->and($result['stderr'])->toContain('pre-autoload verification denied:')
            ->and(\file_exists($fixture['sentinel']))->toBeFalse()
            ->and(\file_exists($fixture['result']))->toBeFalse();

        if ($mutation === 'file') {
            expect(\file_get_contents($fixture['source']))->toBe('tampered-before-launch');
        }
    } finally {
        \chmod($fixture['base'], 0o700);
        fakturowniaPreAutoloadRemoveTree($fixture['base']);
    }
})->with([
    'raw manifest bytes changed after signing' => 'manifest',
    'detached signature changed' => 'signature',
    'signed snapshot file changed and malicious autoload could self-restore it' => 'file',
    'signed runtime policy changed' => 'runtime',
    'installed package set differs semantically from composer.lock' => 'package-set',
    'strict manifest schema has an unknown field' => 'unknown-schema',
    'snapshot file is owner-writable' => 'mode',
    'content-addressed root is owner-writable' => 'snapshot-owner-writable',
    'trusted ancestor is group-writable' => 'ancestor-writable',
    'snapshot contains a symlink' => 'symlink',
    'snapshot contains a hardlink' => 'hardlink',
    'snapshot contains a special file' => 'special',
    'snapshot contains an extra file' => 'extra',
    'snapshot is missing a signed file' => 'missing',
]);
