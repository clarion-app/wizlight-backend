<?php

namespace ClarionApp\WizlightBackend\Jobs;

use ClarionApp\WizlightBackend\Models\Bulb;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Bus;

class BulbStatusCheck
{
    public function handle(): void
    {
        $lock = Cache::lock('bulb-status-check', 30);

        $acquired = $lock->block(5);
        if (!$acquired) {
            return;
        }

        try {
            $bulbs = Bulb::all();

            // FR-006: dispatch all per-bulb jobs in parallel so the 2-second UDP
            // timeout applies per-light, not to the total duration.
            foreach ($bulbs as $bulb) {
                Bus::dispatch(new CheckBulbStatus((string) $bulb->id));
            }
        } finally {
            $lock->release();
        }
    }
}
