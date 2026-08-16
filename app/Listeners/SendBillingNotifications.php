<?php

namespace App\Listeners;

use App\Events\Billing\ChangeRequestUpdated;
use App\Events\Billing\CheckoutCompleted;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\ProvisioningStatusChanged;
use App\Events\Billing\SubscriptionActivated;
use App\Mail\Billing\ChangeRequestStatusUpdate;
use App\Mail\Billing\CheckoutConfirmation;
use App\Mail\Billing\InvoicePaidNotification;
use App\Mail\Billing\ProvisioningUpdate;
use App\Mail\Billing\SubscriptionActive;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Listens to billing lifecycle events and dispatches email notifications.
 * Queued so webhook processing is never blocked by mail delivery.
 * Fails silently (logs warning) if mail is not configured — the billing
 * state machine must never depend on email success.
 */
class SendBillingNotifications implements ShouldQueue
{
    public int $tries = 2;

    public function handleCheckoutCompleted(CheckoutCompleted $event): void
    {
        $session = $event->session;
        $email   = $this->resolveEmail($session->user_id);

        if ($email === null) {
            return;
        }

        $this->safeSend($email, new CheckoutConfirmation($session));
    }

    public function handleSubscriptionActivated(SubscriptionActivated $event): void
    {
        $sub   = $event->subscription;
        $email = $this->resolveEmailFromCustomer($sub->billing_customer_id);

        if ($email === null) {
            return;
        }

        $this->safeSend($email, new SubscriptionActive($sub));
    }

    public function handleInvoicePaid(InvoicePaid $event): void
    {
        $invoice = $event->invoice;
        $email   = $this->resolveEmailFromCustomer($invoice->billing_customer_id);

        if ($email === null) {
            return;
        }

        $this->safeSend($email, new InvoicePaidNotification($invoice));
    }

    public function handleProvisioningStatusChanged(ProvisioningStatusChanged $event): void
    {
        $request = $event->request;
        $email   = $this->resolveEmail($request->user_id);

        if ($email === null) {
            return;
        }

        // Only notify on customer-meaningful transitions.
        $notify = ['approved', 'queued', 'running', 'completed', 'failed', 'rejected'];
        if (! in_array($event->newStatus, $notify, true)) {
            return;
        }

        $this->safeSend($email, new ProvisioningUpdate($request, $event->newStatus));
    }

    public function handleChangeRequestUpdated(ChangeRequestUpdated $event): void
    {
        $cr    = $event->changeRequest;
        $email = $this->resolveEmail($cr->user_id);

        if ($email === null) {
            return;
        }

        $this->safeSend($email, new ChangeRequestStatusUpdate($cr, $event->newStatus));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveEmail(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        return User::find($userId)?->email;
    }

    private function resolveEmailFromCustomer(?int $billingCustomerId): ?string
    {
        if ($billingCustomerId === null) {
            return null;
        }

        $customer = \App\Models\BillingCustomer::find($billingCustomerId);

        return $customer?->email ?? $this->resolveEmail($customer?->user_id);
    }

    private function safeSend(string $to, \Illuminate\Mail\Mailable $mailable): void
    {
        try {
            if (config('billing.notifications.enabled', true)) {
                Mail::to($to)->send($mailable);
            }
        } catch (\Throwable $e) {
            Log::warning('[BillingNotification] Mail delivery failed', [
                'to'    => $to,
                'class' => get_class($mailable),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
