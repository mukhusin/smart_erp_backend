<?php

use Illuminate\Support\Facades\Route;

/*
| The backend has no UI. The Angular app (smart-erp-frontend repo) uses /api/v1.
| Web routes are kept only for what needs the session/cookie stack (Sanctum's CSRF cookie route is added by Sanctum).
*/
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api'  => url('/api/v1'),
]));
