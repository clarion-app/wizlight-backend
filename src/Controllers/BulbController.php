<?php

namespace ClarionApp\WizlightBackend\Controllers;

use ClarionApp\WizlightBackend\Models\Bulb;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use ClarionApp\WizlightBackend\Wiz;
use ClarionApp\WizlightBackend\RGBColor;


class BulbController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Bulb::with('last_seen')->get();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Bulb $bulb)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $bulb = Bulb::find($id);
        if(!$bulb) {
            return response()->json(['message' => 'Bulb not found'], 404);
        }

        $bulb->state = $request->state['state'] ? true : false;
        $bulb->save();

        $wiz = new Wiz();
        $color = new RGBColor($request->state['red'], $request->state['green'], $request->state['blue']);
        $wiz->set_pilot_state($bulb->ip, $color, $request->state['dimming'], $bulb->state ? 1 : 0);
        return $bulb;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Bulb $bulb)
    {
        //
    }
}
