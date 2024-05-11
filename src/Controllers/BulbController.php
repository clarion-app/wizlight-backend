<?php

namespace ClarionApp\WizlightBackend\Controllers;

use ClarionApp\WizlightBackend\Models\Bulb;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class BulbController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Bulb::all();
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
    public function update(Request $request, Bulb $bulb)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Bulb $bulb)
    {
        //
    }
}
