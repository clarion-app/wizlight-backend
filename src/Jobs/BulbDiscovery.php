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
use ClarionApp\WizlightBackend\Capability\CapabilityClassifier;
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
            $modelConfig = $bulb['model_config'] ?? [];
            $userConfig = $bulb['user_config'] ?? [];

            // Derive warmth range: model_config.cctRange > user_config.extRange > [null, null].
            [$warmthMin, $warmthMax] = $this->extractWarmthRange($modelConfig, $userConfig);

            // Classify capability from moduleName + warmth range.
            $classifier = new CapabilityClassifier();
            $capabilityClass = $classifier->classify(
                $sysConfig['moduleName'] ?? null,
                $warmthMin,
                $warmthMax
            );

            // Derived capability columns.
            $firmwareVersion = $sysConfig['fwVersion'] ?? '';
            $wizRoomId = $sysConfig['roomId'] ?? null;
            $wizGroupId = $sysConfig['groupId'] ?? null;

            if ($existing) {
                // FR-009, first-discovery-wins: a node may claim a device only
                // when the claim is unowned or has lapsed. A non-owning node
                // writes no ownership column — and no IP or state either; the
                // owner's view of the device is authoritative while it stands.
                if ($existing->local_node_id !== $local_node_id) {
                    if (!$this->isOwnerLapsed($existing)) {
                        continue;
                    }
                }

                $reported = ['ip' => $bulb['ip']];

                if (!empty($pilotState)) {
                    $reported += array_filter([
                        'state' => isset($pilotState['state']) ? (bool) $pilotState['state'] : null,
                        'dimming' => $pilotState['dimming'] ?? null,
                        'red' => $pilotState['r'] ?? null,
                        'green' => $pilotState['g'] ?? null,
                        'blue' => $pilotState['b'] ?? null,
                        'signal' => $pilotState['rssi'] ?? null,
                    ], fn ($value) => $value !== null);
                }
                if (!empty($sysConfig) && isset($sysConfig['moduleName'])) {
                    $reported['model'] = $sysConfig['moduleName'];
                }

                // Capability columns — always compared and written if different.
                $reported += [
                    'firmware_version' => $firmwareVersion,
                    'capability_class' => $capabilityClass,
                    'warmth_min_kelvin' => $warmthMin,
                    'warmth_max_kelvin' => $warmthMax,
                    'wiz_room_id' => $wizRoomId,
                    'wiz_group_id' => $wizGroupId,
                ];

                // Compare loosely and assign only what actually differs. Writing
                // the whole set unconditionally makes an unchanged bulb dirty
                // every cycle (SQLite/MySQL hand back `1` where the device
                // reports `true`), which would be a bridged on-chain write per
                // light per minute.
                $changed = false;
                foreach ($reported as $column => $value) {
                    if ($column === 'state') {
                        if ((bool) $existing->state !== $value) {
                            $existing->state = $value;
                            $changed = true;
                        }
                        continue;
                    }
                    if ($existing->{$column} != $value) {
                        $existing->{$column} = $value;
                        $changed = true;
                    }
                }

                if ($existing->local_node_id !== $local_node_id) {
                    // Reclaim: owner and liveness timestamp in the same write.
                    $existing->local_node_id = $local_node_id;
                    $existing->local_node_seen_at = now();
                    $changed = true;
                } elseif ($this->shouldRefreshClaim($existing)) {
                    $existing->local_node_seen_at = now();
                    $changed = true;
                }

                if ($changed) {
                    $existing->save();
                }
                $b = $existing;
            } else {
                $b = new Bulb();
                $b->id = (string) \Illuminate\Support\Str::uuid();
                $b->local_node_id = $local_node_id;
                $b->local_node_seen_at = now();
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
                $b->firmware_version = $firmwareVersion;
                $b->capability_class = $capabilityClass;
                $b->warmth_min_kelvin = $warmthMin;
                $b->warmth_max_kelvin = $warmthMax;
                $b->wiz_room_id = $wizRoomId;
                $b->wiz_group_id = $wizGroupId;
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

    /**
     * FR-009: a claim lapses when the *owning* node has not contacted the
     * device within the lapse window.
     *
     * Measured against `local_node_seen_at`, never `updated_at` — the latter is
     * refreshed by any node's write and by every status check, so it would let
     * a decommissioned node's bulb look owned forever as long as some other
     * node kept touching the row. A NULL timestamp is a claim from before this
     * column existed, and is treated as lapsed.
     */
    private function isOwnerLapsed(Bulb $bulb): bool
    {
        if ($bulb->local_node_seen_at === null) {
            return true;
        }

        $lapseHours = (int) config('wizlight.ownership.lapse_hours', 24);

        return $bulb->local_node_seen_at->lessThanOrEqualTo(now()->subHours($lapseHours));
    }

    /**
     * The owner renews its own claim periodically rather than on every cycle.
     *
     * FR-009 wants the timestamp advancing while the owner is alive; SC-008b
     * wants an unchanged bulb to cost zero writes, and every write here is
     * replicated on-chain. Renewing hourly satisfies both — a healthy owner
     * still renews 24 times inside the default 24-hour lapse window.
     */
    private function shouldRefreshClaim(Bulb $bulb): bool
    {
        if ($bulb->local_node_seen_at === null) {
            return true;
        }

        $heartbeatMinutes = (int) config('wizlight.ownership.heartbeat_minutes', 60);

        return $bulb->local_node_seen_at->lessThanOrEqualTo(now()->subMinutes($heartbeatMinutes));
    }

    /**
     * Extract warmth [min, max] from model_config.cctRange or user_config.extRange.
     *
     * Priority: model_config.cctRange (4-element: indices 1,2; 2-element: indices 0,1)
     *          > user_config.extRange (2-element: indices 0,1)
     *          > [null, null].
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function extractWarmthRange(array $modelConfig, array $userConfig): array
    {
        // Try model_config.cctRange first.
        $cctRange = $modelConfig['cctRange'] ?? null;
        if (is_array($cctRange)) {
            $len = count($cctRange);
            if ($len === 4) {
                // 4-element: indices 1, 2 are enforced min/max.
                return [(int) ($cctRange[1] ?? null), (int) ($cctRange[2] ?? null)];
            }
            if ($len === 2) {
                // 2-element: direct min/max.
                return [(int) $cctRange[0], (int) $cctRange[1]];
            }
        }

        // Fallback: user_config.extRange.
        $extRange = $userConfig['extRange'] ?? null;
        if (is_array($extRange) && count($extRange) === 2) {
            return [(int) $extRange[0], (int) $extRange[1]];
        }

        return [null, null];
    }
}
