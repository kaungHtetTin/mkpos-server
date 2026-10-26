<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\ServerHealth;

class ServerHealthController extends Controller
{
    public function __invoke(ServerHealth $health)
    {
        // A diagnostic document, not a liveness endpoint. Component failures stay in JSON.
        return response()->json($health->snapshot())->header('Cache-Control', 'no-store, private');
    }
}
