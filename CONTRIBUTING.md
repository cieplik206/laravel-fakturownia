# Contributing

Contributions are welcome. Keep pull requests focused and include tests for
changed behavior once the client implementation is present.

## Local development

    composer install
    composer validate --strict --no-check-publish
    composer test
    composer analyse
    vendor/bin/pint --test src tests
    composer audit

The default test suite must remain offline and use HTTP mocks. Live API checks,
when added, must be explicitly opt-in and must never use production credentials
from committed files.

## Pull requests

- Follow the existing namespace and Laravel/Pint code style.
- Add or update Pest tests for behavioral changes.
- Update the README or relevant documentation when the public API changes.
- Add a concise entry under Unreleased in CHANGELOG.md.
- Keep the untracked reference repositories out of commits.
