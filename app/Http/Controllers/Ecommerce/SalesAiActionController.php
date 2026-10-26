<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\SalesAi\CustomerActions;
use App\Services\SalesAi\ActionFeedback;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SalesAiActionController extends Controller
{
    public function show(Request $r, string $uuid, string $id, CustomerActions $actions): array
    {
        return $actions->show($actions->customerThread($r, $uuid), $id);
    }
    public function review(Request $r, string $uuid, string $id, CustomerActions $actions): array
    {
        $input = $r->except('image');
        if (isset($input['values']) && is_string($input['values'])) $input['values'] = json_decode($input['values'], true);
        validator($input, ['values' => 'sometimes|array'])->validate();
        return $this->perform($r, $uuid, $id, $actions, 'review', fn ($thread) => $actions->review($thread, $id, $input, $r->file('image')));
    }
    public function confirm(Request $r, string $uuid, string $id, CustomerActions $actions): array
    {
        return $this->perform($r, $uuid, $id, $actions, 'confirm', fn ($thread) => $actions->confirm($thread, $id, $r->all()));
    }
    public function cancel(Request $r, string $uuid, string $id, CustomerActions $actions): array
    {
        return $actions->cancel($actions->customerThread($r, $uuid), $id);
    }
    public function image(Request $r, string $uuid, string $id, CustomerActions $actions)
    {
        return $actions->imageResponse($actions->customerThread($r, $uuid), $id);
    }

    private function perform(Request $r, string $uuid, string $id, CustomerActions $actions, string $operation, \Closure $callback): array
    {
        $thread = $actions->customerThread($r, $uuid);
        try { return $callback($thread); }
        catch (ValidationException|HttpExceptionInterface $e) {
            $status = $e instanceof ValidationException ? 422 : $e->getStatusCode();
            if (in_array($status, [409, 422], true)) {
                // Only a genuine current review can produce failure feedback. Forged/stale
                // previews and unauthorized reads never trigger paid provider work.
                try {
                    $current = $actions->show($thread, $id);
                    if ($current['status'] === 'pending' && (int) $r->input('revision') === $current['revision'] && is_string($r->input('preview_hash')) && hash_equals($current['preview_hash'], $r->input('preview_hash'))) {
                        app(ActionFeedback::class)->record($thread, $id, $operation, ['message' => mb_substr($e->getMessage(), 0, 600), 'fields' => $e instanceof ValidationException ? $e->errors() : []]);
                    }
                } catch (\Throwable) { \Illuminate\Support\Facades\Log::warning('Sales AI form failure feedback unavailable'); }
            }
            throw $e;
        }
    }
}
