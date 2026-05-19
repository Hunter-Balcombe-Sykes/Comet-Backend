<?php

namespace App\Services\Stripe;

use App\Models\Commerce\CommissionPayout;
use App\Models\Commerce\Order;
use App\Models\Core\Professional\Professional;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 6 — streaming CSV + XLSX exports for the Documents tab.
 *
 * Three sync export types remain (transactions moved to async pipeline in Phase 7):
 *   payouts               → local commerce.commission_payouts rows
 *   detailed-commissions  → one row per linked commerce.order (the tax artifact)
 *   eofy                  → same as detailed-commissions, filtered to AU FY (Jul 1 → Jun 30)
 *
 * CSV uses Laravel's streamDownload + fputcsv. XLSX uses openspout's streaming writer so
 * we don't load the whole dataset into memory.
 *
 * Cross-tenant safety: role scoping is enforced in the controller via the same skeleton
 * pattern used by /stripe/payouts and /stripe/transactions.
 */
class ExportService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function exportPayouts(Professional $pro, string $role, string $format, array $filters): StreamedResponse|BinaryFileResponse
    {
        $query = $this->scopedPayouts($pro, $role, $filters);

        $headers = [
            'date', 'status', 'orders_count', 'gross_cents', 'platform_fee_cents',
            'net_to_affiliate_cents', 'currency', 'brand', 'affiliate', 'stripe_pi_id',
        ];

        // Use lazy() (not cursor()) so the eager-load with([...]) clause on $query is honoured.
        // cursor() is a PDO row streamer and silently discards with(); lazy() chunks via get()
        // per page, preserves orderBy(), and runs the standard whereIn eager-load pass.
        $generator = function () use ($query) {
            foreach ($query->lazy() as $p) {
                yield [
                    $p->created_at?->toDateString() ?? '',
                    $p->status,
                    (int) $p->ledger_entry_count,
                    (int) $p->gross_commission_cents,
                    (int) $p->platform_fee_cents,
                    (int) $p->net_payout_cents,
                    $p->currency_code,
                    $p->brandProfessional?->display_name ?? '',
                    $p->affiliateProfessional?->display_name ?? '',
                    (string) ($p->payment_intent_id ?? ''),
                ];
            }
        };

        return $this->stream($this->filename('payouts', $format), $headers, $generator, $format);
    }

    /**
     * Detailed Commission Transactions — the bookkeeping artifact.
     * One row per linked commerce.orders, joined to its payout for status + Stripe IDs.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportDetailedCommissions(Professional $pro, string $role, string $format, array $filters): StreamedResponse|BinaryFileResponse
    {
        $payoutsQuery = $this->scopedPayouts($pro, $role, $filters);

        $headers = [
            'payout_id', 'payout_status', 'payout_date', 'order_id', 'shopify_order_id',
            'order_occurred_at', 'brand_handle', 'brand_name', 'affiliate_handle', 'affiliate_name',
            'gross_order_cents', 'commission_rate_pct', 'gross_commission_cents',
            'platform_fee_cents', 'net_to_affiliate_cents', 'gst_estimate_cents',
            'currency', 'stripe_pi_id', 'stripe_charge_id',
        ];

        $generator = function () use ($payoutsQuery) {
            // Subquery (not pluck('id')->all()) so the payout IDs never round-trip into
            // PHP memory — at EOFY a long-tenured brand can produce tens of thousands of
            // IDs. The inner SELECT runs on the DB side as part of the WHERE IN.
            // lazy() (not cursor()) — cursor() silently drops with(), turning each order row
            // into 3 extra SELECTs. lazy() chunks via get() so the eager-load pass runs once
            // per chunk and orderBy('occurred_at') is preserved.
            $orders = Order::query()
                ->with([
                    'payout:id,status,gross_commission_cents,platform_fee_cents,net_payout_cents,ledger_entry_count,payment_intent_id,charge_id,created_at',
                    'brandProfessional:id,handle,display_name',
                    'affiliateProfessional:id,handle,display_name',
                ])
                ->whereIn('payout_id', $payoutsQuery->select('id'))
                ->orderBy('occurred_at')
                ->lazy();

            foreach ($orders as $o) {
                $grossCommission = (int) ($o->commission_cents ?? 0);
                // Australian GST is 1/11 of the GST-inclusive price. Flag as estimate — brand decides whether
                // their commission is GST-applicable based on registration status (parked decision).
                $gstEstimate = (int) round($grossCommission / 11);
                // Per-order net = pro-rata share of the payout net based on this order's contribution
                // to the payout's gross commission. Avoids re-deriving the platform fee per row.
                $payoutGross = (int) ($o->payout?->gross_commission_cents ?? 0);
                $payoutNet = (int) ($o->payout?->net_payout_cents ?? 0);
                $perOrderNet = $payoutGross > 0
                    ? (int) round($grossCommission * $payoutNet / $payoutGross)
                    : $grossCommission;
                $perOrderFee = $grossCommission - $perOrderNet;

                yield [
                    (string) $o->payout_id,
                    (string) ($o->payout?->status ?? ''),
                    $o->payout?->created_at?->toDateString() ?? '',
                    (string) $o->id,
                    (string) $o->shopify_order_id,
                    $o->occurred_at?->toDateTimeString() ?? '',
                    (string) ($o->brandProfessional?->handle ?? ''),
                    (string) ($o->brandProfessional?->display_name ?? ''),
                    (string) ($o->affiliateProfessional?->handle ?? ''),
                    (string) ($o->affiliateProfessional?->display_name ?? ''),
                    (int) $o->gross_cents,
                    (float) $o->commission_rate,
                    $grossCommission,
                    $perOrderFee,
                    $perOrderNet,
                    $gstEstimate,
                    (string) $o->currency_code,
                    (string) ($o->payout?->payment_intent_id ?? ''),
                    (string) ($o->payout?->charge_id ?? ''),
                ];
            }
        };

        return $this->stream($this->filename('detailed-commissions', $format), $headers, $generator, $format);
    }

    /**
     * EOFY — Detailed Commission Transactions for the given AU financial year.
     * AU FY Y = Jul 1 (Y-1) → Jun 30 (Y). Defaults to the current FY when fy isn't passed.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportEofy(Professional $pro, string $role, string $format, array $filters): StreamedResponse|BinaryFileResponse
    {
        $fy = (int) ($filters['fy'] ?? $this->currentAuFy());
        $filters['date_from'] = ($fy - 1).'-07-01';
        $filters['date_to'] = $fy.'-06-30';

        return $this->exportDetailedCommissions($pro, $role, $format, $filters);
    }

    private function scopedPayouts(Professional $pro, string $role, array $filters)
    {
        $query = CommissionPayout::query()
            ->with([
                'brandProfessional:id,handle,display_name',
                'affiliateProfessional:id,handle,display_name',
            ])
            ->orderByDesc('created_at');

        if ($role === 'brand') {
            $query->where('brand_professional_id', $pro->id);
        } else {
            $query->where('affiliate_professional_id', $pro->id);
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'].' 23:59:59');
        }
        if (! empty($filters['status'])) {
            $query->whereIn('status', (array) $filters['status']);
        }

        return $query;
    }

    private function currentAuFy(): int
    {
        $now = now();

        return (int) ($now->month >= 7 ? $now->year + 1 : $now->year);
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function stream(string $filename, array $headers, \Closure $rowGenerator, string $format): StreamedResponse|BinaryFileResponse
    {
        if ($format === 'xlsx') {
            return $this->streamXlsx($filename, $headers, $rowGenerator);
        }

        return $this->streamCsv($filename, $headers, $rowGenerator);
    }

    private function streamCsv(string $filename, array $headers, \Closure $rowGenerator): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rowGenerator) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rowGenerator() as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function streamXlsx(string $filename, array $headers, \Closure $rowGenerator): BinaryFileResponse
    {
        // openspout writes to a file path; stream the file once written. For small-ish exports
        // this is fine; large exports should go through ExecuteExportJob → Supabase Storage.
        $tmp = tempnam(sys_get_temp_dir(), 'export_').'.xlsx';

        $writer = new XlsxWriter;
        $writer->openToFile($tmp);
        $writer->addRow(Row::fromValues($headers));
        foreach ($rowGenerator() as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        // response()->download() returns a BinaryFileResponse, which streams $tmp to the
        // client via PHP's output buffer in chunks rather than loading the entire XLSX
        // into a PHP string. deleteFileAfterSend(true) handles tempfile cleanup after the
        // response is flushed.
        return response()->download($tmp, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function filename(string $type, string $format): string
    {
        return sprintf('partna-%s-%s.%s', $type, now()->toDateString(), $format);
    }
}
