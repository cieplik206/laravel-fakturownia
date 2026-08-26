<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Resources;

use Cieplik206\Fakturownia\Client\Attributes\RequiresCapability;
use Cieplik206\Fakturownia\Read\Data\PaymentListQuery;
use Cieplik206\Fakturownia\Read\Data\PaymentResponseData;
use Cieplik206\Fakturownia\Read\Data\ReadPage;
use Cieplik206\Fakturownia\Read\Exceptions\PaginationLimitReached;
use Cieplik206\Fakturownia\Read\Exceptions\UnsupportedCapability;
use Cieplik206\Fakturownia\Read\Requests\GetPaymentRequest;
use Cieplik206\Fakturownia\Read\Requests\ListPaymentsRequest;
use Cieplik206\Fakturownia\Read\ValueObjects\Pagination;
use Generator;

final readonly class PaymentsResource extends ReadResource
{
    #[RequiresCapability('payment.read.get', GetPaymentRequest::class)]
    public function get(string $paymentId): PaymentResponseData
    {
        $request = new GetPaymentRequest($paymentId);

        throw new UnsupportedCapability($request->capability());
    }

    /** @return ReadPage<PaymentResponseData> */
    #[RequiresCapability('payment.read.list', ListPaymentsRequest::class)]
    public function list(PaymentListQuery $query = new PaymentListQuery): ReadPage
    {
        $request = new ListPaymentsRequest($query);
        $response = $this->execute($request);
        $payloads = $this->decoder->list($request, $response);
        $this->assertRemotePageSize($request, $response, count($payloads), $query->pagination->perPage);
        $items = array_map(
            static fn (array $payload): PaymentResponseData => PaymentResponseData::fromPayload($payload, $request->operation()),
            $payloads,
        );

        return new ReadPage(
            $query->pagination->page,
            $query->pagination->perPage,
            $items,
            $response->headers->providerRequestId(),
        );
    }

    /** @return iterable<PaymentResponseData> */
    #[RequiresCapability('payment.read.list', ListPaymentsRequest::class)]
    public function stream(PaymentListQuery $query = new PaymentListQuery, int $maximumPages = Pagination::MaximumStreamPages): iterable
    {
        Pagination::assertMaximumPages($maximumPages);
        $this->capabilities->assertSupported((new ListPaymentsRequest($query))->capability());

        return $this->iterate($query, $maximumPages);
    }

    /** @return Generator<int, PaymentResponseData> */
    private function iterate(PaymentListQuery $query, int $maximumPages): Generator
    {
        $seenPages = [];
        $seenRemoteIds = [];

        for ($offset = 0; $offset < $maximumPages; $offset++) {
            $page = $this->list($query->withPage($query->pagination->page + $offset));
            $items = $page->items();
            $signature = hash('sha256', implode("\0", array_map(
                static fn (PaymentResponseData $payment): string => $payment->remoteId,
                $items,
            )));

            if (isset($seenPages[$signature])) {
                return;
            }

            $seenPages[$signature] = true;
            $newItems = 0;

            foreach ($items as $payment) {
                if (isset($seenRemoteIds[$payment->remoteId])) {
                    continue;
                }

                $seenRemoteIds[$payment->remoteId] = true;
                $newItems++;

                yield $payment;
            }

            if ($page->isTerminal() || $newItems === 0) {
                return;
            }
        }

        throw new PaginationLimitReached('payment.read.list', $maximumPages);
    }
}
