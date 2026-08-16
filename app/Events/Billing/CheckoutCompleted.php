<?php

namespace App\Events\Billing;

use App\Models\BillingCheckoutSession;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CheckoutCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public BillingCheckoutSession $session) {}
}
