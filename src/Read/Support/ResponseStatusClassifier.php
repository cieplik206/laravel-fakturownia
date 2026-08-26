<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Support;

use Cieplik206\Fakturownia\Read\Contracts\ReadClock;
use Cieplik206\Fakturownia\Read\Contracts\ReadRequestDescriptor;
use Cieplik206\Fakturownia\Read\Exceptions\AuthenticationFailed;
use Cieplik206\Fakturownia\Read\Exceptions\BadRequest;
use Cieplik206\Fakturownia\Read\Exceptions\RateLimited;
use Cieplik206\Fakturownia\Read\Exceptions\RemoteReadFailed;
use Cieplik206\Fakturownia\Read\Exceptions\RemoteServerFailed;
use Cieplik206\Fakturownia\Read\Exceptions\RemoteValidationFailed;
use Cieplik206\Fakturownia\Read\Exceptions\ResourceNotFound;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadHeaders;

/** @internal */
final readonly class ResponseStatusClassifier
{
    public function __construct(private ReadClock $clock) {}

    public function assertSuccessful(
        ReadRequestDescriptor $request,
        int $statusCode,
        ReadHeaders $headers,
    ): void {
        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        $requestId = $headers->providerRequestId();

        throw match (true) {
            $statusCode === 400 => new BadRequest($request->operation(), $statusCode, $requestId),
            $statusCode === 401, $statusCode === 403 => new AuthenticationFailed($request->operation(), $statusCode, $requestId),
            $statusCode === 404 => new ResourceNotFound($request->operation(), $statusCode, $requestId),
            $statusCode === 422 => new RemoteValidationFailed($request->operation(), $statusCode, $requestId),
            $statusCode === 429 => new RateLimited(
                $request->operation(),
                $statusCode,
                $requestId,
                RetryAfter::milliseconds($headers, $this->clock->unixTime()),
            ),
            $statusCode >= 500 => new RemoteServerFailed($request->operation(), $statusCode, $requestId),
            default => new RemoteReadFailed($request->operation(), $statusCode, $requestId),
        };
    }
}
