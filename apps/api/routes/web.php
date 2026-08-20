<?php

use Illuminate\Support\Facades\Route;

// ServiceProof is API-first: the operations UI (Next.js) and the field app
// (Flutter) are separate clients. This route only advertises the API.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'tagline' => 'Prove the service. Trust the evidence.',
    'api' => url('/api/v1'),
    'health' => url('/up'),
]));
