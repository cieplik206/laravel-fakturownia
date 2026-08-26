<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Laravel\Artifacts\FilesystemContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageKey;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactAddressLease;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactAddressLock;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Facades\Storage;

final class S67StorageStream extends ArtifactContentStream
{
    private int $offset = 0;

    public function __construct(private readonly string $bytes) {}

    public function read(int $maximumBytes): string
    {
        $chunk = substr($this->bytes, $this->offset, $maximumBytes);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->offset >= strlen($this->bytes);
    }

    public function close(): void {}
}

final class S67StorageLease extends ArtifactAddressLease
{
    public int $assertions = 0;

    public int $renewals = 0;

    public bool $released = false;

    public function assertOwned(): void
    {
        $this->assertions++;
    }

    public function renewFor(int $minimumOwnedSeconds): void
    {
        $this->renewals++;
    }

    public function release(): void
    {
        $this->released = true;
    }
}

final class S67StorageLock implements ArtifactAddressLock
{
    public int $acquisitions = 0;

    public ?S67StorageLease $lastLease = null;

    public function acquire(
        ArtifactStorageNamespace $storageNamespace,
        ContentAddress $contentAddress,
    ): ArtifactAddressLease {
        $this->acquisitions++;

        return $this->lastLease = new S67StorageLease;
    }
}

function s67Store(S67StorageLock $lock): FilesystemContentAddressedArtifactStore
{
    config()->set('fakturownia.artifacts.disk', 'fakturownia-artifacts');
    config()->set('fakturownia.artifacts.prefix', 'fakturownia/finalized');
    Storage::fake('fakturownia-artifacts');

    return new FilesystemContentAddressedArtifactStore(
        app(Factory::class),
        app(Repository::class),
        $lock,
    );
}

it('writes and reopens one immutable private PDF object by its verified content address', function (): void {
    $lock = new S67StorageLock;
    $store = s67Store($lock);
    $bytes = "%PDF-1.7\nstorage\n%%EOF\n";
    $first = $store->put(new S67StorageStream($bytes), 'application/pdf');
    $second = $store->put(new S67StorageStream($bytes), 'application/pdf');
    $opened = $store->open($first->contentAddress);
    $restored = '';

    while (! $opened->eof()) {
        $restored .= $opened->read(7);
    }

    $opened->close();

    expect($second)->toEqual($first)
        ->and($store->inspect($first->contentAddress))->toEqual($first)
        ->and($restored)->toBe($bytes)
        ->and($lock->acquisitions)->toBe(2)
        ->and($lock->lastLease?->released)->toBeTrue();
});

it('returns null for an absent address and fails closed for bytes under a false address', function (): void {
    $lock = new S67StorageLock;
    $store = s67Store($lock);
    $address = ContentAddress::fromSha256(str_repeat('a', 64));
    $namespace = new ArtifactStorageNamespace('fakturownia-artifacts', 'fakturownia/finalized');

    expect($store->inspect($address))->toBeNull();

    Storage::disk('fakturownia-artifacts')->put(
        ArtifactStorageKey::for($namespace, $address),
        "%PDF-1.7\nconflict\n%%EOF\n",
    );

    expect(fn () => $store->inspect($address))->toThrow(RuntimeException::class);
});
