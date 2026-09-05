<?php

namespace App\Mail\Billing;

use App\Models\BillingSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionActive extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public BillingSubscription $subscription) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your subscription is active — ' . config('app.name', 'GlassPortal'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.billing.subscription-active');
    }
}
