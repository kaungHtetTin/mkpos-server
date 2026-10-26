<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ErrorReports
{
    public const STATUSES = ['open', 'in_review', 'resolved', 'closed'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    public const CONTACT_CHANNELS = ['facebook', 'telegram', 'viber', 'phone'];

    public function prepare(User $actor, string $conversation, int $message, string $app, array $input): array
    {
        $this->owner($actor);
        $this->message($actor, $conversation, $message);
        $payload = validator($input, ['title' => 'required|string|max:160', 'description' => 'required|string|max:4000',
            'expected_result' => 'nullable|string|max:2000', 'attempted_steps' => 'nullable|string|max:3000',
            'screenshot_message_ids' => 'sometimes|array|max:3', 'screenshot_message_ids.*' => 'integer|min:1|distinct'])->validate();
        abort_unless(trim($payload['title']) && trim($payload['description']), 422, 'A title and problem description are required.');

        return DB::transaction(function () use ($actor, $conversation, $message, $app, $payload) {
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            $existing = DB::table('error_reports')->where('message_id', $message)->where('user_id', $actor->id)->first();
            if ($existing) {
                return $this->publicView($existing) + ['already_submitted' => true];
            }
            $draft = DB::table('error_report_drafts')->where('message_id', $message)->first();
            if (! $draft) {
                $id = DB::table('error_report_drafts')->insertGetId(['business_id' => $actor->business_id, 'user_id' => $actor->id,
                    'conversation_id' => $conversation, 'message_id' => $message, 'app' => $app, 'payload' => json_encode($payload),
                    'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            }

            return ['status' => 'awaiting_owner_contact', 'request_id' => $draft->id ?? $id,
                'message' => 'Report is NOT submitted yet. Ask the owner to open the Contact and submit form in this chat, choose Facebook, Telegram, Viber or phone, and enter their contact. They may explicitly submit without contact. Never invent contact details or claim a report number before owner submission.'];
        });
    }

    public function draft(User $actor, int $id): object
    {
        $this->owner($actor);
        $draft = DB::table('error_report_drafts')->where('id', $id)->where('business_id', $actor->business_id)->where('user_id', $actor->id)->first();
        abort_unless($draft, 404, 'Report request not found.');

        return $draft;
    }

    public function complete(User $actor, int $id, array $contact): array
    {
        return DB::transaction(function () use ($actor, $id, $contact) {
            $draft = $this->draft($actor, $id);
            DB::table('error_report_drafts')->where('id', $id)->lockForUpdate()->first();
            $result = $this->submit($actor, $draft->conversation_id, $draft->message_id, $draft->app, json_decode($draft->payload, true) + $contact);
            DB::table('error_report_drafts')->where('id', $id)->update(['report_id' => $result['id'], 'updated_at' => now('UTC')]);

            return $result;
        });
    }

    public function owner(User $actor): void
    {
        abort_unless(Schema::hasTable('error_reports'), 503, 'Error reporting is being set up. Please try again later.');
        abort_unless(DB::table('users')->where('id', $actor->id)->where('business_id', $actor->business_id)->where('role', 'owner')->where('is_active', true)->exists(), 403, 'An active business owner is required.');
        abort_unless(DB::table('businesses')->where('id', $actor->business_id)->where('status', 'active')->exists(), 403, 'Business access is unavailable.');
    }

    public function context(User $actor, ?string $conversation, ?int $message): array
    {
        $this->owner($actor);
        if (! $conversation || ! $message) {
            return ['screenshots' => []];
        }
        $this->message($actor, $conversation, $message);
        $rows = DB::table('ai_messages')->where('conversation_id', $conversation)->where('role', 'user')->where('id', '<=', $message)->whereNotNull('attachment')->orderByDesc('id')->limit(10)->get();
        $images = [];
        foreach ($rows as $row) {
            $metadata = json_decode($row->attachment, true);
            if (($metadata['type'] ?? '') === 'image') {
                $images[] = ['message_id' => $row->id, 'name' => $metadata['name']];
            }
            if (count($images) === 3) {
                break;
            }
        }

        return ['screenshots' => $images, 'guidance' => 'Use only relevant screenshots. Submission attaches the current message image by default; older images require explicit screenshot_message_ids.'];
    }

    private function message(User $actor, string $conversation, int $message): object
    {
        abort_unless(DB::table('ai_conversations')->where('id', $conversation)->where('user_id', $actor->id)->where('business_id', $actor->business_id)->exists(), 404, 'Conversation not found.');
        $row = DB::table('ai_messages')->where('id', $message)->where('conversation_id', $conversation)->where('role', 'user')->first();
        abort_unless($row, 404, 'Message not found.');

        return $row;
    }

    public function submit(User $actor, string $conversation, int $message, string $app, array $input): array
    {
        $this->owner($actor);
        $current = $this->message($actor, $conversation, $message);
        $data = validator($input, ['title' => 'required|string|max:160', 'description' => 'required|string|max:4000',
            'expected_result' => 'nullable|string|max:2000', 'attempted_steps' => 'nullable|string|max:3000',
            'screenshot_message_ids' => 'sometimes|array|max:3', 'screenshot_message_ids.*' => 'integer|min:1|distinct',
            'contact_choice' => 'required|in:provided,skip', 'contact_channel' => 'required_if:contact_choice,provided|nullable|in:facebook,telegram,viber,phone',
            'contact_value' => 'required_if:contact_choice,provided|nullable|string|max:255'])->validate();
        if ($data['contact_choice'] === 'provided') {
            $value = trim($data['contact_value']);
            $phone = preg_replace('/[\s().-]/', '', $value);
            $valid = match ($data['contact_channel']) {
                'phone', 'viber' => (bool) preg_match('/^\+?[0-9]{7,15}$/', $phone),
                'telegram' => (bool) preg_match('~^(?:@[a-zA-Z0-9_]{5,32}|https://t\.me/[a-zA-Z0-9_]{5,32})$~', $value) || (bool) preg_match('/^\+?[0-9]{7,15}$/', $phone),
                'facebook' => (bool) preg_match('~^https://(?:www\.|m\.)?(?:facebook\.com|fb\.com|fb\.me|m\.me)/\S+$~i', $value),
                default => false,
            };
            if (! $valid) {
                throw ValidationException::withMessages(['contact_value' => match ($data['contact_channel']) {
                    'facebook' => 'Enter your Facebook profile or Messenger link.',
                    'telegram' => 'Enter a Telegram @username, t.me link or phone number.',
                    default => 'Enter a valid phone number, including country code if needed.',
                }]);
            }
            $data['contact_value'] = $value;
        } else {
            $data['contact_channel'] = null;
            $data['contact_value'] = null;
        }
        abort_unless(trim($data['title']) !== '' && trim($data['description']) !== '', 422, 'A title and problem description are required.');
        $copies = [];
        try {
            return DB::transaction(function () use ($actor, $conversation, $message, $app, $data, $current, &$copies) {
                DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
                $this->owner($actor);
                $previous = DB::table('error_reports')->where('message_id', $message)->where('user_id', $actor->id)->first();
                if ($previous) {
                    return $this->publicView($previous) + ['already_submitted' => true];
                }
                $ids = $data['screenshot_message_ids'] ?? (($meta = json_decode($current->attachment ?? 'null', true)) && ($meta['type'] ?? '') === 'image' ? [$message] : []);
                $images = [];
                foreach ($ids as $id) {
                    abort_if((int) $id > $message, 422, 'Choose screenshots from this conversation before the reported message.');
                    $source = $this->message($actor, $conversation, (int) $id);
                    $metadata = json_decode($source->attachment ?? 'null', true);
                    abort_unless(($metadata['type'] ?? '') === 'image', 422, 'The selected message has no screenshot.');
                    [$bytes, $mime] = app(AiChatImages::class)->read($metadata, (int) $actor->business_id, $conversation);
                    $path = 'error-report-images/'.$actor->business_id.'/'.Str::uuid();
                    abort_unless(Storage::disk('local')->put($path, $bytes), 503, 'The screenshot could not be saved.');
                    $copies[] = $path;
                    $images[] = ['name' => $metadata['name'], 'mime' => $mime, 'size' => strlen($bytes), '_path' => $path, '_sha256' => hash('sha256', $bytes)];
                }
                $id = DB::table('error_reports')->insertGetId(['business_id' => $actor->business_id, 'user_id' => $actor->id, 'conversation_id' => $conversation,
                    'message_id' => $message, 'source' => 'assistant', 'app' => in_array($app, ['business_web', 'business_mobile', 'windows'], true) ? $app : 'unknown',
                    'title' => trim($data['title']), 'description' => trim($data['description']), 'expected_result' => $data['expected_result'] ?? null,
                    'attempted_steps' => $data['attempted_steps'] ?? null, 'contact_channel' => $data['contact_channel'], 'contact_value' => $data['contact_value'],
                    'screenshots' => json_encode($images), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

                return $this->publicView(DB::table('error_reports')->where('id', $id)->first()) + ['already_submitted' => false];
            });
        } catch (\Throwable $e) {
            foreach ($copies as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    public function publicView(object $row): array
    {
        return ['id' => (int) $row->id, 'reference' => 'ERR-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT), 'title' => $row->title,
            'status' => $row->status, 'resolution' => $row->resolution, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at];
    }

    public function mine(User $actor, array $args): array
    {
        $this->owner($actor);
        $data = validator($args, ['report_id' => 'sometimes|integer|min:1', 'offset' => 'sometimes|integer|min:0|max:10000', 'limit' => 'sometimes|integer|min:1|max:20'])->validate();
        $query = DB::table('error_reports')->where('business_id', $actor->business_id)->where('user_id', $actor->id);
        if (isset($data['report_id'])) {
            $row = $query->where('id', $data['report_id'])->first();
            abort_unless($row, 404, 'Report not found.');

            return ['report' => $this->publicView($row)];
        }
        $limit = $data['limit'] ?? 10;
        $rows = $query->orderByDesc('id')->offset($data['offset'] ?? 0)->limit($limit + 1)->get();

        return ['items' => $rows->take($limit)->map(fn ($row) => $this->publicView($row))->all(), 'has_more' => $rows->count() > $limit];
    }

    public function image(object $report, int $index)
    {
        $images = json_decode($report->screenshots ?? '[]', true);
        $image = $images[$index] ?? null;
        abort_unless($image && str_starts_with($image['_path'], 'error-report-images/'.$report->business_id.'/') && ! str_contains($image['_path'], '..'), 404);
        abort_unless(Storage::disk('local')->exists($image['_path']), 404);
        $bytes = Storage::disk('local')->get($image['_path']);
        abort_unless(hash_equals($image['_sha256'], hash('sha256', $bytes)), 404);

        return response($bytes, 200, ['Content-Type' => $image['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
