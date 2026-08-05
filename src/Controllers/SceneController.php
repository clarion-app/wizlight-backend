<?php

namespace ClarionApp\WizlightBackend\Controllers;

use ClarionApp\WizlightBackend\Scenes\SceneCatalogue;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SceneController extends Controller
{
    /**
     * Return the full scene catalogue as a read-only reference list.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $scenes = SceneCatalogue::all();

        return response()->json(array_map(function ($scene) {
            return [
                'id' => $scene->id,
                'name' => $scene->name,
                'animated' => $scene->animated,
                'classes' => $scene->classes,
            ];
        }, $scenes));
    }
}
