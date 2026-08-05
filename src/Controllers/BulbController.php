<?php

namespace ClarionApp\WizlightBackend\Controllers;

use ClarionApp\WizlightBackend\Capability\DeviceCapabilityValidator;
use ClarionApp\WizlightBackend\Models\Bulb;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
        ]);

        $bulb = Bulb::find($id);
        if(!$bulb) {
            return response()->json(['message' => 'Bulb not found'], 404);
        }

        // Validate against device capability (Phase 4, US2).
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
