<?php

namespace App\Http\Controllers\Api;

use App\Services\BusinessBackupService;
use Illuminate\Http\Request;

class DataBackupController extends ApiController
{
    public function __construct(private BusinessBackupService $backups)
    {
    }

    public function status(): array
    {
        return $this->backups->status();
    }

    public function export(Request $request)
    {
        $businessId = (int) $request->user('web')->business_id;
        $slug = preg_replace('/[^a-z0-9-]+/i', '-', (string) $request->user('web')->business->slug) ?: 'business';
        $filename = 'mkpos-'.$slug.'-'.now()->format('Y-m-d-His').'.mkpos-backup';

        return response($this->backups->export($businessId), 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function restore(Request $request): array
    {
        $data = $request->validate([
            'backup' => ['required', 'file', 'max:25600'],
            'confirmation' => ['required', 'in:RESTORE'],
            'admin_pin' => ['nullable', 'string'],
        ]);
        $this->requireAdminPin($data['admin_pin'] ?? '');
        $contents = $request->file('backup')->get();

        return $this->backups->restore((int) $request->user('web')->business_id, $contents);
    }
}
