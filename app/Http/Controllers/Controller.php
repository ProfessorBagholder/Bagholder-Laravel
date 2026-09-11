<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controllers\HasMiddleware;

abstract class Controller implements HasMiddleware
{
    /**
     * @return array<int, \Illuminate\Routing\Controllers\Middleware|\Closure|string>
     */
    public static function middleware(): array
    {
        return [];
    }
}
