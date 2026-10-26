<?php

namespace App\Http\Controllers\Api;

use App\Services\AiAssistantEntitlement;
use App\Services\AiBillingRequests;
use Illuminate\Http\Request;

class AiBillingRequestController extends ApiController
{
    public function index(Request $request, string $conversation, AiBillingRequests $billing): array
    {
        return ['items' => $billing->listing($request->user('web'), $conversation)];
    }

    public function show(Request $request, string $conversation, string $draft, AiBillingRequests $billing): array
    {
        return $billing->get($request->user('web'), $conversation, $draft);
    }

    public function validateProof(Request $request, string $conversation, string $draft, AiBillingRequests $billing): array
    {
        app(AiAssistantEntitlement::class)->requireAccess($request->user('web'));
        $data = $request->validate(['revision' => 'required|integer|min:1', 'subscription_plan_id' => 'required|integer|min:1', 'payment_method_id' => 'required|integer|min:1',
            'image_message_id' => 'required|integer|min:1', 'message' => 'sometimes|nullable|string|max:1000']);
        if (! app()->runningUnitTests()) {
            @set_time_limit(180);
            ignore_user_abort(true);
        }

        return $billing->validate($request->user('web'), $conversation, $draft, $data);
    }

    public function submit(Request $request, string $conversation, string $draft, AiBillingRequests $billing): array
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'preview_hash' => 'required|string|size:64']);

        return $billing->submit($request->user('web'), $conversation, $draft, $data['revision'], $data['preview_hash']);
    }

    public function cancel(Request $request, string $conversation, string $draft, AiBillingRequests $billing): array
    {
        return $billing->cancel($request->user('web'), $conversation, $draft);
    }
}
