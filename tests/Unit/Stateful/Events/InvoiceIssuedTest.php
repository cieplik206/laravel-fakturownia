<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Events\InvoiceIssued;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

function s46InvoiceIssued(DateTimeImmutable $occurredAt): InvoiceIssued
{
    $reflection = new ReflectionClass(InvoiceIssued::class);
    $event = $reflection->newInstanceWithoutConstructor();
    $constructor = $reflection->getConstructor();

    if (! $constructor instanceof ReflectionMethod) {
        throw new LogicException('InvoiceIssued must keep its sealed constructor.');
    }

    $constructor->invoke(
        $event,
        '01K3K8N8G8V3A6R5T4Y2W1Q9PA',
        new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9P7'),
        new ConnectionKey('sales'),
        new InvoiceResourceId('01K3K8N8G8V3A6R5T4Y2W1Q9P7'),
        '9001',
        IntegrationContext::make(
            correlationId: 'workflow:invoice:1',
            attributes: ['workflow_id' => '1', 'step' => 'issue_invoice'],
        ),
        $occurredAt,
    );

    return $event;
}

it('ships a sealed semantic event with fixed provider version and no raw invoice response fields', function (): void {
    $reflection = new ReflectionClass(InvoiceIssued::class);
    $constructor = $reflection->getConstructor();
    $publicStaticFactories = array_filter(
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC),
        static fn (ReflectionMethod $method): bool => ! str_starts_with($method->getName(), '__'),
    );
    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        $reflection->getProperties(ReflectionProperty::IS_PUBLIC),
    );

    expect($constructor)->not->toBeNull()
        ->and($constructor?->isPrivate())->toBeTrue()
        ->and($publicStaticFactories)->toBe([])
        ->and(InvoiceIssued::EventVersion)->toBe(1)
        ->and(InvoiceIssued::Provider)->toBe('fakturownia')
        ->and($properties)->toContain(
            'eventId',
            'operationId',
            'connectionKey',
            'resourceId',
            'remoteId',
            'context',
            'occurredAt',
        )
        ->and($properties)->not->toContain(
            'payload',
            'response',
            'positions',
            'buyerTaxNumber',
            'token',
            'credentials',
        );
});

it('accepts only a system supplied UTC instant and rejects native transfer', function (): void {
    $event = s46InvoiceIssued(new DateTimeImmutable('2026-08-26T10:00:00.123456+00:00'));

    expect($event->occurredAt->format('Y-m-d\TH:i:s.uP'))->toBe('2026-08-26T10:00:00.123456+00:00')
        ->and($event->__debugInfo())
        ->toMatchArray([
            'provider' => 'fakturownia',
            'connection' => '[REDACTED]',
            'remote_id' => '[REDACTED]',
            'context' => '[REDACTED]',
        ]);

    expect(fn (): string => serialize($event))->toThrow(LogicException::class)
        ->and(fn (): InvoiceIssued => s46InvoiceIssued(
            new DateTimeImmutable('2026-08-26T12:00:00.123456+02:00'),
        ))->toThrow(InvalidArgumentException::class);
});
