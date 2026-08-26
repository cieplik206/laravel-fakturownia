<?php

test('the package development environment uses a supported PHP version', function (): void {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80400);
});
