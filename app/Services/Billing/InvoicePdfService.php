<?php

namespace App\Services\Billing;

use App\Models\BillingInvoice;

/**
 * Generates a simple invoice PDF from local billing data.
 * Uses no external dependencies — renders HTML to PDF via Laravel's built-in
 * DomPDF integration (barryvdh/laravel-dompdf or similar).
 * Falls back to a plain-text download if PDF rendering is unavailable.
 */
class InvoicePdfService
{
    /**
     * Generate invoice HTML suitable for PDF rendering or direct display.
     */
    public function renderHtml(BillingInvoice $invoice): string
    {
        $customer = $invoice->customer;
        $payments = $invoice->payments;

        return view('pdf.invoice', [
            'invoice'  => $invoice,
            'customer' => $customer,
            'payments' => $payments,
            'appName'  => config('app.name', 'GlassPortal'),
        ])->render();
    }

    /**
     * Return PDF binary content. Uses DomPDF if available, otherwise returns
     * the HTML wrapped for print.
     */
    public function generatePdf(BillingInvoice $invoice): string
    {
        $html = $this->renderHtml($invoice);

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
                ->setPaper('a4')
                ->output();
        }

        // Fallback: return HTML (controller will serve as text/html for print).
        return $html;
    }

    public function filename(BillingInvoice $invoice): string
    {
        $ref = $invoice->stripe_invoice_id ?? ('INV-' . str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT));

        return "invoice-{$ref}.pdf";
    }
}
