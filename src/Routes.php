<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use ClarionApp\WizlightBackend\Controllers\BulbController;
use ClarionApp\WizlightBackend\Controllers\RoomController;

Route::group(['middleware'=>['auth:api'], 'prefix'=>$this->routePrefix ], function () {
    Route::resource('bulb', BulbController::class)->except(['store', 'show']);
    Route::resource('room', RoomController::class);
});

Broadcast::channel('clarion-app-wizlights', function ($user) {
    return (bool) $user;
});
