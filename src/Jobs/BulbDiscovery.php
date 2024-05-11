<?php

namespace ClarionApp\WizlightBackend\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ClarionApp\WizlightBackend\Wiz;
use ClarionApp\WizlightBackend\Models\Bulb;

class BulbDiscovery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $local_node_id = config('clarion.node_id');

        $wiz = new Wiz();
        $bulbs = $wiz->discover();
        foreach ($bulbs as $bulb) {
            $bulb['local_node_id'] = $local_node_id;
            $b = Bulb::where('mac', $bulb['mac'])->first() ?? Bulb::create($bulb);
            $b->last_seen = now();
            $b->save();
        }
    }
}
