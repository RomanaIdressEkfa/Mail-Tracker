<?php

use App\Http\Controllers\TrackController;
use Illuminate\Support\Facades\Route;

// Loaded without the "web" middleware group: no sessions or cookies are created for recipients.
Route::get('/o/{token}.gif', [TrackController::class, 'pixel'])
    ->where('token', '[A-Za-z0-9_-]{16,40}')
    ->name('track.pixel');

Route::get('/c/{token}/{position}', [TrackController::class, 'click'])
    ->where(['token' => '[A-Za-z0-9_-]{16,40}', 'position' => '[0-9]{1,3}'])
    ->name('track.click');
