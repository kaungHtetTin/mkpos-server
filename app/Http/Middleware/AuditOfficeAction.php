<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuditOfficeAction
{
    public function handle(Request $request, Closure $next)
    {
        $admin = Auth::guard('office')->user();
        if ($request->is('api/office/admins*') || (!$request->isMethod('GET') && $request->is('api/office/branding*'))) {
            abort_unless($admin->role === 'super_admin', 403, 'Only a super administrator can manage administrators and branding.');
        }
        if ($request->isMethod('GET') || $request->isMethod('HEAD') || $request->is('api/office/activity-alerts/*')) return $next($request);

        // The mutation and its audit record commit together. Never store passwords,
        // tokens, uploaded image data or arbitrary request bodies in the audit log.
        return DB::transaction(function () use ($request, $next, $admin) {
            $response = $next($request);
            if ($response->getStatusCode() < 400) {
                $details = $request->only(['name', 'email', 'role', 'is_active', 'status', 'subscription_plan_id', 'record_financial', 'duration_days', 'days', 'price_paid', 'account_id', 'subscription_account_id', 'primary_color', 'founders', 'version', 'type', 'amount', 'currency', 'entry_date', 'to_account_id', 'founder_id', 'reason', 'admin_note', 'starts_at', 'ends_at', 'original_starts_at', 'original_ends_at', 'ids']);
                DB::table('office_activity_logs')->insert([
                    'admin_id' => $admin->id, 'admin_name' => $admin->name, 'admin_email' => $admin->email,
                    'action' => class_basename($request->route()->getActionName()),
                    'method' => $request->method(), 'path' => $request->path(),
                    'details' => json_encode($details), 'created_at' => now(),
                ]);
            }
            return $response;
        });
    }
}
