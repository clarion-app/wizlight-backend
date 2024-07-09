<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use ClarionApp\WizlightBackend\Controllers\BulbController;
use ClarionApp\WizlightBackend\Controllers\RoomController;

Route::group(['middleware'=>['api'], 'prefix'=>'api/clarion-app/wizlight' ], function () {
    Route::resource('bulb', BulbController::class);
    Route::resource('room', RoomController::class);
});