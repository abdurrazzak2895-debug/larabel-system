<?php

namespace App\Services\Contracts;

interface ServiceInterface
{
    public function handle(array $params): mixed;
}
