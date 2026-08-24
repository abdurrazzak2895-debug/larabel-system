<?php

namespace App\Contracts;

interface PortalAvailabilityProviderInterface
{
    /** @return array<string, mixed> */
    public function refreshAccount(string $sessionCookie, string $accountId): array;

    /** @return array<int, array<string, mixed>> */
    public function occupations(string $sessionCookie): array;

    /** @return array<string, mixed> */
    public function searchDates(
        string $sessionCookie,
        string $accountId,
        int|string $categoryId,
        string $startFrom,
    ): array;

    /** @return array<string, mixed> */
    public function centers(
        string $sessionCookie,
        string $accountId,
        int|string $categoryId,
        string $city,
        string $date,
        int|string $occupationId,
        string $languageCode,
    ): array;
}
