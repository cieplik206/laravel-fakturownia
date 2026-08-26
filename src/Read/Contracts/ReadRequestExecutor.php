<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Contracts;

use Cieplik206\Fakturownia\Read\Requests\JsonReadRequest;
use Cieplik206\Fakturownia\Read\Requests\StreamReadRequest;
use Cieplik206\Fakturownia\Read\Responses\JsonReadResponse;
use Cieplik206\Fakturownia\Read\Responses\StreamReadResponse;

interface ReadRequestExecutor
{
    public function execute(JsonReadRequest $request): JsonReadResponse;

    public function stream(StreamReadRequest $request): StreamReadResponse;
}
