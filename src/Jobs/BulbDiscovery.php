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
use ClarionApp\WizlightBackend\Models\BulbLastSeen;

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
        foreach($bulbs as $bulb) {
            $b = Bulb::where('mac', $bulb['mac'])->first();
            if(!$b)
            {
                $bulb['local_node_id'] = $local_node_id;
                $b = Bulb::create($bulb);
            }
            else
            {
                $b->update($bulb);
            }

            $last_seen = BulbLastSeen::where('bulb_id', $b->id)->first();
            if(!$last_seen)
            {
                $last_seen = BulbLastSeen::create([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'bulb_id' => $b->id,
                    'last_seen_at' => now(),
                ]);
            }
            else
            {
                $last_seen->update([
                    'last_seen_at' => now(),
                ]);
            }
        }
    }
}
