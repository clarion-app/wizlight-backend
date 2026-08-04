<?php

namespace ClarionApp\WizlightBackend\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ClarionApp\WizlightBackend\Wiz;
use ClarionApp\WizlightBackend\Transport\UdpTransport;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Models\BulbLastSeen;
use ClarionApp\WizlightBackend\Events\BulbStatusEvent;
use ClarionApp\WizlightBackend\Validation\IpValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BulbDiscovery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private ?UdpTransport $transport;

    public function __construct(?UdpTransport $transport = null)
    {
        $this->transport = $transport;
    }

    public function handle(): void
    {
        $lock = Cache::store(config('cache.default', 'array'))->lock('bulb-discovery', 30);
        if (!$lock->get()) {
            return;
        }

        try {
            $this->discoverBulbs();
        } finally {
            $lock->release();
        }
    }

    private function discoverBulbs(): void
    {
        $local_node_id = config('clarion.node_id');

        $wiz = new Wiz(
            transport: $this->transport ?? app(UdpTransport::class)
        );
        $bulbs = $wiz->discover();
        foreach ($bulbs as $bulb) {
            if (!IpValidator::isPrivateIp($bulb['ip'])) {
                Log::warning("Discarding discovery response with non-private IP: {$bulb['ip']}");
                continue;
            }

            $existing = Bulb::where('mac', $bulb['mac'])->first();
            $pilotState = $bulb['pilot_state'] ?? [];
            $sysConfig = $bulb['system_config'] ?? [];

            if ($existing) {
                if ($existing->local_node_id !== $local_node_id) {
                    $lapsed = $this->isOwnerLapsed($existing);
                    if (!$lapsed) {
                        continue;
                    }
                    $existing->local_node_id = $local_node_id;
                    $existing->save();
                }

                $updates = [
                    'ip' => $bulb['ip'],
                ];
                if (!empty($pilotState)) {
                    $updates['state'] = $pilotState['state'] ?? $existing->state;
                    $updates['dimming'] = $pilotState['dimming'] ?? $existing->dimming;
                    $updates['red'] = $pilotState['r'] ?? $existing->red;
                    $updates['green'] = $pilotState['g'] ?? $existing->green;
                    $updates['blue'] = $pilotState['b'] ?? $existing->blue;
                    $updates['signal'] = $pilotState['rssi'] ?? $existing->signal;
                }
                if (!empty($sysConfig) && isset($sysConfig['moduleName'])) {
                    $updates['model'] = $sysConfig['moduleName'];
                }
                $existing->update($updates);
                $b = $existing->fresh();
            } else {
                $b = new Bulb();
                $b->id = (string) \Illuminate\Support\Str::uuid();
                $b->local_node_id = $local_node_id;
                $b->mac = $bulb['mac'];
                $b->ip = $bulb['ip'];
                $b->name = 'Unnamed Bulb';
                $b->state = $pilotState['state'] ?? false;
                $b->dimming = $pilotState['dimming'] ?? 100;
                $b->red = $pilotState['r'] ?? 0;
                $b->green = $pilotState['g'] ?? 0;
                $b->blue = $pilotState['b'] ?? 0;
                $b->signal = $pilotState['rssi'] ?? 0;
                $b->model = $sysConfig['moduleName'] ?? null;
                $b->save();
            }

            $last_seen = BulbLastSeen::where('bulb_id', $b->id)->first();
            if (!$last_seen) {
                $last_seen = BulbLastSeen::create([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'bulb_id' => $b->id,
                    'last_seen_at' => now(),
                ]);
            } else {
                $last_seen->update([
                    'last_seen_at' => now(),
                ]);
            }

            event(new BulbStatusEvent($b));
        }
    }

    private function isOwnerLapsed(Bulb $bulb): bool
    {
        $lapseHours = (int) config('wizlight.ownership.lapse_hours', 24);
        $lapseThreshold = now()->subHours($lapseHours);
        return $bulb->updated_at <= $lapseThreshold;
    }
}
