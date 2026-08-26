<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Tests\Contract\Support\LiveEvidenceAttestationGuard;

it('integrity-pins a loaded INI symlink through its canonical regular target', function (): void {
    $directory = sys_get_temp_dir().'/fakturownia-runtime-ini-'.bin2hex(random_bytes(12));
    $target = $directory.'/runtime.ini';
    $link = $directory.'/loaded.ini';
    $contents = "memory_limit=512M\n";

    expect(mkdir($directory, 0700))->toBeTrue()
        ->and(file_put_contents($target, $contents))->toBe(strlen($contents))
        ->and(symlink($target, $link))->toBeTrue();

    try {
        $method = new ReflectionMethod(LiveEvidenceAttestationGuard::class, 'readRuntimeConfigurationFile');
        $snapshot = $method->invoke(null, $link);

        expect($snapshot)->toBe([
            'path' => realpath($target),
            'contents' => $contents,
        ]);

        unlink($target);

        expect(fn (): mixed => $method->invoke(null, $link))
            ->toThrow(RuntimeException::class, 'cannot be integrity-pinned');
    } finally {
        if (is_link($link)) {
            unlink($link);
        }

        if (is_file($target)) {
            unlink($target);
        }

        rmdir($directory);
    }
});
