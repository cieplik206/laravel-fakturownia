<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Contracts;

interface ReadBodyStream
{
    public function read(int $length): string;

    public function eof(): bool;

    public function close(): void;
}
