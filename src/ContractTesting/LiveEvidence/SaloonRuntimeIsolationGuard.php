<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use ReflectionClass;
use RuntimeException;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Senders\GuzzleSender;

final class SaloonRuntimeIsolationGuard
{
    private function __construct() {}

    public static function assertIsolated(): void
    {
        if (MockClient::getGlobal() !== null) {
            throw new RuntimeException('Remote consumption is unavailable while a global HTTP mock is registered.');
        }

        $middleware = Config::globalMiddleware();

        if ($middleware->getRequestPipeline()->getPipes() !== []
            || $middleware->getResponsePipeline()->getPipes() !== []
            || $middleware->getFatalPipeline()->getPipes() !== []) {
            throw new RuntimeException('Remote consumption requires an empty global HTTP middleware pipeline.');
        }

        $reflection = new ReflectionClass(Config::class);
        $senderResolver = $reflection->getProperty('senderResolver')->getValue();

        if ($senderResolver !== null
            || Config::$defaultSender !== GuzzleSender::class
            || Config::$defaultTlsMethod !== \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            || Config::$defaultConnectionTimeout !== 10
            || Config::$defaultRequestTimeout !== 30) {
            throw new RuntimeException('Remote consumption requires an unmodified global HTTP sender configuration.');
        }
    }
}
