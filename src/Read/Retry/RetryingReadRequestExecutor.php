<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Retry;

use Cieplik206\Fakturownia\Read\Contracts\ReadClock;
use Cieplik206\Fakturownia\Read\Contracts\ReadJitter;
use Cieplik206\Fakturownia\Read\Contracts\ReadRequestExecutor;
use Cieplik206\Fakturownia\Read\Contracts\ReadSleeper;
use Cieplik206\Fakturownia\Read\Exceptions\TransportFailed;
use Cieplik206\Fakturownia\Read\Requests\JsonReadRequest;
use Cieplik206\Fakturownia\Read\Requests\StreamReadRequest;
use Cieplik206\Fakturownia\Read\Responses\JsonReadResponse;
use Cieplik206\Fakturownia\Read\Responses\StreamReadResponse;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadHeaders;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadSafety;
use JsonSerializable;
use LogicException;

final readonly class RetryingReadRequestExecutor implements JsonSerializable, ReadRequestExecutor
{
    public function __construct(
        private ReadRequestExecutor $executor,
        private ReadRetryPolicy $policy,
        private ReadSleeper $sleeper,
        private ReadJitter $jitter,
        private ReadClock $clock,
    ) {}

    public function execute(JsonReadRequest $request): JsonReadResponse
    {
        $attempt = 1;
        $totalDelay = 0;

        while (true) {
            try {
                $response = $this->executor->execute($request);
            } catch (TransportFailed $exception) {
                if (! $this->mayRetry($request->safety(), $attempt)) {
                    throw $exception;
                }

                $delay = $this->delay($attempt, new ReadHeaders, $totalDelay);

                if ($delay === null) {
                    throw $exception;
                }

                $this->sleeper->sleepMilliseconds($delay);
                $totalDelay += $delay;
                $attempt++;

                continue;
            }

            if (! $this->policy->shouldRetryStatus($response->statusCode)
                || ! $this->mayRetry($request->safety(), $attempt)) {
                return $response;
            }

            $delay = $this->delay($attempt, $response->headers, $totalDelay);

            if ($delay === null) {
                return $response;
            }

            $this->sleeper->sleepMilliseconds($delay);
            $totalDelay += $delay;
            $attempt++;
        }
    }

    public function stream(StreamReadRequest $request): StreamReadResponse
    {
        $attempt = 1;
        $totalDelay = 0;

        while (true) {
            try {
                $response = $this->executor->stream($request);
            } catch (TransportFailed $exception) {
                if (! $this->mayRetry($request->safety(), $attempt)) {
                    throw $exception;
                }

                $delay = $this->delay($attempt, new ReadHeaders, $totalDelay);

                if ($delay === null) {
                    throw $exception;
                }

                $this->sleeper->sleepMilliseconds($delay);
                $totalDelay += $delay;
                $attempt++;

                continue;
            }

            if (! $this->policy->shouldRetryStatus($response->statusCode)
                || ! $this->mayRetry($request->safety(), $attempt)) {
                return $response;
            }

            $delay = $this->delay($attempt, $response->headers, $totalDelay);

            if ($delay === null) {
                return $response;
            }

            $response->body->close();
            $this->sleeper->sleepMilliseconds($delay);
            $totalDelay += $delay;
            $attempt++;
        }
    }

    private function mayRetry(ReadSafety $safety, int $attempt): bool
    {
        return $safety === ReadSafety::Safe && $attempt < $this->policy->maximumAttempts;
    }

    private function delay(int $attempt, ReadHeaders $headers, int $totalDelay): ?int
    {
        $delay = $this->policy->delayMilliseconds($attempt, $headers, $this->clock->unixTime(), $this->jitter);

        if ($delay > $this->policy->maximumDelayMilliseconds
            || $totalDelay + $delay > $this->policy->maximumTotalDelayMilliseconds) {
            return null;
        }

        return $delay;
    }

    /** @return array{transport: string, credentials: string} */
    public function __debugInfo(): array
    {
        return ['transport' => 'retrying-read-executor', 'credentials' => '[REDACTED]'];
    }

    /** @return array{transport: string, credentials: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Retrying read executors cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Retrying read executors cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Retrying read executors cannot be unserialized.');
    }
}
