<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use ClarionApp\WizlightBackend\Controllers\BulbController;

Route::group(['middleware'=>'api', 'prefix'=>'api' ], function () {
    Route::resource('wizlight-bulb', BulbController::class);
});