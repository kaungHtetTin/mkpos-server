<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Conversation privacy is enforced before reading data or building model context. */
class AiConversationAccess
{
    private function ownerAccess(User $actor): bool
    {
        $access = app(AiAssistantAccess::class)->context($actor);
        abort_unless($access['access_reason'] === null || $access['access_reason'] === 'owner_required', 403, 'AI conversation access is unavailable for this account.');

        return $access['can_read_business_data'];
    }

    public function query(User $actor): Builder
    {
        $owner = $this->ownerAccess($actor);
        $query = DB::table('ai_conversations')->where('user_id', $actor->id)->where('business_id', $actor->business_id);
        if (! $owner) {
            // Fail closed for staff while an existing installation awaits the migration.
            Schema::hasColumn('ai_conversations', 'requires_owner')
                ? $query->where('requires_owner', false)
                : $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public function find(User $actor, string $id): object
    {
        $conversation = $this->query($actor)->where('id', $id)->first();
        abort_unless($conversation, 404);

        return $conversation;
    }

    public function creationAttributes(User $actor): array
    {
        $owner = $this->ownerAccess($actor);
        if (! Schema::hasColumn('ai_conversations', 'requires_owner')) {
            abort_unless($owner, 503, 'AI conversation privacy setup is required. Please contact platform support.');

            return [];
        }

        return ['requires_owner' => $owner];
    }

    /** Caller holds the per-user send lock; protect before saving any owner content. */
    public function prepareSend(User $actor, string $id): object
    {
        return DB::transaction(function () use ($actor, $id) {
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            $conversation = $this->query($actor)->where('id', $id)->lockForUpdate()->first();
            abort_unless($conversation, 404);
            if ($this->ownerAccess($actor) && Schema::hasColumn('ai_conversations', 'requires_owner')) {
                DB::table('ai_conversations')->where('id', $id)->update(['requires_owner' => true]);
                $conversation->requires_owner = true;
            }

            return $conversation;
        });
    }

    public function canUseHistory(User $actor, ?string $conversation): bool
    {
        // Unscoped supplied history cannot be proven safe for a restricted account.
        return $conversation === null ? $this->ownerAccess($actor) : $this->query($actor)->where('id', $conversation)->exists();
    }
}
