<?php

namespace App\Services\Billing;

use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\BillingEvent;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPaymentMethod;
use App\Models\BillingPlan;
use App\Models\BillingServiceEntitlement;
use App\Models\BillingSubscription;
use App\Models\ProvisioningRequest;
use App\Services\Provisioning\ProvisioningRequestService;
use Illuminate\Support\Carbon;

/**
 * Processes verified Stripe webhook events into local billing records,
 * entitlements, and approval-gated provisioning requests (Phase 27).
 *
 * Boundaries:
 *  - Idempotent on `provider_event_id` (already-processed events are duplicates).
 *  - Records every event in billing_events; unsupported types are ignored.
 *  - NEVER mutates infrastructure and NEVER calls SIONA/Proxmox/DNS/etc.
 *  - May update entitlements (BillingEntitlementService) and create
 *    approval-gated provisioning requests (ProvisioningRequestService) — never
 *    executes provisioning.
 *  - When payload data is insufficient to safely link records, records the
 *    event with warnings rather than guessing.
 */
class StripeWebhookService
{
    public function __construct(
        private BillingEntitlementService $entitlements,
        private ProvisioningRequestService $provisioning,
    ) {}

    /**
     * @param array<string, mixed> $event Decoded Stripe event.
     * @return array{status: string, event: BillingEvent}
     */
    public function handle(array $event): array
    {
        $type            = (string) ($event['type'] ?? '');
        $providerEventId = (string) ($event['id'] ?? '');

        // Idempotency — an already-terminal event is a duplicate (return 2xx).
        $existing = $providerEventId !== ''
            ? BillingEvent::where('provider', 'stripe')->where('provider_event_id', $providerEventId)->first()
            : null;

        if ($existing !== null && in_array($existing->status, [
            BillingEvent::STATUS_PROCESSED,
            BillingEvent::STATUS_PROCESSED_WITH_WARNINGS,
            BillingEvent::STATUS_IGNORED,
        ], true)) {
            return ['status' => 'duplicate', 'event' => $existing];
        }

        $billingEvent = $existing ?? BillingEvent::create([
            'event_type'        => $type,
            'provider'          => 'stripe',
            'provider_event_id' => $providerEventId ?: null,
            'payload'           => $event,
            'status'            => BillingEvent::STATUS_PENDING,
        ]);

        if (! in_array($type, (array) config('billing.webhooks.allowed_events', []), true)) {
            $billingEvent->update(['status' => BillingEvent::STATUS_IGNORED, 'processed_at' => now()]);

            return ['status' => 'ignored', 'event' => $billingEvent];
        }

        try {
            $object = (array) ($event['data']['object'] ?? []);

            $warnings = match ($type) {
                'checkout.session.completed'                                       => $this->handleCheckoutCompleted($object),
                'customer.created', 'customer.updated'                             => $this->handleCustomerUpsert($object),
                'customer.subscription.created', 'customer.subscription.updated'   => $this->handleSubscriptionUpsert($object),
                'customer.subscription.deleted'                                    => $this->handleSubscriptionDeleted($object),
                'invoice.paid', 'invoice.payment_succeeded'                        => $this->handleInvoicePaid($object),
                'invoice.payment_failed'                                           => $this->handleInvoicePaymentFailed($object),
                'payment_method.attached'                                          => $this->handlePaymentMethodAttached($object),
                default                                                            => ['unsupported handler'],
            };

            $clean = empty($warnings);
            $billingEvent->update([
                'status'        => $clean ? BillingEvent::STATUS_PROCESSED : BillingEvent::STATUS_PROCESSED_WITH_WARNINGS,
                'processed_at'  => now(),
                'error_message' => $clean ? null : implode('; ', $warnings),
            ]);

            return ['status' => $clean ? 'processed' : 'processed_with_warnings', 'event' => $billingEvent];
        } catch (\Throwable $e) {
            // Never store the raw exception (it can echo payload data).
            $billingEvent->update([
                'status'        => BillingEvent::STATUS_FAILED,
                'processed_at'  => now(),
                'error_message' => 'handler_error',
            ]);

            return ['status' => 'failed', 'event' => $billingEvent];
        }
    }

    // -------------------------------------------------------------------------
    // Handlers — each returns a list of warning strings (empty = clean).

    /** @return list<string> */
    private function handleCheckoutCompleted(array $object): array
    {
        $sessionId = $object['id'] ?? null;
        if (! $sessionId) {
            return ['missing checkout session id'];
        }

        $warnings = [];
        $local    = BillingCheckoutSession::where('provider_session_id', $sessionId)->first();

        $providerCustomer = $object['customer'] ?? null;
        $providerSub      = $object['subscription'] ?? null;

        if ($local === null) {
            return ['no local checkout session for ' . $sessionId];
        }

        // Link the customer's Stripe id if we now know it.
        if ($providerCustomer && $local->customer && blank($local->customer->stripe_customer_id)) {
            $local->customer->update(['stripe_customer_id' => $providerCustomer]);
        }

        // Create a subscription stub so later subscription.* events link cleanly.
        if ($providerSub) {
            $sub = BillingSubscription::firstOrNew(['stripe_subscription_id' => $providerSub]);
            if (! $sub->exists) {
                $sub->fill([
                    'billing_customer_id' => $local->billing_customer_id,
                    'billing_plan_id'     => $local->billing_plan_id,
                    'status'              => 'incomplete',
                ])->save();
            }
            $local->billing_subscription_id = $sub->id;
        }

        $local->fill([
            'status'                   => BillingCheckoutSession::STATUS_COMPLETE,
            'payment_status'           => $object['payment_status'] ?? $local->payment_status,
            'provider_customer_id'     => $providerCustomer ?: $local->provider_customer_id,
            'provider_subscription_id' => $providerSub ?: $local->provider_subscription_id,
            'amount_total'             => $object['amount_total'] ?? $local->amount_total,
            'completed_at'             => now(),
        ])->save();

        // Do NOT activate entitlements / create provisioning here — wait for the
        // subscription.* / invoice.* events that confirm active/paid state.
        return $warnings;
    }

    /** @return list<string> */
    private function handleCustomerUpsert(array $object): array
    {
        $stripeId = $object['id'] ?? null;
        if (! $stripeId) {
            return ['missing customer id'];
        }

        $customer = BillingCustomer::firstOrNew(['stripe_customer_id' => $stripeId]);
        $customer->name  = $object['name'] ?? $customer->name;
        $customer->email = $object['email'] ?? $customer->email;
        $customer->status = $customer->status ?: 'active';

        $meta = (array) ($object['metadata'] ?? []);
        if (! $customer->organization_id && ! empty($meta['glassportal_organization_id'])) {
            $customer->organization_id = (int) $meta['glassportal_organization_id'];
        }
        if (! $customer->user_id && ! empty($meta['glassportal_user_id'])) {
            $customer->user_id = (int) $meta['glassportal_user_id'];
        }

        $customer->save();

        return [];
    }

    /** @return list<string> */
    private function handleSubscriptionUpsert(array $object): array
    {
        $stripeSubId = $object['id'] ?? null;
        if (! $stripeSubId) {
            return ['missing subscription id'];
        }

        $warnings = [];
        $customer = $this->resolveCustomerByStripeId($object['customer'] ?? null);

        $sub = BillingSubscription::firstOrNew(['stripe_subscription_id' => $stripeSubId]);
        if ($customer !== null) {
            $sub->billing_customer_id = $customer->id;
        } elseif (! $sub->billing_customer_id) {
            $warnings[] = 'no linked billing customer';
        }

        $stripeStatus = (string) ($object['status'] ?? 'incomplete');
        $sub->status  = $stripeStatus;
        $sub->current_period_start = $this->ts($object['current_period_start'] ?? null) ?? $sub->current_period_start;
        $sub->current_period_end   = $this->ts($object['current_period_end'] ?? null) ?? $sub->current_period_end;
        $sub->cancel_at_period_end = (bool) ($object['cancel_at_period_end'] ?? false);

        $priceId = $object['items']['data'][0]['price']['id'] ?? null;
        if ($priceId) {
            $plan = BillingPlan::where('stripe_price_id', $priceId)->first();
            if ($plan !== null) {
                $sub->billing_plan_id = $plan->id;
            }
        }

        if (! $sub->billing_customer_id) {
            return $warnings; // can't safely persist a customerless subscription
        }

        $sub->save();
        $this->syncEntitlementForSubscription($sub->fresh(), $stripeStatus, $warnings);

        return $warnings;
    }

    /** @return list<string> */
    private function handleSubscriptionDeleted(array $object): array
    {
        $stripeSubId = $object['id'] ?? null;
        if (! $stripeSubId) {
            return ['missing subscription id'];
        }

        $sub = BillingSubscription::where('stripe_subscription_id', $stripeSubId)->first();
        if ($sub === null) {
            return ['no local subscription for ' . $stripeSubId];
        }

        $sub->update(['status' => 'canceled']);

        foreach ($sub->serviceEntitlements as $entitlement) {
            $this->entitlements->cancel($entitlement->fresh(), 'subscription deleted');
        }

        return [];
    }

    /** @return list<string> */
    private function handleInvoicePaid(array $object): array
    {
        $invoiceId = $object['id'] ?? null;
        if (! $invoiceId) {
            return ['missing invoice id'];
        }

        $warnings = [];
        $customer = $this->resolveCustomerByStripeId($object['customer'] ?? null);

        $invoice = BillingInvoice::firstOrNew(['stripe_invoice_id' => $invoiceId]);
        if ($customer !== null) {
            $invoice->billing_customer_id = $customer->id;
        } elseif (! $invoice->billing_customer_id) {
            return ['no linked billing customer'];
        }

        $invoice->fill([
            'status'            => 'paid',
            'amount_due_cents'  => $object['amount_due'] ?? $invoice->amount_due_cents ?? 0,
            'amount_paid_cents' => $object['amount_paid'] ?? $invoice->amount_paid_cents ?? 0,
            'currency'          => strtoupper((string) ($object['currency'] ?? $invoice->currency ?? 'USD')),
            'paid_at'           => now(),
        ])->save();

        if (! empty($object['payment_intent'])) {
            $payment = BillingPayment::firstOrNew(['stripe_payment_intent_id' => $object['payment_intent']]);
            $payment->fill([
                'billing_customer_id' => $invoice->billing_customer_id,
                'billing_invoice_id'  => $invoice->id,
                'status'              => 'succeeded',
                'amount_cents'        => $object['amount_paid'] ?? 0,
                'currency'            => $invoice->currency,
                'paid_at'             => now(),
            ])->save();
        }

        $this->markSubscriptionActiveFromInvoice($object['subscription'] ?? null);

        return $warnings;
    }

    /** @return list<string> */
    private function handleInvoicePaymentFailed(array $object): array
    {
        $invoiceId = $object['id'] ?? null;
        if (! $invoiceId) {
            return ['missing invoice id'];
        }

        $customer = $this->resolveCustomerByStripeId($object['customer'] ?? null);

        $invoice = BillingInvoice::firstOrNew(['stripe_invoice_id' => $invoiceId]);
        if ($customer !== null) {
            $invoice->billing_customer_id = $customer->id;
        } elseif (! $invoice->billing_customer_id) {
            return ['no linked billing customer'];
        }

        $invoice->fill([
            'status'           => 'open',
            'amount_due_cents' => $object['amount_due'] ?? $invoice->amount_due_cents ?? 0,
            'currency'         => strtoupper((string) ($object['currency'] ?? $invoice->currency ?? 'USD')),
        ])->save();

        if (! empty($object['payment_intent'])) {
            $payment = BillingPayment::firstOrNew(['stripe_payment_intent_id' => $object['payment_intent']]);
            $payment->fill([
                'billing_customer_id' => $invoice->billing_customer_id,
                'billing_invoice_id'  => $invoice->id,
                'status'              => 'failed',
                'amount_cents'        => $object['amount_due'] ?? 0,
                'currency'            => $invoice->currency,
            ])->save();
        }

        $subId = $object['subscription'] ?? null;
        if ($subId) {
            $sub = BillingSubscription::where('stripe_subscription_id', $subId)->first();
            if ($sub !== null) {
                $sub->update(['status' => 'past_due']);
                foreach ($sub->serviceEntitlements as $entitlement) {
                    $this->entitlements->markPastDue($entitlement->fresh(), 'invoice payment failed');
                }
            }
        }

        return [];
    }

    /** @return list<string> */
    private function handlePaymentMethodAttached(array $object): array
    {
        $pmId = $object['id'] ?? null;
        if (! $pmId) {
            return ['missing payment method id'];
        }

        $customer = $this->resolveCustomerByStripeId($object['customer'] ?? null);
        if ($customer === null) {
            return ['no linked customer for payment method'];
        }

        $card = (array) ($object['card'] ?? []);

        // Stores only safe display data — never PAN/CVC.
        BillingPaymentMethod::firstOrNew(['stripe_payment_method_id' => $pmId])->fill([
            'billing_customer_id' => $customer->id,
            'type'                => $object['type'] ?? 'card',
            'brand'               => $card['brand'] ?? null,
            'last4'               => $card['last4'] ?? null,
            'exp_month'           => $card['exp_month'] ?? null,
            'exp_year'            => $card['exp_year'] ?? null,
        ])->save();

        return [];
    }

    // -------------------------------------------------------------------------
    // Helpers

    private function resolveCustomerByStripeId(?string $stripeCustomerId): ?BillingCustomer
    {
        if (! $stripeCustomerId) {
            return null;
        }

        return BillingCustomer::firstOrCreate(['stripe_customer_id' => $stripeCustomerId], ['status' => 'active']);
    }

    /**
     * Update entitlement lifecycle from a subscription's Stripe status, and
     * create an approval-gated provisioning request once it is active.
     */
    private function syncEntitlementForSubscription(BillingSubscription $subscription, string $stripeStatus, array &$warnings): void
    {
        $entitlement = $subscription->serviceEntitlements()->first();
        if ($entitlement === null) {
            $entitlement = $this->entitlements->createFromSubscription($subscription)->entitlement;
        }
        if ($entitlement === null) {
            $warnings[] = 'could not resolve entitlement';

            return;
        }

        if (in_array($stripeStatus, ['active', 'trialing'], true)) {
            $this->ensureEntitlementActiveAndProvisioning($entitlement->fresh());
        } elseif (in_array($stripeStatus, ['past_due', 'unpaid'], true)) {
            $this->entitlements->markPastDue($entitlement->fresh(), "subscription {$stripeStatus}");
        } elseif (in_array($stripeStatus, ['canceled', 'incomplete_expired'], true)) {
            $this->entitlements->cancel($entitlement->fresh(), "subscription {$stripeStatus}");
        }
    }

    private function markSubscriptionActiveFromInvoice(?string $subId): void
    {
        if (! $subId) {
            return;
        }

        $sub = BillingSubscription::where('stripe_subscription_id', $subId)->first();
        if ($sub === null) {
            return;
        }

        if (! in_array($sub->status, ['active', 'trialing'], true)) {
            $sub->update(['status' => 'active']);
        }

        $entitlement = $sub->serviceEntitlements()->first()
            ?? $this->entitlements->createFromSubscription($sub->fresh())->entitlement;

        if ($entitlement !== null) {
            $this->ensureEntitlementActiveAndProvisioning($entitlement->fresh());
        }
    }

    /**
     * Make an entitlement live and ensure exactly one open, approval-gated
     * provisioning request exists. Idempotent across repeated events.
     */
    private function ensureEntitlementActiveAndProvisioning(BillingServiceEntitlement $entitlement): void
    {
        // Don't churn an entitlement already active or mid-provisioning.
        if (! in_array($entitlement->status, [
            BillingServiceEntitlement::STATUS_ACTIVE,
            BillingServiceEntitlement::STATUS_PROVISIONING_PENDING,
            BillingServiceEntitlement::STATUS_PROVISIONING_FAILED,
        ], true)) {
            $this->entitlements->activate($entitlement, 'subscription active');
            $entitlement = $entitlement->fresh();
        }

        $hasOpenRequest = $entitlement->provisioningRequests()
            ->open()
            ->where('requested_action', ProvisioningRequest::ACTION_PROVISION)
            ->exists();

        if (! $hasOpenRequest && $entitlement->canProvision()) {
            // Approval-gated by default — never executes infrastructure.
            $this->provisioning->createFromEntitlement($entitlement, ProvisioningRequest::ACTION_PROVISION);
        }
    }

    private function ts(mixed $timestamp): ?Carbon
    {
        return (is_int($timestamp) || (is_string($timestamp) && ctype_digit($timestamp)))
            ? Carbon::createFromTimestamp((int) $timestamp)
            : null;
    }
}
