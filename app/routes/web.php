<?php

use Illuminate\Support\Facades\Route;

// La interfaz (React, compilada en public/ui) se sirve desde la raíz; la API vive bajo /api.
Route::get('/', fn () => response()->file(public_path('ui/index.html')));
