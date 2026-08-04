<?php

namespace ClarionApp\WizlightBackend\Jobs;

use ClarionApp\WizlightBackend\Models\Bulb;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Bus;

/**
 * Sweep orchestrator. Runs on a queue worker (enqueued by $schedule->job(),
 * never dispatchSync — FR-006b), contacts no device itself, and queues one
 * CheckBulbStatus per bulb.
 */
class BulbStatusCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $lock = Cache::lock('bulb-status-check', 30);

        // FR-007: skip outright when a sweep is already in flight. Blocking
        // here would queue sweeps behind one another, which is the piling-up
        // this requirement exists to prevent.
        if (!$lock->get()) {
            return;
        }

        try {
            // FR-006: the per-bulb jobs are QUEUED, so other workers pick them
            // up and the 2-second timeout applies per light. Bus::dispatchSync()
            // here would run every check inline in this one process — N × 2s,
            // the linear behaviour this design exists to eliminate.
            foreach (Bulb::all() as $bulb) {
                Bus::dispatch(new CheckBulbStatus((string) $bulb->id));
            }
        } finally {
            $lock->release();
        }
    }
}
