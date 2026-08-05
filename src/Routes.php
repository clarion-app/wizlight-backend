<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use ClarionApp\WizlightBackend\Controllers\BulbController;
use ClarionApp\WizlightBackend\Controllers\RoomController;
use ClarionApp\WizlightBackend\Controllers\SceneController;

Route::group(['middleware'=>['auth:api'], 'prefix'=>$this->routePrefix ], function () {
    Route::resource('bulb', BulbController::class)->except(['store', 'show']);
    Route::resource('room', RoomController::class);
    Route::get('scene', [SceneController::class, 'index']);
});

Broadcast::channel('clarion-app-wizlights', function ($user) {
    return (bool) $user;
});
