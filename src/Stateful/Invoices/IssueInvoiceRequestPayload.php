<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices;

use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use InvalidArgumentException;
use LogicException;

final readonly class IssueInvoiceRequestPayload
{
    private const int MaximumPayloadBytes = 2_000_000;

    private const int MaximumPayloadDepth = 4;

    private const int MaximumPayloadNodes = 12_000;

    /** @var array<string, mixed> */
    private array $invoice;

    /** @param array<string, mixed> $invoice */
    private function __construct(
        array $invoice,
        public InvoiceDraftValidationResult $validation,
    ) {
        $nodes = 0;
        $bytes = 0;
        $this->invoice = self::validatePayload($invoice, 0, $nodes, $bytes);
    }

    public static function fromDraft(
        InvoiceDraft $draft,
        InvoiceValidationProfile $profile,
        RemoteInvoiceIdentity $identity,
        InvoiceDraftValidator $validator = new InvoiceDraftValidator,
    ): self {
        $draft = self::validatedDraft($draft);

        if ($identity->scope->documentKind !== $draft->kind
            || $identity->scope->departmentId !== $draft->departmentId) {
            throw new InvalidArgumentException('Remote identity scope does not match the invoice draft.');
        }

        $oid = $identity->oid();
        if ($oid !== null && ! self::validReference($oid, 256)) {
            throw new InvalidArgumentException('Remote invoice OID is invalid.');
        }

        $validation = $validator->validate($draft, $profile);
        if ($profile->rejectsIssues()) {
            $validation->throwIfInvalid();
        }

        $invoice = [
            'kind' => $draft->kind,
            'income' => $draft->income ? '1' : '0',
            'sell_date' => $draft->sellDate,
            'issue_date' => $draft->issueDate,
            'buyer_company' => $draft->buyer->company,
            'department_id' => $draft->departmentId,
            'buyer_last_name' => $draft->buyer->lastName,
            'buyer_name' => $draft->buyer->name,
            'buyer_tax_no' => $draft->buyer->taxNumber,
            'buyer_post_code' => $draft->buyer->postCode,
            'buyer_city' => $draft->buyer->city,
            'buyer_street' => $draft->buyer->street,
            'buyer_country' => $draft->buyer->country,
            'buyer_email' => $draft->buyer->email,
            'payment_type' => $draft->payment->type,
            'status' => $draft->payment->status,
            'paid' => $draft->payment->paid->decimal(),
            'description' => $draft->description,
            'payment_to_kind' => $draft->payment->dueKind,
            'paid_date' => $draft->payment->paidDate,
            'positions' => array_map(
                static fn (InvoiceLine $position): array => [
                    'name' => $position->name,
                    'tax' => $position->tax,
                    'total_price_gross' => $position->totalGross->decimal(),
                    'quantity' => $position->quantity,
                    'quantity_unit' => $position->unit,
                ],
                $draft->positions,
            ),
            'payment_to' => $draft->payment->dueDate,
            'buyer_tax_no_kind' => $draft->buyer->taxNumberKind,
            'buyer_first_name' => $draft->buyer->firstName,
            'number' => $draft->number,
            'currency' => $draft->currency(),
        ];

        if ($profile->usesKsefConstraints()) {
            $invoice['use_invoice_issuer'] = false;
        }

        if ($oid !== null) {
            $invoice['oid'] = $oid;
        }

        if ($identity->usesOidUnique()) {
            $invoice['oid_unique'] = 'yes';
        }

        return new self($invoice, $validation);
    }

    /** @return array{placement: string, field: string} */
    public function authenticationContract(): array
    {
        return [
            'placement' => 'json_body_top_level',
            'field' => 'api_token',
        ];
    }

    /** @return array{Accept: string, Content-Type: string} */
    public function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /** @return array<string, never> */
    public function query(): array
    {
        return [];
    }

    /** @return array{invoice: array<string, mixed>} */
    public function bodyWithoutCredentials(): array
    {
        return ['invoice' => $this->invoice];
    }

    /** @return array{invoice: string, validation_issues: int, credentials: string} */
    public function __debugInfo(): array
    {
        return [
            'invoice' => '[REDACTED]',
            'validation_issues' => count($this->validation->issues),
            'credentials' => '[NOT_PRESENT]',
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Invoice request payloads cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Invoice request payloads cannot be unserialized.');
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $values
     * @return array<TKey, mixed>
     */
    private static function validatePayload(
        array $values,
        int $depth,
        int &$nodes,
        int &$bytes,
    ): array {
        if ($depth > self::MaximumPayloadDepth) {
            throw new InvalidArgumentException('Invoice request payload is too deeply nested.');
        }

        $validated = [];

        foreach ($values as $key => $value) {
            $nodes++;
            if ($nodes > self::MaximumPayloadNodes) {
                throw new InvalidArgumentException('Invoice request payload contains too many values.');
            }

            if (is_string($key)) {
                self::assertSafeKey($key);
                $bytes += strlen($key);
            }

            if (is_array($value)) {
                $validated[$key] = self::validatePayload($value, $depth + 1, $nodes, $bytes);

                continue;
            }

            if (! is_string($value) && ! is_int($value) && ! is_bool($value) && $value !== null) {
                throw new InvalidArgumentException('Invoice request payload contains a mutable or ambiguous value.');
            }

            if (is_string($value)) {
                $bytes += strlen($value);

                if (preg_match('//u', $value) !== 1
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F\p{Cf}]/u', $value) === 1) {
                    throw new InvalidArgumentException('Invoice request payload contains invalid text.');
                }
            }

            if ($bytes > self::MaximumPayloadBytes) {
                throw new InvalidArgumentException('Invoice request payload exceeds the byte limit.');
            }

            $validated[$key] = $value;
        }

        return $validated;
    }

    private static function assertSafeKey(string $key): void
    {
        if ($key === ''
            || strlen($key) > 128
            || preg_match('//u', $key) !== 1
            || preg_match('/[\p{Cc}\p{Cf}]/u', $key) === 1
            || preg_match('/(?:api[_-]?token|access[_-]?token|authorization|password|secret|credential)/i', $key) === 1) {
            throw new InvalidArgumentException('Invoice request payload contains an invalid or reserved key.');
        }
    }

    private static function validatedDraft(InvoiceDraft $draft): InvoiceDraft
    {
        $buyer = new InvoiceBuyer(
            $draft->buyer->company,
            $draft->buyer->name,
            $draft->buyer->taxNumber,
            $draft->buyer->postCode,
            $draft->buyer->city,
            $draft->buyer->street,
            $draft->buyer->country,
            $draft->buyer->email,
            $draft->buyer->lastName,
            $draft->buyer->firstName,
            $draft->buyer->taxNumberKind,
        );
        $payment = new InvoicePayment(
            $draft->payment->type,
            $draft->payment->status,
            self::validatedMoney($draft->payment->paid),
            $draft->payment->dueKind,
            $draft->payment->paidDate,
            $draft->payment->dueDate,
        );
        $positions = array_map(
            static fn (InvoiceLine $position): InvoiceLine => new InvoiceLine(
                $position->name,
                $position->tax,
                self::validatedMoney($position->totalGross),
                $position->quantity,
                $position->unit,
            ),
            $draft->positions,
        );

        return new InvoiceDraft(
            $draft->kind,
            $draft->income,
            $draft->sellDate,
            $draft->issueDate,
            $draft->departmentId,
            $buyer,
            $payment,
            $draft->description,
            $positions,
            $draft->number,
        );
    }

    private static function validatedMoney(Money $money): Money
    {
        return new Money($money->minorUnits, $money->currency, $money->fractionDigits);
    }

    private static function validReference(string $value, int $maximumBytes): bool
    {
        return $value !== ''
            && $value === trim($value)
            && strlen($value) <= $maximumBytes
            && preg_match('//u', $value) === 1
            && preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) !== 1;
    }
}
