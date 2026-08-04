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

            foreach ($bulbs as $bulb) {
                Bus::dispatchSync(new CheckBulbStatus((string) $bulb->id));
            }
        } finally {
            $lock->release();
        }
    }
}
