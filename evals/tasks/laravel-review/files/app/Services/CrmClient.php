<?php

namespace App\Services;

interface CrmClient
{
    /** Appends an event to the contact's timeline. */
    public function recordEvent(string $contactId, string $event, array $properties = []): void;
}
