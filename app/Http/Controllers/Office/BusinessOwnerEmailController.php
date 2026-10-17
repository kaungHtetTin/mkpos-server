<?php

namespace App\Http\Controllers\Office;

use App\Models\User;
use App\Services\BusinessOwnerMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessOwnerEmailController extends PlatformUserEmailController
{
    protected string $emailTable = 'business_owner_emails';
    protected string $recipientColumn = 'owner_user_id';
    protected string $recipientLabel = 'business owner';

    protected function recipientQuery(object $recipient)
    {
        return User::whereKey($recipient->id)->where('business_id', $recipient->business_id)->where('role', 'owner');
    }

    public function storeOwner(Request $request, int $businessId, BusinessOwnerMailer $mailer)
    {
        abort_unless(DB::table('businesses')->where('id', $businessId)->exists(), 404, 'Business not found.');
        $owners = User::where('business_id', $businessId)->where('role', 'owner')->get();
        abort_unless($owners->count() === 1, 409, 'This business must have exactly one owner before email can be sent.');
        return $this->deliver($request, $owners->first(), $mailer);
    }
}
