<?php

namespace App\Console\Commands;

use App\Services\Pilot\PilotReadinessItem;
use App\Services\Pilot\PilotReadinessService;
use Illuminate\Console\Command;

/**
 * Operator-readable pilot/product-test readiness report (Phase 29).
 *
 * Reuses {@see PilotReadinessService}. Exits 0 when no checks are blocked, 1 when
 * any blocked check exists. Warnings never fail the command. Never prints secret
 * values (the service only ever returns presence booleans / counts).
 */
class PilotReadiness extends Command
{
    protected $signature   = 'glassportal:pilot-readiness';
    protected $description  = 'Report whether GlassPortal is ready for a controlled pilot / product test';

    public function handle(PilotReadinessService $readiness): int
    {
        $this->line('');
        $this->line('  <fg=blue>GlassPortal Pilot Readiness</>');
        $this->line('  ' . now()->toIso8601String());
        $this->line('');

        $lastCategory = null;
        foreach ($readiness->items() as $item) {
            if ($item->category !== $lastCategory) {
                $this->line("  <fg=cyan>{$item->category}</>");
                $lastCategory = $item->category;
            }
            $this->renderItem($item);
        }

        $summary = $readiness->summary();
        $this->line('');
        $this->line(sprintf(
            '  <fg=green>%d ready</>  <fg=yellow>%d warning</>  <fg=red>%d blocked</>  (%d checks)',
            $summary['ready'], $summary['warning'], $summary['blocked'], $summary['total'],
        ));
        $this->line('');

        if ($readiness->hasBlocked()) {
            $this->line('  <fg=red>NOT pilot-ready — resolve the blocked checks above.</>');
            $this->line('');

            return self::FAILURE;
        }

        if ($summary['warning'] > 0) {
            $this->line('  <fg=yellow>Pilot-ready for a local dry run. Review warnings before a live (Stripe test-mode) run.</>');
        } else {
            $this->line('  <fg=green>Pilot-ready.</>');
        }
        $this->line('');

        return self::SUCCESS;
    }

    private function renderItem(PilotReadinessItem $item): void
    {
        [$glyphColor, $glyph] = match ($item->status) {
            PilotReadinessItem::READY   => ['green', '✓'],
            PilotReadinessItem::WARNING => ['yellow', '!'],
            PilotReadinessItem::BLOCKED => ['red', '✗'],
            default                     => ['gray', '?'],
        };

        $this->line("    <fg={$glyphColor}>{$glyph}</> <fg=white>{$item->key}</>  {$item->message}");

        if ($item->action !== '' && $item->status !== PilotReadinessItem::READY) {
            $this->line("      <fg=gray>→ {$item->action}</>");
        }
    }
}
