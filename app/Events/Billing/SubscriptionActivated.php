<?php

namespace App\Events\Billing;

use App\Models\BillingSubscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionActivated
{
    use Dispatchable, SerializesModels;

    public function __construct(public BillingSubscription $subscription) {}
}
