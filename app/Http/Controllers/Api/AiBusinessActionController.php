<?php

namespace App\Http\Controllers\Api;

use App\Services\AiBusinessActions;
use Illuminate\Http\Request;

class AiBusinessActionController extends ApiController
{
    public function index(Request $request, string $conversation, AiBusinessActions $actions): array
    {
        $data = $request->validate(['offset' => 'sometimes|integer|min:0|max:10000']);

        return $actions->listing($request->user('web'), $conversation, $data['offset'] ?? 0);
    }

    public function show(Request $request, string $conversation, string $action, AiBusinessActions $actions): array
    {
        $data = $request->validate(['offset' => 'sometimes|integer|min:0|max:5000', 'limit' => 'sometimes|integer|min:1|max:100']);

        return $actions->get($request->user('web'), $conversation, $action, $data['offset'] ?? 0, $data['limit'] ?? 20);
    }

    public function confirm(Request $request, string $conversation, string $action, AiBusinessActions $actions): array
    {
        $data = $request->validate(['preview_hash' => 'required|string|size:64', 'reviewed_count' => 'required|integer|min:1|max:5000', 'admin_pin' => 'sometimes|nullable|string|max:64', 'verified_payment' => 'sometimes|boolean', 'verified_goods_return' => 'sometimes|boolean']);
        if (! app()->runningUnitTests()) {
            // An approved CSV may span many internal checkpoints; finish its atomic transaction
            // even if the browser disconnects, then let a retry retrieve the saved result.
            @set_time_limit(180);
            ignore_user_abort(true);
        }

        return $actions->confirm($request->user('web'), $conversation, $action, $data['preview_hash'], $data['reviewed_count'], array_intersect_key($data, array_flip(['admin_pin', 'verified_payment', 'verified_goods_return'])));
    }

    public function cancel(Request $request, string $conversation, string $action, AiBusinessActions $actions): array
    {
        return $actions->cancel($request->user('web'), $conversation, $action);
    }

    public function revise(Request $request, string $conversation, string $action, AiBusinessActions $actions): array
    {
        $data = $request->validate(['preview_hash' => 'required|string|size:64', 'values' => 'required|array|min:1|max:40']);

        return $actions->revise($request->user('web'), $conversation, $action, $data['preview_hash'], $data['values']);
    }
}
