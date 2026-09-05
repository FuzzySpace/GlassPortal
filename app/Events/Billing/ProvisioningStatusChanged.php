<?php

namespace App\Events\Billing;

use App\Models\ProvisioningRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProvisioningStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ProvisioningRequest $request,
        public string $previousStatus,
        public string $newStatus,
    ) {}
}
