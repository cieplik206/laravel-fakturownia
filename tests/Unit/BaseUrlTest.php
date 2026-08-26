<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Client\ValueObjects\BaseUrl;

it('accepts only the exact allowlisted HTTPS origin', function (): void {
    $baseUrl = BaseUrl::fromString(
        'https://tenant.fakturownia.pl/',
        ['other.fakturownia.pl', 'TENANT.FAKTUROWNIA.PL'],
    );

    expect((string) $baseUrl)->toBe('https://tenant.fakturownia.pl')
        ->and($baseUrl->host())->toBe('tenant.fakturownia.pl')
        ->and($baseUrl->equals(BaseUrl::fromString(
            'https://tenant.fakturownia.pl',
            ['tenant.fakturownia.pl'],
        )))->toBeTrue();
});

/**
 * @param  string  $url
 * @param  list<string>  $allowedHosts
 */
it('rejects unsafe or non-allowlisted origins', function (string $url, array $allowedHosts): void {
    expect(fn (): BaseUrl => BaseUrl::fromString($url, $allowedHosts))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'plain HTTP' => ['http://tenant.fakturownia.pl', ['tenant.fakturownia.pl']],
    'different subdomain' => ['https://evil.fakturownia.pl', ['tenant.fakturownia.pl']],
    'suffix confusion' => ['https://tenant.fakturownia.pl.evil.test', ['tenant.fakturownia.pl']],
    'embedded credentials' => ['https://user:secret@tenant.fakturownia.pl', ['tenant.fakturownia.pl']],
    'query string' => ['https://tenant.fakturownia.pl?next=evil', ['tenant.fakturownia.pl']],
    'non-root path' => ['https://tenant.fakturownia.pl/api', ['tenant.fakturownia.pl']],
    'explicit default port' => ['https://tenant.fakturownia.pl:443', ['tenant.fakturownia.pl']],
    'non-default port' => ['https://tenant.fakturownia.pl:8443', ['tenant.fakturownia.pl']],
    'IP literal' => ['https://127.0.0.1', ['127.0.0.1']],
    'single DNS label' => ['https://localhost', ['localhost']],
    'invalid DNS label' => ['https://tenant.-invalid.pl', ['tenant.-invalid.pl']],
    'empty allowlist' => ['https://tenant.fakturownia.pl', []],
    'wildcard allowlist' => ['https://tenant.fakturownia.pl', ['*.fakturownia.pl']],
]);
