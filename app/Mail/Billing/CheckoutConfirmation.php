<?php

namespace App\Mail\Billing;

use App\Models\BillingCheckoutSession;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CheckoutConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public BillingCheckoutSession $session) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment received — ' . config('app.name', 'GlassPortal'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.billing.checkout-confirmation');
    }
}
