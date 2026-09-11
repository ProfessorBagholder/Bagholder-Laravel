<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::dashboard')->name('dashboard');
Route::redirect('/connect', '/')->name('connect');

use App\Http\Controllers\OrderQuoteController;

Route::get('/api/order/quote', OrderQuoteController::class)->name('api.order.quote');
