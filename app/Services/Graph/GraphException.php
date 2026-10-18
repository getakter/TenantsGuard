<?php

namespace App\Services\Graph;

use RuntimeException;

class GraphException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly ?string $graphCode = null)
    {
        parent::__construct($message, $status);
    }

    public function isPermissionOrLicense(): bool
    {
        return in_array($this->status, [401, 403], true)
            || in_array($this->graphCode, ['Authorization_RequestDenied', 'Forbidden', 'AccessDenied'], true);
    }

    public function friendly(): string
    {
        return match (true) {
            $this->graphCode === 'AccessPolicy' => $this->getMessage(),
            $this->status === 403 => 'Access denied by Microsoft Graph. The app is missing a permission, admin consent has not been granted, or the tenant lacks the required licence. ('.$this->getMessage().')',
            $this->status === 401 => 'Authentication with the tenant failed. Re-run admin consent. ('.$this->getMessage().')',
            $this->status === 404 => 'Resource not found in this tenant. ('.$this->getMessage().')',
            $this->status === 429 => 'Microsoft Graph throttled the request. Try again shortly.',
            default => $this->getMessage(),
        };
    }
}
