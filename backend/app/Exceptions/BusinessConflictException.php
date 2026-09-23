<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BusinessConflictException extends Exception implements ShouldntReport
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, private readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'details' => $this->details ?: null,
        ]), Response::HTTP_CONFLICT);
    }
}
