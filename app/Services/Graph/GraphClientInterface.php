<?php

namespace App\Services\Graph;

/**
 * Minimal Microsoft Graph surface used by the scanner and fixes.
 * Paths are relative to v1.0 (e.g. "/users") unless they start with "/beta/".
 */
interface GraphClientInterface
{
    public function get(string $path): array;

    /** Follows @odata.nextLink and returns the merged "value" list. */
    public function getAll(string $path): array;

    public function post(string $path, array $body): array;

    public function patch(string $path, array $body): array;

    public function put(string $path, array $body): array;

    public function delete(string $path): array;
}
