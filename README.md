# Laravel Fakturownia Client

Boilerplate for a Laravel package integrating the
[Fakturownia](https://fakturownia.pl) / [InvoiceOcean](https://invoiceocean.com)
REST API.

This repository currently contains the installable package scaffold and
development environment only. A no-op service provider proves Laravel package
discovery. The API client, DTOs, requests, exceptions, and facade will be
implemented in subsequent work.

## Planned direction

- Saloon 4 as the HTTP transport;
- typed request and response data objects;
- Laravel auto-discovery, configuration, facade, and dependency injection;
- credentials injected per account/tenant;
- explicit exception and retry policies;
- invoice, client, product, payment, and KSeF operations;
- isolated HTTP-mocked tests and opt-in live smoke tests.

## Requirements

- PHP 8.2 or newer;
- Laravel 11, 12, or 13;
- PHP cURL and JSON extensions.

The supported package range is intentionally aligned with the Saloon-based
reference project. The exact runtime floor may be narrowed when the first
public API is implemented.

## Development setup

    composer install
    composer validate --strict --no-check-publish
    composer test
    composer analyse
    vendor/bin/pint --test src tests
    composer audit

Composer dependencies and PHPUnit/PHPStan/Pint caches are local-only and are
excluded by .gitignore.

## Configuration scaffold

The future Laravel integration will read these values from
config/fakturownia.php:

    FAKTUROWNIA_DOMAIN=my-company
    FAKTUROWNIA_TOKEN=your-api-authorization-code
    FAKTUROWNIA_DEPARTMENT_ID=
    FAKTUROWNIA_PLACE=

Do not commit real credentials. Fakturownia API tokens must be supplied by the
consuming application or tenant configuration.

## License

The package is released under the MIT License in LICENSE.md.
