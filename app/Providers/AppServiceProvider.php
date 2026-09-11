<?php

namespace App\Providers;

use App\Journal\MarketImport;
use App\Models\Meta;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Lightweight fill when Meta quotes are empty (skip 100k bars on boot).
        try {
            $quotes = Meta::json('quotes', []);
            if ((! is_array($quotes) || $quotes === []) && is_file(MarketImport::defaultPath())) {
                MarketImport::import(null, false);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
