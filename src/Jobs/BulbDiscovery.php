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
use ClarionApp\WizlightBackend\Events\BulbStatusEvent;
use ClarionApp\WizlightBackend\Validation\IpValidator;
use Illuminate\Support\Facades\Log;

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
        //Log::info("Starting bulb discovery...");
        $local_node_id = config('clarion.node_id');

        $wiz = new Wiz();
        $bulbs = $wiz->discover();
        foreach($bulbs as $bulb) {
            if (!IpValidator::isPrivateIp($bulb['ip'])) {
                Log::warning("Discarding discovery response with non-private IP: {$bulb['ip']}");
                continue;
            }

            $b = Bulb::updateOrCreate(
                ['mac' => $bulb['mac']],
                [
                    'ip' => $bulb['ip'],
                    'local_node_id' => $local_node_id,
                    'name' => Bulb::where('mac', $bulb['mac'])->value('name') ?? 'Unnamed Bulb',
                ]
            );

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

            event(new BulbStatusEvent($b));
        }
    }
}
