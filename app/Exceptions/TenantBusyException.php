<?php

namespace App\Exceptions;

use App\Models\Tenant;
use RuntimeException;

class TenantBusyException extends RuntimeException
{
    public function __construct(public readonly Tenant $tenant)
    {
        parent::__construct("Another operation is already running for {$tenant->name}. Wait a moment and try again.");
    }
}
