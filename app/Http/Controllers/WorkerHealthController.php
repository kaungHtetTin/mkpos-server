<?php

namespace App\Http\Controllers;

use App\Services\WorkerHealth;
use Illuminate\Http\JsonResponse;

class WorkerHealthController extends Controller
{
    public function show(WorkerHealth $health): JsonResponse
    {
        return $this->respond($health->snapshot());
    }

    public function details(WorkerHealth $health): JsonResponse
    {
        return $this->respond($health->snapshot(true));
    }

    private function respond(array $result): JsonResponse
    {
        return response()->json($result, $result['ok'] ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
