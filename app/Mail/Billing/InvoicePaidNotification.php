<?php

namespace App\Mail\Billing;

use App\Models\BillingInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoicePaidNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public BillingInvoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invoice paid — ' . config('app.name', 'GlassPortal'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.billing.invoice-paid');
    }
}
