<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;

class ResponseService
{
    /**
     * Return a successful JSON response.
     */
    public static function success(mixed $data = null, int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
        ], $statusCode);
    }

    /**
     * Return an error JSON response.
     */
    public static function error(
        string $message = 'An error occurred.',
        int $statusCode = 400,
        mixed $errors = null
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], $statusCode);
    }

    /**
     * Build a response directly from an Illuminate HTTP Client response.
     */
    public static function fromHttpClient(Response $response, string $defaultMessage = 'Request failed.'): JsonResponse
    {
        $body = $response->json();

        return $response->successful()
            ? self::success($body, $response->status())
            : self::error(
                $body['message'] ?? $defaultMessage,
                $response->status() >= 500 ? 500 : ($response->status() ?: 400),
                $body['errors'] ?? null
            );
    }
}
