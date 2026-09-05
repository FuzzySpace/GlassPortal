<?php

namespace App\Mail\Billing;

use App\Models\BillingChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChangeRequestStatusUpdate extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public BillingChangeRequest $changeRequest,
        public string $newStatus,
    ) {}

    public function envelope(): Envelope
    {
        $label = str_replace('_', ' ', ucfirst($this->newStatus));

        return new Envelope(
            subject: "Billing request {$label} — " . config('app.name', 'GlassPortal'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.billing.change-request-update');
    }
}
