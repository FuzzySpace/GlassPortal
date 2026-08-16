<?php

namespace App\Providers;

use App\Events\Billing\ChangeRequestUpdated;
use App\Events\Billing\CheckoutCompleted;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\ProvisioningStatusChanged;
use App\Events\Billing\SubscriptionActivated;
use App\Listeners\SendBillingNotifications;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class BillingEventServiceProvider extends ServiceProvider
{
    protected $listen = [
        CheckoutCompleted::class => [
            [SendBillingNotifications::class, 'handleCheckoutCompleted'],
        ],
        SubscriptionActivated::class => [
            [SendBillingNotifications::class, 'handleSubscriptionActivated'],
        ],
        InvoicePaid::class => [
            [SendBillingNotifications::class, 'handleInvoicePaid'],
        ],
        ProvisioningStatusChanged::class => [
            [SendBillingNotifications::class, 'handleProvisioningStatusChanged'],
        ],
        ChangeRequestUpdated::class => [
            [SendBillingNotifications::class, 'handleChangeRequestUpdated'],
        ],
    ];
}

