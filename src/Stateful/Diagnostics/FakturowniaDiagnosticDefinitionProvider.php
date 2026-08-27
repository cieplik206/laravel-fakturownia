<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Diagnostics;

use Cieplik206\IntegrationOperations\Contracts\FailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\OperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use Cieplik206\IntegrationOperations\Contracts\RetryPolicy;
use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\Registry\ServiceReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;

final readonly class FakturowniaDiagnosticDefinitionProvider implements OperationDefinitionProvider
{
    public const string OperationType = 'fakturownia.diagnostic.echo';

    public static function provider(): ProviderKey
    {
        return new ProviderKey('fakturownia');
    }

    /** @return iterable<OperationDefinition> */
    public static function definitions(): iterable
    {
        yield OperationDefinition::readOnly(
            provider: self::provider(),
            operationType: new OperationType(self::OperationType),
            versions: new OperationDefinitionVersions(1, 1, 1),
            handler: self::extension(OperationHandler::class),
            failureClassifier: self::extension(FailureClassifier::class),
            retryPolicy: self::extension(RetryPolicy::class),
            resultCodec: self::extension(OperationResultCodec::class),
            outcomeProjector: self::extension(OutcomeProjector::class),
        );
    }

    /**
     * @template TExtension of object
     *
     * @param  class-string<TExtension>  $contract
     */
    private static function extension(string $contract): ServiceReference
    {
        return new ServiceReference(FakturowniaDiagnosticProviderExtensions::class, $contract);
    }
}
