<?php

namespace App\Http\Controllers;

use App\Services\McpSupportAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class McpSupportAccessController extends Controller
{
    public function owner(Request $request): array
    {
        return $this->issue($request, 'owner', $request->user('web'));
    }

    public function customer(Request $request): array
    {
        return $this->issue($request, 'customer', $request->attributes->get('platform_customer'));
    }

    public function admin(Request $request): array
    {
        return $this->issue($request, 'admin', Auth::guard('office')->user());
    }

    private function issue(Request $request, string $type, object $actor): array
    {
        abort_if($type === 'owner' && $actor->role !== 'owner', 403, 'Business owner access required.');
        $data = $request->validate(['consent' => ['required', 'accepted'], 'allow_writes' => ['sometimes', 'boolean'],
            'current_password' => ['sometimes', 'string']]);
        $scopes = ($data['allow_writes'] ?? false) ? ['read', 'write'] : ['read'];
        // Existing owner profile changes require password verification. Keep this in
        // the trusted signed-in app, never in model-visible tool arguments.
        if ($type === 'owner' && isset($data['current_password'])) {
            abort_unless(Hash::check($data['current_password'], $actor->password), 422, 'Current password is incorrect.');
            if (in_array('write', $scopes, true)) {
                $scopes[] = 'profile_write';
            }
        }

        return app(McpSupportAccess::class)->issue($type, $actor, $scopes);
    }

    public function revoke(Request $request): array
    {
        $context = $request->attributes->get('mcp_support');
        DB::table('mcp_support_tokens')->where('id', $context['token_id'])->delete();

        return ['revoked' => true];
    }

    public function evidence(Request $request): array
    {
        $context = $request->attributes->get('mcp_support');
        abort_unless($context['type'] === 'owner' && in_array('write', $context['scopes'], true), 403);
        $request->validate(['file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $file = $request->file('file');
        $path = $file->store('mcp-payment-evidence', 'local');
        abort_unless($path, 500, 'Evidence could not be stored.');
        try {
            $id = (string) Str::uuid();
            DB::table('mcp_support_evidence')->insert([
                'id' => $id, 'actor_type' => $context['type'], 'actor_id' => $context['id'],
                'file_path' => $path, 'sha256' => hash_file('sha256', $file->getRealPath()),
                'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return ['upload_id' => $id, 'expires_at' => now()->addHour()->toIso8601String()];
    }
}
