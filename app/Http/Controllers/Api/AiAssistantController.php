<?php

namespace App\Http\Controllers\Api;

use App\Ai\Personas\WyneWyne;
use App\Http\Controllers\Controller;
use App\Jobs\WyneWyneReply;
use App\Services\AiAssistantEntitlement;
use App\Services\AiAssistantSettings;
use App\Services\AiBalanceBook;
use App\Services\AiBillingRequests;
use App\Services\AiBusinessActions;
use App\Services\AiChatImages;
use App\Services\AiConversationAccess;
use App\Services\AiWeeklyQuota;
use App\Services\McpProductImportValidator;
use App\Services\ProductImportService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AiAssistantController extends Controller
{
    private function actor(Request $request)
    {
        return $request->user('web');
    }

    private function scoped(Request $request)
    {
        return app(AiConversationAccess::class)->query($this->actor($request));
    }

    private function conversation(Request $request, string $id)
    {
        return app(AiConversationAccess::class)->find($this->actor($request), $id);
    }

    public function config(Request $request, AiAssistantSettings $settings): array
    {
        $value = $settings->get();

        return array_intersect_key($value, array_flip(['enabled', 'configured', 'storage_ready', 'weekly_limit'])) + ['subscription_access' => app(AiAssistantEntitlement::class)->forUser($this->actor($request)), 'weekly_usage' => Schema::hasTable('ai_runs') ? app(AiWeeklyQuota::class)->usage($this->actor($request)->id, $value['weekly_limit']) : null, 'name' => WyneWyne::NAME, 'name_my' => WyneWyne::MYANMAR_NAME, 'max_message_length' => 4000, 'max_csv_bytes' => 5242880, 'max_image_bytes' => 5242880, 'image_types' => ['image/jpeg', 'image/png', 'image/webp']];
    }

    private function available(): array
    {
        $settings = app(AiAssistantSettings::class)->get();
        abort_unless($settings['storage_ready'] && $settings['enabled'] && $settings['configured'], 503, 'MKPOS AI is not available yet. Please contact platform support.');

        abort_unless(Schema::hasColumn('ai_runs', 'message_id'), 503, 'Wyne Wyne worker setup is required. Please contact platform support.');

        return $settings;
    }

    public function index(Request $request): array
    {
        $data = $request->validate(['limit' => 'sometimes|integer|min:1|max:50', 'offset' => 'sometimes|integer|min:0']);
        $limit = $data['limit'] ?? 20;
        $offset = $data['offset'] ?? 0;
        $rows = $this->scoped($request)->orderByDesc('updated_at')->orderBy('id')->offset($offset)->limit($limit + 1)->get(['id', 'title', 'updated_at']);

        return ['items' => $rows->take($limit)->values(), 'has_more' => $rows->count() > $limit, 'next_offset' => $rows->count() > $limit ? $offset + $limit : null];
    }

    public function store(Request $request): array
    {
        app(AiAssistantEntitlement::class)->requireAccess($this->actor($request));
        $this->available();
        app(AiBalanceBook::class)->funded();
        $data = $request->validate(['id' => 'required|uuid']);
        $user = $this->actor($request);
        // Client-owned UUID makes a retried create idempotent, never changes ownership.
        DB::table('ai_conversations')->insertOrIgnore(['id' => $data['id'], 'user_id' => $user->id, 'business_id' => $user->business_id,
            ...app(AiConversationAccess::class)->creationAttributes($user),
            'title' => 'New conversation', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return ['conversation' => $this->conversation($request, $data['id'])];
    }

    private function serialize($row): array
    {
        $data = (array) $row;
        unset($data['request_hash']);
        foreach (['created_at', 'updated_at'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = Carbon::parse($data[$key], 'UTC')->toISOString();
            }
        }
        foreach (['attachment', 'tools'] as $key) {
            $data[$key] = isset($data[$key]) ? json_decode($data[$key], true) : null;
        }

        if ($data['attachment']) {
            $data['attachment'] = app(AiChatImages::class)->publicMetadata($data['attachment']);
            if (($data['attachment']['type'] ?? '') === 'image') {
                $data['attachment']['preview_path'] = '/ai-assistant/conversations/'.$data['conversation_id'].'/messages/'.$data['id'].'/image';
            }
        }

        if (Schema::hasTable('error_report_drafts')) {
            $draft = DB::table('error_report_drafts')->where('message_id', $row->id)->first(['id', 'report_id']);
            if ($draft) {
                $data['error_report_request'] = ['id' => $draft->id, 'report_id' => $draft->report_id];
            }
        }

        return $data;
    }

    public function messages(Request $request, string $conversation): array
    {
        $row = $this->conversation($request, $conversation);
        $data = $request->validate(['limit' => 'sometimes|integer|min:1|max:100', 'before' => 'sometimes|integer|min:1']);
        $limit = $data['limit'] ?? 30;
        $rows = DB::table('ai_messages')->where('conversation_id', $row->id)->when(isset($data['before']), fn ($q) => $q->where('id', '<', $data['before']))->orderByDesc('id')->limit($limit + 1)->get();
        $page = $rows->take($limit)->reverse()->values();

        return ['conversation' => $row, 'items' => $page->map(fn ($message) => $this->serialize($message)), 'has_more' => $rows->count() > $limit, 'next_before' => $rows->count() > $limit ? $page->first()->id : null,
            'actions' => $this->actions($this->actor($request), $row->id), 'billing_requests' => $this->billing($this->actor($request), $row->id), 'weekly_usage' => $this->weeklyUsage($this->actor($request))];
    }

    public function image(Request $request, string $conversation, int $message)
    {
        $row = $this->conversation($request, $conversation);
        $saved = DB::table('ai_messages')->where('conversation_id', $row->id)->where('id', $message)->first();
        abort_unless($saved && $saved->attachment, 404);
        $metadata = json_decode($saved->attachment, true);
        abort_unless(($metadata['type'] ?? '') === 'image', 404);
        [$bytes, $mime] = app(AiChatImages::class)->read($metadata, (int) $this->actor($request)->business_id, $row->id);

        return response($bytes, 200, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function delivery(Request $request, string $conversation, string $clientId): array
    {
        $row = $this->conversation($request, $conversation);
        $message = DB::table('ai_messages')->where('conversation_id', $row->id)->where('client_id', $clientId)->where('role', 'user')->first();

        // A pending message and its run are committed together before calling the provider.
        // Seen means accepted for AI processing, not a provider read receipt.
        return ($message ? $this->exchange($message->id, $this->actor($request), $row->id) : ['items' => [], 'pending' => false]) + ['message' => $message ? $this->serialize($message) : null,
            'sent' => (bool) $message, 'seen' => $message && in_array($message->status, ['pending', 'completed'])];
    }

    public function send(Request $request, string $conversation)
    {
        $row = $this->conversation($request, $conversation);
        app(AiAssistantEntitlement::class)->requireAccess($this->actor($request));
        $settings = $this->available();
        $user = $this->actor($request);
        $data = $request->validate(['client_id' => 'required|uuid', 'body' => 'nullable|string|max:4000', 'language' => 'sometimes|in:en,my', 'app' => 'sometimes|in:business_web,business_mobile,windows', 'csv' => 'sometimes|file|max:5120|prohibits:image', 'image' => 'sometimes|file|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=8192,max_height=8192|prohibits:csv', 'price_types' => 'nullable|string|max:1000']);
        $body = trim($data['body'] ?? '');
        $file = $request->file('csv');
        $image = $request->file('image');
        $newImagePath = null;
        $imagePersisted = false;
        $lock = Cache::lock('mkpos-ai-user-'.$user->id, 240);
        abort_unless($lock->get(), 409, 'Another AI reply is still processing. Please try again shortly.');
        try {
            $row = app(AiConversationAccess::class)->prepareSend($user, $row->id);
            $existing = DB::table('ai_messages')->where('conversation_id', $row->id)->where('client_id', $data['client_id'])->first();
            $attachment = $existing?->attachment ? json_decode($existing->attachment, true) : null;
            if ($image) {
                $attachment = app(AiChatImages::class)->inspect($image);
            }
            if ($file) {
                abort_unless(strtolower($file->getClientOriginalExtension()) === 'csv', 422, 'Attach a UTF-8 .csv file only.');
                $checked = app(McpProductImportValidator::class)->check(['csv_content' => $file->get(), 'filename' => basename($file->getClientOriginalName()), 'price_types' => $data['price_types'] ?? '', 'limit' => 20, 'issues_only' => true]);
                $attachment = ['name' => Str::limit(basename($file->getClientOriginalName()), 120, ''), 'validation' => $checked];
                if (! $existing && $user->role === 'owner' && app(AiBusinessActions::class)->ready() && app(SubscriptionService::class)->status((int) $user->business_id)['can_mutate']) {
                    try {
                        $import = app(ProductImportService::class)->preview($file->get(), $attachment['name'], (int) $user->id);
                        $attachment['import_id'] = $import['id'];
                        $attachment['import_summary'] = $import;
                    } catch (ValidationException $e) {
                        $attachment['import_error'] = 'The CSV could not be staged. Correct its validation errors and attach it again.';
                    } catch (HttpExceptionInterface $e) {
                        if ($e->getStatusCode() !== 422) {
                            throw $e;
                        }
                        $attachment['import_error'] = 'The CSV could not be staged. Correct its validation errors and attach it again.';
                    }
                }
            }
            abort_if($body === '' && ! $attachment, 422, 'Enter a message or attach an image or product CSV.');
            $hash = hash('sha256', json_encode([$body, $attachment['validation']['content_sha256'] ?? null, $attachment['validation']['price_types'] ?? []]));
            if (($attachment['type'] ?? '') === 'image') {
                $hash = hash('sha256', json_encode([$body, 'image', $attachment['_image_sha256']]));
            }
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This message key belongs to different content. Start a new message.');
                if (in_array($existing->status, ['pending', 'completed'], true)) {
                    $exchange = $this->exchange($existing->id, $user, $row->id);

                    return response()->json($exchange, $exchange['pending'] ? 202 : 200);
                }
            }
            if ($existing && $existing->attachment) {
                $attachment = json_decode($existing->attachment, true);
            }
            $book = app(AiBalanceBook::class);
            $book->funded();
            $pricing = $book->pricing($settings['model']);
            // Lock the actor while checking all attempts across conversations, including failures.
            if ($image && ! $existing) {
                $newImagePath = $image->store('ai-chat-images/'.$user->business_id.'/'.$row->id, 'local');
                $attachment['_image_path'] = $newImagePath;
            }
            $runId = (string) Str::uuid();
            $messageId = DB::transaction(function () use ($user, $row, $body, $attachment, $data, $hash, $existing, $settings, $runId, $pricing) {
                DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                // Match worker completion's user-before-wallet lock order.
                app(AiBalanceBook::class)->funded(lock: true);
                abort_if(DB::table('ai_runs')->where('user_id', $user->id)->whereNotNull('message_id')->whereIn('status', ['pending', 'processing'])->exists(), 409, 'Another Wyne Wyne reply is still being prepared. Wait for it to finish.');
                abort_if(app(AiWeeklyQuota::class)->usage($user->id, $settings['weekly_limit'])['remaining'] === 0, 429, 'Your weekly AI message limit has been reached. It resets Monday at midnight Asia/Yangon.');
                $id = $existing?->id;
                if ($id) {
                    DB::table('ai_messages')->where('id', $id)->update(['status' => 'pending', 'attempts' => $existing->attempts + 1, 'updated_at' => now('UTC')]);
                } else {
                    $id = DB::table('ai_messages')->insertGetId(['conversation_id' => $row->id, 'client_id' => $data['client_id'], 'role' => 'user', 'body' => $body, 'status' => 'pending', 'request_hash' => $hash, 'attachment' => $attachment ? json_encode($attachment, JSON_UNESCAPED_UNICODE) : null, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                }
                DB::table('ai_runs')->insert(['id' => $runId, 'user_id' => $user->id, 'business_id' => $user->business_id, 'model' => $settings['model'], 'status' => 'pending', 'message_id' => $id, 'actor_role' => $user->role, 'language' => $data['language'] ?? 'en', 'app' => $data['app'] ?? 'business_web', 'max_tokens' => $settings['max_tokens'], 'pricing' => json_encode($pricing, JSON_THROW_ON_ERROR), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                DB::table('ai_conversations')->where('id', $row->id)->update(['title' => Str::limit($body ?: ($attachment['name'] ?? 'Product CSV'), 100, ''), 'updated_at' => now('UTC')]);

                return $id;
            });
            $imagePersisted = true;
            try {
                WyneWyneReply::dispatch($runId)->onConnection('wyne-wyne')->onQueue('wyne-wyne');
                DB::table('ai_runs')->where('id', $runId)->update(['queued_at' => now('UTC')]);
            } catch (\Throwable $error) {
                // The durable pending run is recovered by the scheduler, never run in HTTP.
                Log::warning('Wyne Wyne queue dispatch unavailable', ['run_id' => $runId, 'exception_class' => get_class($error)]);
            }
            $exchange = $this->exchange($messageId, $user, $row->id);
            if ($exchange['status'] === 'failed') {
                return response()->json(['message' => 'AI could not reply. Your message is saved; retry it shortly.', 'message_id' => $messageId] + $exchange, 503);
            }

            return response()->json($exchange, $exchange['pending'] ? 202 : 200);
        } finally {
            if ($newImagePath && ! $imagePersisted) {
                Storage::disk('local')->delete($newImagePath);
            }
            $lock->release();
        }
    }

    private function exchange(int $id, $actor, string $conversation): array
    {
        // Permissions can change during a provider call or before a completed retry.
        app(AiConversationAccess::class)->find($actor, $conversation);

        $status = DB::table('ai_messages')->where('conversation_id', $conversation)->where('id', $id)->value('status');

        return ['pending' => $status === 'pending', 'status' => $status, 'items' => DB::table('ai_messages')->where('conversation_id', $conversation)->where(fn ($q) => $q->where('id', $id)->orWhere('reply_to_id', $id))->orderBy('id')->get()->map(fn ($m) => $this->serialize($m)), 'actions' => $this->actions($actor, $conversation), 'billing_requests' => $this->billing($actor, $conversation), 'weekly_usage' => $this->weeklyUsage($actor)];
    }

    private function weeklyUsage($actor): array
    {
        return app(AiWeeklyQuota::class)->usage($actor->id, app(AiAssistantSettings::class)->get()['weekly_limit']);
    }

    private function billing($actor, string $conversation): array
    {
        return $actor->role === 'owner' && in_array('billing', config('mkpos.enabled_modules', []), true) && app(AiBillingRequests::class)->ready()
            ? app(AiBillingRequests::class)->listing($actor, $conversation) : [];
    }

    private function actions($actor, string $conversation): array
    {
        return $actor->role === 'owner' && app(AiBusinessActions::class)->ready() ? app(AiBusinessActions::class)->listing($actor, $conversation) : ['items' => [], 'has_more' => false];
    }

    public function template(Request $request)
    {
        $data = $request->validate(['price_types' => 'nullable|string|max:1000']);
        $template = app(McpProductImportValidator::class)->template($data);

        return ['filename' => $template['filename'], 'csv' => $template['template_csv']];
    }
}
