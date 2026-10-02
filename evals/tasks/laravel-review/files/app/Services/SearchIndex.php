<?php

namespace App\Services;

interface SearchIndex
{
    public function upsert(string $index, int $id, array $document): void;

    public function delete(string $index, int $id): void;
}
