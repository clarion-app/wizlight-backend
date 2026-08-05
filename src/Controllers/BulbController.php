<?php

namespace ClarionApp\WizlightBackend\Controllers;

use ClarionApp\WizlightBackend\Capability\DeviceCapabilityValidator;
use ClarionApp\WizlightBackend\Mode\ActiveMode;
use ClarionApp\WizlightBackend\Mode\AmbiguousModeException;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Scenes\SceneCatalogue;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use ClarionApp\WizlightBackend\Services\WizlightService;

class BulbController extends Controller
{
    public function __construct(
        protected WizlightService $service,
    ) {}

    /**
     * Display a list of all bulbs.
     * @response Bulb[]
     */
    public function index(Request $request)
    {
        return Bulb::with('last_seen')->get();
    }

    /**
     * Change the bulb state.
     * @param  \Illuminate\Http\Request  $request
     * @param string $id
     * @response Bulb
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'state' => 'nullable|boolean',
            'red' => 'nullable|integer|min:0|max:255',
            'green' => 'nullable|integer|min:0|max:255',
            'blue' => 'nullable|integer|min:0|max:255',
            'temperature' => 'nullable|integer|min:0|max:6500',
            'dimming' => 'nullable|integer|min:0|max:100',
            'name' => 'nullable|string',
            'room_id' => 'nullable|uuid|exists:wizlight_rooms,id',
            'active_mode' => 'nullable|string|in:rgb,warmth,white_channels,scene',
            'scene_id' => 'nullable|integer',
            'scene_speed' => 'nullable|integer|min:' . SceneCatalogue::SPEED_MIN . '|max:' . SceneCatalogue::SPEED_MAX,
            'white_warm' => 'nullable|integer|min:0|max:255',
            'white_cool' => 'nullable|integer|min:0|max:255',
            'head_ratio' => 'nullable|integer|min:0|max:100',
        ]);

        $bulb = Bulb::find($id);
        if (!$bulb) {
            return response()->json(['message' => 'Bulb not found'], 404);
        }

        // Mode decision: if no explicit active_mode, try to infer from field groups.
        // If ambiguous (two+ mode-owned groups changed), reject with 422.
        if (!array_key_exists('active_mode', $validated)) {
            try {
                $inferred = ActiveMode::infer(
                    $validated,
                    [
                        'red' => $bulb->red,
                        'green' => $bulb->green,
                        'blue' => $bulb->blue,
                        'temperature' => $bulb->temperature,
                        'white_warm' => $bulb->white_warm,
                        'white_cool' => $bulb->white_cool,
                        'scene_id' => $bulb->scene_id,
                    ]
                );
                // If inferred, add it to validated payload.
                if ($inferred !== null) {
                    $validated['active_mode'] = $inferred;
                }
            } catch (AmbiguousModeException $e) {
                // The exception already names the conflicting groups
                // (contracts/mode-and-scene-api.md's exact wording) — use it
                // rather than a generic message.
                throw ValidationException::withMessages([
                    'active_mode' => [$e->getMessage()],
                ]);
            }
        }

        // Validate against device capability (Phase 4, US2 + T021).
        (new DeviceCapabilityValidator())->validate($bulb, $validated);

        return $this->service->updateBulbState($bulb, $validated);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $bulb = Bulb::find($id);
        if(!$bulb) {
            return response()->json(['message' => 'Bulb not found'], 404);
        }
        // The last-seen cascade is a model-level `deleting` hook registered in
        // WizlightBackendServiceProvider (FR-011), so it fires here and on
        // every other deletion path.
        if($bulb->forceDelete()) {
            return response()->json(['message' => 'Bulb deleted successfully'], 200);
        } else {
            return response()->json(['message' => 'Failed to delete bulb'], 500);
        }
    }
}
