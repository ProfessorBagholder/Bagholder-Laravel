<?php

namespace App\Http\Controllers;

use App\Wealthsimple\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderQuoteController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $r = OrderService::quote(
            (string) $request->query('symbol', ''),
            (string) $request->query('security', ''),
            (string) $request->query('account', ''),
            (string) $request->query('exchange', ''),
        );

        return response()->json($r);
    }
}
