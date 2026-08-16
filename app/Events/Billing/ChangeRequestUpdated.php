<?php

namespace App\Events\Billing;

use App\Models\BillingChangeRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChangeRequestUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public BillingChangeRequest $changeRequest,
        public string $previousStatus,
        public string $newStatus,
    ) {}
}
