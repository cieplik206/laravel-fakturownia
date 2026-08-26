<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStatus;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactType;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Symfony\Component\Uid\Ulid;

/** @param array<string, mixed> $overrides */
function s61ArtifactDescriptor(array $overrides = []): ArtifactDescriptor
{
    $createdAt = new DateTimeImmutable('2026-08-26T01:00:00.000000+00:00');
    $values = array_replace([
        'id' => (string) new Ulid,
        'connectionKey' => 'tenant:default',
        'operationId' => (string) new Ulid,
        'resourceId' => (string) new Ulid,
        'type' => ArtifactType::InvoicePdf,
        'revisionKeyHmac' => str_repeat('a', 64),
        'sourceSnapshotFingerprintHmac' => str_repeat('b', 64),
        'sourceKsefOperationId' => null,
        'object' => new ArtifactObjectDescriptor(
            'shared-artifacts',
            ContentAddress::fromSha256(str_repeat('c', 64)),
            'application/pdf',
            1_024,
        ),
        'status' => ArtifactStatus::Ready,
        'createdAt' => $createdAt,
        'readyAt' => $createdAt->modify('+1 second'),
        'expiresAt' => $createdAt->modify('+90 days'),
        'deletedAt' => null,
    ], $overrides);

    return new ArtifactDescriptor(...$values);
}

function s61NamedReturnType(ReflectionMethod $method): ReflectionNamedType
{
    $returnType = $method->getReturnType();

    if (! $returnType instanceof ReflectionNamedType) {
        throw new LogicException("{$method->getName()} must declare one named return type.");
    }

    return $returnType;
}

final class S61LiteralArtifactContentStream extends ArtifactContentStream
{
    public function read(int $maximumBytes): string
    {
        return '';
    }

    public function eof(): bool
    {
        return true;
    }

    public function close(): void {}
}

it('uses one canonical SHA-256 content address representation', function (): void {
    $digest = hash('sha256', 'immutable invoice PDF');
    $address = ContentAddress::fromSha256($digest);

    expect((string) $address)->toBe("sha256:{$digest}")
        ->and($address->sha256())->toBe($digest)
        ->and(ContentAddress::parse((string) $address))->toEqual($address);
});

it('rejects non-canonical content addresses', function (string $address): void {
    expect(fn (): ContentAddress => ContentAddress::parse($address))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'no scheme' => str_repeat('a', 64),
    'wrong scheme' => 'sha512:'.str_repeat('a', 64),
    'uppercase digest' => 'sha256:'.str_repeat('A', 64),
    'short digest' => 'sha256:'.str_repeat('a', 63),
    'path suffix' => 'sha256:'.str_repeat('a', 64).'/invoice.pdf',
]);

it('describes a stored object without exposing a storage key or bytes', function (): void {
    $digest = str_repeat('d', 64);
    $object = new ArtifactObjectDescriptor(
        'shared-artifacts',
        ContentAddress::fromSha256($digest),
        'application/pdf',
        2_048,
    );

    expect($object->contentSha256())->toBe($digest)
        ->and(get_object_vars($object))->toHaveKeys(['disk', 'contentAddress', 'mimeType', 'sizeBytes'])
        ->and(get_object_vars($object))->not->toHaveKeys(['storageKey', 'contents', 'bytes']);
});

it('rejects invalid object metadata', function (string $disk, string $mimeType, int $sizeBytes): void {
    expect(fn (): ArtifactObjectDescriptor => new ArtifactObjectDescriptor(
        $disk,
        ContentAddress::fromSha256(str_repeat('e', 64)),
        $mimeType,
        $sizeBytes,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'path as disk' => ['../private', 'application/pdf', 1],
    'mime parameters' => ['artifacts', 'application/pdf; charset=binary', 1],
    'uppercase MIME' => ['artifacts', 'Application/PDF', 1],
    'empty object' => ['artifacts', 'application/pdf', 0],
]);

it('keeps the complete provider artifact descriptor deeply immutable', function (): void {
    $descriptor = s61ArtifactDescriptor();

    expect((new ReflectionClass(ArtifactDescriptor::class))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass(ArtifactObjectDescriptor::class))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass(ContentAddress::class))->isReadOnly())->toBeTrue()
        ->and($descriptor->status)->toBe(ArtifactStatus::Ready)
        ->and($descriptor->object->contentSha256())->toBe(str_repeat('c', 64));

    expect(function () use ($descriptor): void {
        $property = new ReflectionProperty($descriptor, 'status');
        $property->setValue($descriptor, ArtifactStatus::Deleted);
    })->toThrow(Error::class);
});

it('requires coherent UTC lifecycle metadata', function (array $overrides): void {
    expect(fn (): ArtifactDescriptor => s61ArtifactDescriptor($overrides))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'ready before create' => [[
        'readyAt' => new DateTimeImmutable('2026-08-25T23:59:59+00:00'),
    ]],
    'non-UTC create time' => [[
        'createdAt' => new DateTimeImmutable('2026-08-26T03:00:00+02:00'),
    ]],
    'expiry at ready time' => [[
        'expiresAt' => new DateTimeImmutable('2026-08-26T01:00:01+00:00'),
    ]],
    'deleted status without deletion time' => [[
        'status' => ArtifactStatus::Deleted,
    ]],
    'ready status with deletion time' => [[
        'deletedAt' => new DateTimeImmutable('2026-08-26T02:00:00+00:00'),
    ]],
]);

it('rejects malformed logical references and fingerprints', function (array $overrides): void {
    expect(fn (): ArtifactDescriptor => s61ArtifactDescriptor($overrides))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'artifact UUID' => [['id' => 'fc88442f-de40-4b16-af6b-30a935fe25ed']],
    'blank connection' => [['connectionKey' => '']],
    'lowercase ULID' => [['operationId' => strtolower((string) new Ulid)]],
    'short revision HMAC' => [['revisionKeyHmac' => str_repeat('a', 63)]],
    'uppercase snapshot HMAC' => [['sourceSnapshotFingerprintHmac' => str_repeat('B', 64)]],
    'malformed KSeF operation' => [['sourceKsefOperationId' => 'ksef-operation']],
]);

it('defines a storage-only contract without an unauthorised deletion surface', function (): void {
    $contract = new ReflectionClass(ContentAddressedArtifactStore::class);
    $putReturnType = s61NamedReturnType($contract->getMethod('put'));
    $inspectReturnType = s61NamedReturnType($contract->getMethod('inspect'));
    $openReturnType = s61NamedReturnType($contract->getMethod('open'));

    expect($contract->isInterface())->toBeTrue()
        ->and($putReturnType->getName())->toBe(ArtifactObjectDescriptor::class)
        ->and($inspectReturnType->getName())->toBe(ArtifactObjectDescriptor::class)
        ->and($inspectReturnType->allowsNull())->toBeTrue()
        ->and($openReturnType->getName())->toBe(ArtifactContentStream::class)
        ->and($contract->hasMethod('delete'))->toBeFalse()
        ->and($contract->hasMethod('deleteUnreferenced'))->toBeFalse();
});

it('makes every artifact content stream non-cloneable, non-serializable, and redacted', function (): void {
    $stream = new S61LiteralArtifactContentStream;

    expect($stream->__debugInfo())->toBe(['contents' => '[REDACTED]'])
        ->and(fn (): object => clone $stream)->toThrow(LogicException::class)
        ->and(fn (): string => serialize($stream))->toThrow(LogicException::class);
});
