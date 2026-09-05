<?php

namespace App\Mail\Billing;

use App\Models\ProvisioningRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProvisioningUpdate extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ProvisioningRequest $request,
        public string $newStatus,
    ) {}

    public function envelope(): Envelope
    {
        $label = str_replace('_', ' ', ucfirst($this->newStatus));

        return new Envelope(
            subject: "Service provisioning: {$label} — " . config('app.name', 'GlassPortal'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.billing.provisioning-update');
    }
}
