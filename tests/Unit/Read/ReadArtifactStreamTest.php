<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Read\Exceptions\ArtifactValidationFailed;
use Cieplik206\Fakturownia\Read\Exceptions\ResourceNotFound;
use Cieplik206\Fakturownia\Read\FakturowniaReadClient;
use Cieplik206\Fakturownia\Read\Requests\DownloadInvoiceAttachmentsZipRequest;
use Cieplik206\Fakturownia\Read\Requests\DownloadInvoiceKsefUpoRequest;
use Cieplik206\Fakturownia\Read\Requests\DownloadInvoiceKsefXmlRequest;
use Cieplik206\Fakturownia\Read\Requests\DownloadInvoicePdfRequest;
use Cieplik206\Fakturownia\Read\Requests\StreamReadRequest;
use Cieplik206\Fakturownia\Read\Responses\ReadArtifactStream;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;
use Cieplik206\Fakturownia\Testing\Read\FrozenReadClock;
use Cieplik206\Fakturownia\Testing\Read\LiteralReadCapabilityGate;
use Cieplik206\Fakturownia\Testing\Read\LiteralReadRequestExecutor;
use Cieplik206\Fakturownia\Testing\Read\LiteralStreamExchange;

function rt3DrainArtifact(ReadArtifactStream $stream, int $chunkBytes = 7): string
{
    $body = '';

    while (! $stream->eof()) {
        $body .= $stream->read($chunkBytes);
    }

    return $body;
}

/** @param array<string, string|list<string>> $headers */
function rt3StreamExchange(
    StreamReadRequest $request,
    string $body,
    array $headers,
    int $status = 200,
    int $redirectCount = 0,
    bool $crossHostRedirected = false,
    bool $credentialsStrippedOnRedirect = true,
): LiteralStreamExchange {
    return LiteralStreamExchange::response(
        $request,
        $status,
        $headers,
        $body,
        chunkBytes: 37,
        redirectCount: $redirectCount,
        crossHostRedirected: $crossHostRedirected,
        credentialsStrippedOnRedirect: $credentialsStrippedOnRedirect,
    );
}

it('streams validated PDF ZIP KSeF XML and UPO bytes with size and checksum evidence', function (): void {
    $pdfRequest = new DownloadInvoicePdfRequest('11');
    $zipRequest = new DownloadInvoiceAttachmentsZipRequest('11');
    $xmlRequest = new DownloadInvoiceKsefXmlRequest('11');
    $upoRequest = new DownloadInvoiceKsefUpoRequest('11');
    $pdf = "%PDF-1.7\nobject\n%%EOF\n";
    $zip = "PK\x05\x06".str_repeat("\0", 18);
    $xml = '<?xml version="1.0" encoding="UTF-8"?><Invoice xmlns="urn:ksef"/>';
    $upo = '<?xml version="1.0"?><UPO><Reference>abc</Reference></UPO>';
    $executor = new LiteralReadRequestExecutor([
        rt3StreamExchange($pdfRequest, $pdf, [
            'content-type' => 'application/pdf',
            'content-length' => (string) strlen($pdf),
        ]),
        rt3StreamExchange($zipRequest, $zip, [
            'content-type' => 'application/zip',
            'content-length' => (string) strlen($zip),
        ]),
        rt3StreamExchange($xmlRequest, $xml, [
            'content-type' => 'application/xml; charset=utf-8',
            'content-length' => (string) strlen($xml),
        ], redirectCount: 1, crossHostRedirected: true),
        rt3StreamExchange($upoRequest, $upo, [
            'content-type' => 'text/xml',
            'content-length' => (string) strlen($upo),
            'x-request-id' => 'upo-request',
        ]),
    ]);
    $client = new FakturowniaReadClient(
        $executor,
        new LiteralReadCapabilityGate([
            ReadCapability::InvoicePdfStream,
            ReadCapability::InvoiceAttachmentsZipStream,
            ReadCapability::InvoiceKsefXmlStream,
            ReadCapability::InvoiceKsefUpoStream,
        ]),
        new FrozenReadClock(1_788_192_000),
    );

    $pdfStream = $client->invoices()->pdf('11');
    $zipStream = $client->invoices()->attachmentsZip('11');
    $xmlStream = $client->invoices()->ksefXml('11');
    $upoStream = $client->invoices()->ksefUpo('11');

    expect(rt3DrainArtifact($pdfStream))->toBe($pdf)
        ->and($pdfStream->size())->toBe(strlen($pdf))
        ->and($pdfStream->sha256())->toBe(hash('sha256', $pdf))
        ->and(rt3DrainArtifact($zipStream))->toBe($zip)
        ->and($zipStream->sha256())->toBe(hash('sha256', $zip))
        ->and(rt3DrainArtifact($xmlStream))->toBe($xml)
        ->and($xmlStream->sha256())->toBe(hash('sha256', $xml))
        ->and(rt3DrainArtifact($upoStream))->toBe($upo)
        ->and($upoStream->providerRequestId())->toBe('upo-request')
        ->and($executor->requests()[2]->path())->toBe('/invoices/11/attachment')
        ->and($executor->requests()[2]->query()->all())->toBe(['kind' => 'gov'])
        ->and($executor->requests()[3]->query()->all())->toBe(['kind' => 'gov_upo']);

    expect(json_encode($pdfStream, JSON_THROW_ON_ERROR))->toBe('{"format":"pdf","validated":true,"credentials":"[REDACTED]"}')
        ->and(fn () => clone $pdfStream)->toThrow(LogicException::class)
        ->and(fn () => serialize($pdfStream))->toThrow(LogicException::class);

    $executor->assertExhausted();
});

it('does not expose artifact size or checksum before the complete stream validates', function (): void {
    $request = new DownloadInvoicePdfRequest('12');
    $pdf = "%PDF-1.7\n".str_repeat('x', 900)."\n%%EOF\n";
    $executor = new LiteralReadRequestExecutor([
        rt3StreamExchange($request, $pdf, [
            'content-type' => 'application/pdf',
            'content-length' => (string) strlen($pdf),
        ]),
    ]);
    $client = new FakturowniaReadClient(
        $executor,
        new LiteralReadCapabilityGate([ReadCapability::InvoicePdfStream]),
        new FrozenReadClock(1_788_192_000),
    );
    $stream = $client->invoices()->pdf('12');

    expect($stream->isValidated())->toBeFalse()
        ->and(fn () => $stream->sha256())->toThrow(LogicException::class)
        ->and(fn () => $stream->size())->toThrow(LogicException::class);

    expect(rt3DrainArtifact($stream, 19))->toBe($pdf)
        ->and($stream->isValidated())->toBeTrue()
        ->and($stream->size())->toBe(strlen($pdf));
});

/**
 * @param  array<string, string|list<string>>  $headers
 */
it('rejects corrupt artifact metadata redirects and bytes before they can be accepted', function (
    StreamReadRequest $request,
    ReadCapability $capability,
    string $body,
    array $headers,
    int $redirectCount,
    bool $crossHostRedirected,
    bool $credentialsStrippedOnRedirect,
): void {
    $executor = new LiteralReadRequestExecutor([
        rt3StreamExchange(
            $request,
            $body,
            $headers,
            redirectCount: $redirectCount,
            crossHostRedirected: $crossHostRedirected,
            credentialsStrippedOnRedirect: $credentialsStrippedOnRedirect,
        ),
    ]);
    $client = new FakturowniaReadClient(
        $executor,
        new LiteralReadCapabilityGate([$capability]),
        new FrozenReadClock(1_788_192_000),
    );

    $open = fn (): ReadArtifactStream => match ($capability) {
        ReadCapability::InvoicePdfStream => $client->invoices()->pdf('21'),
        ReadCapability::InvoiceAttachmentsZipStream => $client->invoices()->attachmentsZip('21'),
        ReadCapability::InvoiceKsefXmlStream => $client->invoices()->ksefXml('21'),
        ReadCapability::InvoiceKsefUpoStream => $client->invoices()->ksefUpo('21'),
        default => throw new LogicException('Unexpected artifact capability.'),
    };

    expect($open)->toThrow(ArtifactValidationFailed::class);
})->with([
    'wrong PDF MIME' => [
        new DownloadInvoicePdfRequest('21'),
        ReadCapability::InvoicePdfStream,
        "%PDF-1.7\n%%EOF\n",
        ['content-type' => 'text/html'],
        0,
        false,
        true,
    ],
    'wrong PDF magic' => [
        new DownloadInvoicePdfRequest('21'),
        ReadCapability::InvoicePdfStream,
        "not-a-pdf\n%%EOF\n",
        ['content-type' => 'application/pdf'],
        0,
        false,
        true,
    ],
    'corrupt PDF trailer' => [
        new DownloadInvoicePdfRequest('21'),
        ReadCapability::InvoicePdfStream,
        '%PDF-1.7 without trailer',
        ['content-type' => 'application/pdf'],
        0,
        false,
        true,
    ],
    'ambiguous content type' => [
        new DownloadInvoicePdfRequest('21'),
        ReadCapability::InvoicePdfStream,
        "%PDF-1.7\n%%EOF\n",
        ['content-type' => ['application/pdf', 'text/html']],
        0,
        false,
        true,
    ],
    'declared length mismatch' => [
        new DownloadInvoicePdfRequest('21'),
        ReadCapability::InvoicePdfStream,
        "%PDF-1.7\n%%EOF\n",
        ['content-type' => 'application/pdf', 'content-length' => '999'],
        0,
        false,
        true,
    ],
    'declared body over endpoint limit' => [
        new DownloadInvoicePdfRequest('21'),
        ReadCapability::InvoicePdfStream,
        "%PDF-1.7\n%%EOF\n",
        ['content-type' => 'application/pdf', 'content-length' => '20971521'],
        0,
        false,
        true,
    ],
    'too many redirects' => [
        new DownloadInvoicePdfRequest('21'),
        ReadCapability::InvoicePdfStream,
        "%PDF-1.7\n%%EOF\n",
        ['content-type' => 'application/pdf'],
        4,
        false,
        true,
    ],
    'cross-host credentials retained' => [
        new DownloadInvoicePdfRequest('21'),
        ReadCapability::InvoicePdfStream,
        "%PDF-1.7\n%%EOF\n",
        ['content-type' => 'application/pdf'],
        1,
        true,
        false,
    ],
    'HTML disguised as XML' => [
        new DownloadInvoiceKsefXmlRequest('21'),
        ReadCapability::InvoiceKsefXmlStream,
        '<html><body>login</body></html>',
        ['content-type' => 'application/xml'],
        0,
        false,
        true,
    ],
    'unsafe XML doctype' => [
        new DownloadInvoiceKsefUpoRequest('21'),
        ReadCapability::InvoiceKsefUpoStream,
        '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY y SYSTEM "file:///etc/passwd">]><x>&y;</x>',
        ['content-type' => 'application/xml'],
        0,
        false,
        true,
    ],
    'malformed XML tree' => [
        new DownloadInvoiceKsefXmlRequest('21'),
        ReadCapability::InvoiceKsefXmlStream,
        '<Invoice><Item></Invoice>',
        ['content-type' => 'application/xml'],
        0,
        false,
        true,
    ],
    'corrupt ZIP trailer' => [
        new DownloadInvoiceAttachmentsZipRequest('21'),
        ReadCapability::InvoiceAttachmentsZipStream,
        "PK\x03\x04payload",
        ['content-type' => 'application/zip'],
        0,
        false,
        true,
    ],
    'inconsistent ZIP central directory offset' => [
        new DownloadInvoiceAttachmentsZipRequest('21'),
        ReadCapability::InvoiceAttachmentsZipStream,
        "PK\x03\x04payloadPK\x05\x06".str_repeat("\0", 18),
        ['content-type' => 'application/zip'],
        0,
        false,
        true,
    ],
    'multi-disk ZIP metadata' => [
        new DownloadInvoiceAttachmentsZipRequest('21'),
        ReadCapability::InvoiceAttachmentsZipStream,
        "PK\x05\x06".pack('vvvvVVv', 1, 0, 0, 0, 0, 0, 0),
        ['content-type' => 'application/zip'],
        0,
        false,
        true,
    ],
]);

it('classifies a missing remote XML artifact before inspecting its body', function (): void {
    $request = new DownloadInvoiceKsefXmlRequest('31');
    $executor = new LiteralReadRequestExecutor([
        rt3StreamExchange($request, '<error/>', ['content-type' => 'application/xml'], status: 404),
    ]);
    $client = new FakturowniaReadClient(
        $executor,
        new LiteralReadCapabilityGate([ReadCapability::InvoiceKsefXmlStream]),
        new FrozenReadClock(1_788_192_000),
    );

    expect(fn () => $client->invoices()->ksefXml('31'))->toThrow(ResourceNotFound::class);
});
