<?php

use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Middleware\VerifyPixelforgeSignature;
use Illuminate\Support\Facades\Route;

/*
| Machine-to-machine routes. Loaded WITHOUT the "web" middleware group (see bootstrap/app.php):
| no session, no cookies and no CSRF token, which Pixelforge can't send anyway.
| Authenticity comes from the HMAC signature instead.
*/

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
    ->middleware(VerifyPixelforgeSignature::class)
    ->name('webhooks.pixelforge');
