<?php

namespace App\Events\Billing;

use App\Models\BillingInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoicePaid
{
    use Dispatchable, SerializesModels;

    public function __construct(public BillingInvoice $invoice) {}
}
