<?php

namespace ClarionApp\WizlightBackend\Jobs;

use ClarionApp\WizlightBackend\Mode\ActiveMode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ClarionApp\WizlightBackend\Wiz;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Events\BulbStatusEvent;
use ClarionApp\WizlightBackend\Transport\UdpTransport;

/**
 * One bulb, one getPilot round trip, 2-second timeout, reconcile, done.
 *
 * Owns the reconciliation (FR-010/FR-010b/FR-015): Wiz returns the decoded
 * payload and this job decides what it means for the row. Every attribute is
 * compared, signal included and independently, and the light's report always
 * wins over the stored intended state.
 */
class CheckBulbStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $bulbId;
    private ?UdpTransport $transport;

    public function __construct(string $bulbId, ?UdpTransport $transport = null)
    {
        $this->bulbId = $bulbId;
        $this->transport = $transport;
    }

    public function handle(): ?array
    {
        $bulb = Bulb::find($this->bulbId);
        if (!$bulb) {
            return null;
        }

        $wiz = new Wiz(
            wait_time: 2.0,
            transport: $this->transport ?? app(UdpTransport::class)
        );

        $results = $wiz->get_pilot_state($bulb->ip);
        if (!$results) {
            return $results;
        }

        $payload = $this->payloadFor($results, $bulb->mac);
        if ($payload === null) {
            return $results;
        }

        if ($this->reconcile($bulb, $payload)) {
            event(new BulbStatusEvent($bulb->fresh()));
        }

        return $results;
    }

    /**
     * Pick this bulb's datagram out of the response set.
     */
    private function payloadFor(array $results, ?string $mac): ?array
    {
        foreach ($results as $result) {
            $payload = $result['result'] ?? null;
            if (!is_array($payload)) {
                continue;
            }
            if ($mac === null || !isset($payload['mac']) || $payload['mac'] === $mac) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * Compare every attribute; write once if any differs. Returns whether a
     * write happened, so an unchanged light costs zero writes — which matters
     * because every write here is a bridged on-chain write.
     */
    private function reconcile(Bulb $bulb, array $payload): bool
    {
        // When a scene is active, r/g/b in the payload represent the scene's
        // current animation frame — NOT the user's intended colour. Skip them.
        $sceneActive = isset($payload['sceneId']) && $payload['sceneId'] > 0;

        $reported = [
            'state' => isset($payload['state']) ? (bool) $payload['state'] : null,
            'dimming' => $payload['dimming'] ?? null,
            'temperature' => $payload['temperature'] ?? null,
            // FR-010: signal is an independent trigger, not a passenger on
            // some other attribute happening to change at the same moment.
            'signal' => $payload['rssi'] ?? null,
        ];

        // Only compare r/g/b when no scene is active. When a scene plays,
        // the r/g/b values are the scene's internal frame colour, not the
        // user-set colour stored in the model.
        if (!$sceneActive) {
            $reported['red'] = $payload['r'] ?? null;
            $reported['green'] = $payload['g'] ?? null;
            $reported['blue'] = $payload['b'] ?? null;
        }

        // White channels: read back w (warm) and c (cool), never defaulted
        // to zero — retention rule, same as r/g/b above.
        $reported['white_warm'] = $payload['w'] ?? null;
        $reported['white_cool'] = $payload['c'] ?? null;

        $changed = false;
        foreach ($reported as $column => $value) {
            if ($value === null) {
                continue;
            }
            if ($column === 'state') {
                if ((bool) $bulb->state !== $value) {
                    $bulb->state = $value;
                    $changed = true;
                }
                continue;
            }
            if ($bulb->{$column} != $value) {
                $bulb->{$column} = $value;
                $changed = true;
            }
        }

        // Write scene_id when it differs — scene changes are always meaningful.
        $sceneIdChanged = false;
        if (isset($payload['sceneId']) && $bulb->scene_id != $payload['sceneId']) {
            $bulb->scene_id = $payload['sceneId'];
            $changed = true;
            $sceneIdChanged = true;
        }

        // Write active_mode only when scene_id changed (mode transition) or
        // other fields already changed (piggy-back). Prevents "null → rgb"
        // inference from triggering a save on an otherwise-unchanged bulb.
        $pilotMode = ActiveMode::fromPilot($payload);
        if ($pilotMode !== null && $bulb->active_mode !== $pilotMode) {
            if ($sceneIdChanged || $changed) {
                $bulb->active_mode = $pilotMode;
                $changed = true;
            }
        }

        // Deliberately no `local_node_seen_at` write here. FR-009 restricts that
        // column to the owning node contacting the device; BulbDiscovery is
        // where the owner does that and where the flow in data-model.md places
        // the stamp. Advancing it here as well would make an unchanged light
        // cost a bridged on-chain write every minute — the exact thing the
        // no-change case below exists to prevent.
        if ($changed) {
            $bulb->save();
        }

        return $changed;
    }
}
