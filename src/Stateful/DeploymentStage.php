<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful;

/**
 * Consumer deployment metadata only; never capability or live-evidence proof.
 */
enum DeploymentStage: string
{
    case Production = 'production';
    case NonProduction = 'non_production';
}
