<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PasswordOtpMailer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    private function identity(Request $request): array
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $email = $request->validate(['email' => ['required', 'email', 'max:255']])['email'];
        $scope = $request->is('api/ecommerce/*') ? 'customer' : 'owner';
        return [$email, $scope, hash_hmac('sha256', $scope.':'.$email, config('app.key'))];
    }

    private function account(string $email, string $scope)
    {
        $query = DB::table($scope === 'customer' ? 'platform_customers' : 'users')->where('email', $email)->where('is_active', true);
        if ($scope === 'owner') $query->where('role', 'owner');
        return $query;
    }

    public function requestCode(Request $request, PasswordOtpMailer $mailer): array
    {
        [$email, $scope, $key] = $this->identity($request);
        $mailer->assertConfigured();
        DB::table('password_reset_otps')->insertOrIgnore(['key' => $key]);
        DB::transaction(function () use ($email, $scope, $key, $mailer) {
            $row = DB::table('password_reset_otps')->where('key', $key)->lockForUpdate()->first();
            if ($row->sent_at && Carbon::parse($row->sent_at)->gt(now()->subMinute())) return;
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            DB::table('password_reset_otps')->where('key', $key)->update(['code_hash' => hash_hmac('sha256', $key.':'.$code, config('app.key')), 'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'sent_at' => now()]);
            $account = $this->account($email, $scope)->first();
            if ($account) {
                try { $mailer->send($email, $account->name, $code, $scope === 'customer' ? 'MKPOS Shops' : 'MKPOS Business'); }
                catch (\Throwable $error) {
                    // Never log an OTP, credentials, recipient or SMTP transcript.
                    Log::error('Password reset email delivery failed.', ['exception_type' => get_class($error)]);
                    DB::table('password_reset_otps')->where('key', $key)->update(['code_hash' => null]);
                }
            }
        });
        return ['message' => 'If an eligible account exists, a reset code has been sent. Check your inbox and spam folder.', 'expires_in' => 600, 'resend_after' => 60];
    }

    public function reset(Request $request): array
    {
        [$email, $scope, $key] = $this->identity($request);
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[0-9]{6}$/'], 'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed']]);
        $valid = DB::transaction(function () use ($email, $scope, $key, $data) {
            $query = DB::table('password_reset_otps')->where('key', $key);
            $row = (clone $query)->lockForUpdate()->first();
            if (!$row || !$row->code_hash || !$row->expires_at || Carbon::parse($row->expires_at)->lte(now()) || $row->attempts >= 5) return false;
            if (!hash_equals($row->code_hash, hash_hmac('sha256', $key.':'.$data['code'], config('app.key')))) {
                $query->increment('attempts');
                return false;
            }
            $account = $this->account($email, $scope)->lockForUpdate()->first();
            if (!$account) return false;
            $changes = ['password' => Hash::make($data['password']), 'updated_at' => now()];
            if ($scope === 'owner') {
                $changes += ['remember_token' => Str::random(60), 'password_reset_at' => now()];
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $account->id)->delete();
            } else {
                DB::table('platform_customer_tokens')->where('platform_customer_id', $account->id)->delete();
            }
            $this->account($email, $scope)->update($changes);
            $query->update(['code_hash' => null, 'attempts' => 5]);
            return true;
        });
        if (!$valid) throw ValidationException::withMessages(['code' => 'The code is invalid, expired, or has too many attempts. Request a new code.']);
        return ['message' => 'Password reset successfully. Sign in with your new password.'];
    }
}
