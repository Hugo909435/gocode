<?php

use Illuminate\Support\Facades\Route;

// Point d'entrée SPA — toutes les routes non-API renvoient le shell HTML Vue
Route::get('/{any?}', fn () => view('app'))->where('any', '^(?!api).*');
