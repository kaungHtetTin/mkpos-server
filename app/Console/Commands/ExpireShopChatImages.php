<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExpireShopChatImages extends Command
{
    protected $signature = 'shop-chat:expire-images';
    protected $description = 'Delete expired chat images while preserving their message history.';

    public function handle(): int
    {
        $deleted = 0; $failed = 0;
        DB::table('shop_chat_messages')->whereNotNull('image_path')->where('image_expires_at', '<=', now())
            ->orderBy('id')->chunkById(200, function ($messages) use (&$deleted, &$failed) {
                foreach ($messages as $message) {
                    try {
                        DB::transaction(function () use ($message, &$deleted) {
                            $row = DB::table('shop_chat_messages')->where('id', $message->id)->lockForUpdate()->first();
                            if (! $row || ! $row->image_path || now()->lessThan($row->image_expires_at)) return;
                            if (! str_starts_with($row->image_path, 'shop-chat-images/')) throw new \RuntimeException('Unexpected chat image path.');
                            $disk = Storage::disk('local');
                            if ($disk->exists($row->image_path) && ! $disk->delete($row->image_path)) throw new \RuntimeException('Unable to delete chat image.');
                            DB::table('shop_chat_messages')->where('id', $row->id)->update(['image_path' => null, 'image_deleted_at' => now()]);
                            $deleted++;
                        });
                    } catch (\Throwable $error) { $failed++; $this->error('Unable to expire image for message '.$message->id.'.'); }
                }
            });
        $this->info("Expired {$deleted} chat images.");
        return $failed ? 1 : 0;
    }
}
