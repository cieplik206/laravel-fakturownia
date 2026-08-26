<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Artifacts;

use Cieplik206\Fakturownia\Client\Contracts\ClientFactory;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\Contracts\InvoicePdfSourceReader;
use Cieplik206\Fakturownia\Stateful\Contracts\ConnectionResolver;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use LogicException;

final readonly class FakturowniaInvoicePdfSourceReader implements InvoicePdfSourceReader
{
    public function __construct(
        private ConnectionResolver $connections,
        private ClientFactory $clients,
    ) {}

    public function open(ConnectionKey $connectionKey, string $remoteId): ArtifactContentStream
    {
        $profile = $this->connections->resolve($connectionKey);

        if (! $profile->key()->equals($connectionKey)) {
            throw new LogicException('The invoice PDF source resolved a mismatched connection profile.');
        }

        return new ReadArtifactContentStream(
            $profile->createClient($this->clients)->read()->invoices()->pdf($remoteId),
        );
    }
}
