<?php

namespace App\Services\SalesAi;

use App\Http\Controllers\Ecommerce\ShopController;
use App\Services\EcommerceService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CheckoutProof
{
    public function stage(UploadedFile $file, int $businessId): array
    {
        validator(['image' => $file], ['image' => 'required|image|mimes:jpeg,jpg,png,webp|max:2048|dimensions:max_width=4096,max_height=4096'])->validate();
        $path = $file->store('sales-ai-action-proofs/'.$businessId, 'local');

        return ['evidence_path' => $path, 'evidence_hash' => hash_file('sha256', Storage::disk('local')->path($path))];
    }

    public function stageBytes(string $bytes, int $businessId): array
    {
        abort_if(strlen($bytes) > 2 * 1024 * 1024, 422, 'Choose an image up to 2 MB.');
        $path = 'sales-ai-action-proofs/'.$businessId.'/'.Str::uuid().'.upload';
        Storage::disk('local')->put($path, $bytes);
        try {
            return $this->stage(new UploadedFile(Storage::disk('local')->path($path), 'payment.png', test: true), $businessId);
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    public function image(array $evidence): UploadedFile
    {
        abort_unless(! empty($evidence['evidence_path']) && ! empty($evidence['evidence_hash']), 422, 'Attach and review a payment screenshot for this payment method.');
        $disk = Storage::disk('local');
        abort_unless($disk->exists($evidence['evidence_path']) && hash_equals($evidence['evidence_hash'], hash_file('sha256', $disk->path($evidence['evidence_path']))), 409, 'Payment screenshot changed or is unavailable. Attach it again.');

        return new UploadedFile($disk->path($evidence['evidence_path']), 'payment.webp', test: true);
    }

    public function attach(object $order, array $evidence): array
    {
        $proof = Request::create('/', 'POST', ['reference' => $order->reference,
            'credential' => app(EcommerceService::class)->customerTrackingCredential($order)],
            files: ['proof' => $this->image($evidence)]);

        return app(ShopController::class)->proof($proof);
    }

    /** Customer-confirmed checkout and its proof succeed or roll back together. */
    public function submit(object $thread, object $store, array $values, array $evidence = []): array
    {
        return DB::transaction(function () use ($thread, $store, $values, $evidence) {
            $quote = app(EcommerceService::class)->quote($store, $values);
            $required = ($quote['payment_method']['type'] ?? '') !== 'cash';
            $file = $required ? $this->image($evidence) : null;
            $request = Request::create('/', 'POST', $values);
            if ($thread->platform_customer_id) {
                $request->attributes->set('platform_customer', (object) ['id' => $thread->platform_customer_id]);
            }
            $saved = app(ShopController::class)->checkout($request, $store->slug);
            if ($file) {
                $proof = Request::create('/', 'POST', ['reference' => $saved['reference'], 'credential' => $saved['tracking_credential']], files: ['proof' => $file]);
                app(ShopController::class)->proof($proof);
            }

            return $saved + ['payment_status' => $required ? 'proof_submitted' : 'unpaid', 'payment_verified' => false];
        });
    }
}
