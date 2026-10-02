<?php

namespace App\Services;

interface MailchimpClient
{
    /** Adds or updates the list member with this email address. */
    public function upsertMember(string $listId, string $email, array $mergeFields): void;
}
