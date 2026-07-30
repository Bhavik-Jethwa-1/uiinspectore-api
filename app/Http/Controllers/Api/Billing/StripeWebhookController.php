<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Billing\SubscriptionManager;
use App\Services\Billing\BillingServiceLocator;
use App\Services\Stripe\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles all Stripe webhook events for subscription lifecycle.
 * Routes events to appropriate handlers based on event type.
 */
class StripeWebhookController extends Controller
{
    private StripeService $stripe;
    private SubscriptionManager $subManager;

    public function __construct(
        StripeService        $stripe,
        SubscriptionManager  $subManager,
    ) {
        $this->stripe     = $stripe;
        $this->subManager = $subManager;
    }

    /**
     * POST /api/billing/stripe/webhook
     * Main Stripe webhook endpoint — verifies signature and dispatches events.
     */
    public function handle(Request $request): \Illuminate\Http\JsonResponse
    {
        $payload   = $request->getContent();
        $signature = $request->header('Stripe-Signature');

        Log::info('StripeWebhook: Received event', [
            'signature_present' => !empty($signature),
        ]);

        // Verify webhook signature
        $event = $this->stripe->constructWebhookEvent($payload, $signature);
        if (!$event) {
            // Dev mode: decode without verification
            $event = json_decode($payload);
            if (!$event) {
                return response()->json(['error' => 'Invalid payload'], 400);
            }
        }

        $eventType = $event->type ?? 'unknown';
        $eventData = $event->data->object ?? null;

        Log::info("StripeWebhook: Processing event", [
            'type' => $eventType,
            'id'   => $event->id ?? 'unknown',
        ]);

        try {
            $handled = $this->dispatch($eventType, $eventData);
            if (!$handled) {
                Log::info("StripeWebhook: Unhandled event type", ['type' => $eventType]);
            }
        } catch (\Throwable $e) {
            Log::error("StripeWebhook: Handler error", [
                'type'  => $eventType,
                'error' => $e->getMessage(),
            ]);
            // Return 200 to prevent Stripe from retrying non-fatal errors
        }

        return response()->json(['received' => true]);
    }

    // ─── Event Dispatcher ──────────────────────────────────────────────────────

    private function dispatch(string $eventType, ?object $data): bool
    {
        if (!$data) return false;

        return match ($eventType) {
            // ── Checkout ──────────────────────────────────────────────────────
            'checkout.session.completed' => $this->handleCheckoutCompleted($data),

            // ── Subscription lifecycle ───────────────────────────────────────
            'customer.subscription.created' => $this->handleSubscriptionCreated($data),
            'customer.subscription.updated'  => $this->handleSubscriptionUpdated($data),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($data),

            // ── Payment lifecycle ────────────────────────────────────────────
            'invoice.paid'                  => $this->handleInvoicePaid($data),
            'invoice.payment_failed'         => $this->handleInvoicePaymentFailed($data),
            'invoice.created'                => $this->handleInvoiceCreated($data),

            // ── Customer ────────────────────────────────────────────────────
            'customer.updated'  => $this->handleCustomerUpdated($data),

            default => false,
        };
    }

    // ─── Checkout ─────────────────────────────────────────────────────────────

    private function handleCheckoutCompleted(object $session): bool
    {
        // This is handled by the frontend redirect → verifyCheckout flow.
        // Stripe webhooks for checkout are supplementary.
        Log::info('StripeWebhook: checkout.session.completed', [
            'session_id' => $session->id ?? null,
            'customer'   => $session->customer ?? null,
        ]);
        return true;
    }

    // ─── Subscription Lifecycle ───────────────────────────────────────────────

    private function handleSubscriptionCreated(object $stripeSub): bool
    {
        $customerId = $stripeSub->customer ?? null;
        if (!$customerId) return false;

        $user = User::where('stripe_customer_id', $customerId)->first();
        if (!$user) {
            Log::warning('StripeWebhook: subscription.created — no user found', [
                'customer_id' => $customerId,
            ]);
            return true; // Marked as handled (unmatched user is not an error)
        }

        // Update provider subscription ID if not set
        $localSub = BillingServiceLocator::billing()->getSubscription($user);
        if ($localSub && empty($localSub->provider_subscription_id)) {
            $localSub->update([
                'provider_subscription_id' => $stripeSub->id,
                'status'                  => $this->mapStatus($stripeSub->status ?? 'active'),
            ]);
            Log::info('StripeWebhook: Linked Stripe subscription to user', [
                'user_id'         => $user->id,
                'stripe_sub_id'   => $stripeSub->id,
            ]);
        }

        return true;
    }

    private function handleSubscriptionUpdated(object $stripeSub): bool
    {
        $customerId = $stripeSub->customer ?? null;
        if (!$customerId) return false;

        $user = User::where('stripe_customer_id', $customerId)->first();
        if (!$user) return true;

        $this->subManager->syncFromStripeEvent($stripeSub);
        return true;
    }

    private function handleSubscriptionDeleted(object $stripeSub): bool
    {
        $customerId = $stripeSub->customer ?? null;
        if (!$customerId) return false;

        $user = User::where('stripe_customer_id', $customerId)->first();
        if (!$user) return true;

        $this->subManager->handleDeleted($user);
        return true;
    }

    // ─── Invoice / Payment ────────────────────────────────────────────────────

    private function handleInvoicePaid(object $invoice): bool
    {
        // Renewed or one-time paid — extend subscription period
        $customerId = $invoice->customer ?? null;
        if (!$customerId) return false;

        $user = User::where('stripe_customer_id', $customerId)->first();
        if (!$user) return true;

        $stripeSubId = $invoice->subscription ?? null;
        if (!$stripeSubId) return true;

        // Refresh subscription period from Stripe
        try {
            $stripeSub = $this->stripe->getSubscription($stripeSubId);
            $this->subManager->syncFromStripeEvent($stripeSub);
        } catch (\Exception $e) {
            Log::warning('StripeWebhook: Could not refresh subscription on invoice.paid', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);
        }

        // Record payment
        $this->recordPayment($invoice);

        return true;
    }

    private function handleInvoicePaymentFailed(object $invoice): bool
    {
        $customerId = $invoice->customer ?? null;
        if (!$customerId) return false;

        $user = User::where('stripe_customer_id', $customerId)->first();
        if (!$user) return true;

        $localSub = BillingServiceLocator::billing()->getSubscription($user);
        if ($localSub) {
            $localSub->update(['status' => 'past_due']);

            \App\Models\Billing\SubscriptionHistory::create([
                'user_id'         => $user->id,
                'subscription_id' => $localSub->id,
                'action'          => 'payment_failed',
                'metadata'        => [
                    'invoice_id' => $invoice->id,
                    'amount_due' => ($invoice->amount_due ?? 0) / 100,
                ],
            ]);
        }

        Log::warning('StripeWebhook: invoice.payment_failed', [
            'user_id'    => $user->id,
            'invoice_id' => $invoice->id,
        ]);

        return true;
    }

    private function handleInvoiceCreated(object $invoice): bool
    {
        // Record invoice locally
        $this->recordPayment($invoice);
        return true;
    }

    // ─── Customer ──────────────────────────────────────────────────────────────

    private function handleCustomerUpdated(object $customer): bool
    {
        $user = User::where('stripe_customer_id', $customer->id ?? null)->first();
        if (!$user) return true;

        // Sync email if changed
        if (!empty($customer->email) && $user->email !== $customer->email) {
            $user->update(['email' => $customer->email]);
        }

        return true;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function mapStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'active', 'trialing'     => 'active',
            'past_due', 'unpaid'    => 'past_due',
            'canceled', 'cancelled' => 'cancelled',
            'incomplete'            => 'incomplete',
            default                 => $stripeStatus,
        };
    }

    private function recordPayment(object $invoice): void
    {
        $customerId = $invoice->customer ?? null;
        $user = $customerId ? User::where('stripe_customer_id', $customerId)->first() : null;
        if (!$user) return;

        $amount = ($invoice->amount_paid ?? $invoice->total ?? 0) / 100;
        $currency = strtoupper($invoice->currency ?? 'USD');

        try {
            \App\Models\Billing\Invoice::updateOrCreate(
                ['provider_invoice_id' => $invoice->id],
                [
                    'user_id'              => $user->id,
                    'provider'             => 'stripe',
                    'provider_invoice_id'  => $invoice->id,
                    'status'               => $invoice->status ?? 'draft',
                    'subtotal'             => $amount,
                    'tax'                  => ($invoice->tax ?? 0) / 100,
                    'total'                => $amount,
                    'currency'             => $currency,
                    'description'          => 'Subscription invoice',
                    'period_start'         => isset($invoice->period_start)
                        ? \Carbon\Carbon::createFromTimestamp($invoice->period_start)
                        : null,
                    'period_end'           => isset($invoice->period_end)
                        ? \Carbon\Carbon::createFromTimestamp($invoice->period_end)
                        : null,
                    'invoice_url'          => $invoice->hosted_invoice_url ?? null,
                    'pdf_url'              => $invoice->invoice_pdf ?? null,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('StripeWebhook: Could not record invoice', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
