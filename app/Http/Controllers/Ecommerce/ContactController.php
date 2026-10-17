<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\ShopContacts;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    public function __construct(private TenantContext $tenant) {}

    public function index(): array { return ['items' => ShopContacts::list($this->tenant->requireId())]; }

    public function store(Request $request)
    {
        $data = ShopContacts::validate($request);
        $id = DB::transaction(function () use ($data) {
            $businessId = $this->tenant->requireId();
            DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
            abort_if($this->query()->count() >= 100, 422, 'A shop can have up to 100 contacts.');
            return $this->query()->insertGetId($data + ['business_id' => $businessId, 'created_at' => now(), 'updated_at' => now()]);
        });
        return response()->json(ShopContacts::view($this->query()->where('id', $id)->first()), 201);
    }

    public function update(Request $request, int $contactId): array
    {
        $data = ShopContacts::validate($request);
        return DB::transaction(function () use ($data, $contactId) {
            abort_unless($this->query()->where('id', $contactId)->lockForUpdate()->first(), 404, 'Contact unavailable.');
            $this->query()->where('id', $contactId)->update($data + ['updated_at' => now()]);
            return ShopContacts::view($this->query()->where('id', $contactId)->first());
        });
    }

    public function destroy(int $contactId): array
    {
        abort_unless($this->query()->where('id', $contactId)->delete(), 404, 'Contact unavailable.');
        return ['ok' => true];
    }

    private function query() { return DB::table('shop_contacts')->where('business_id', $this->tenant->requireId()); }
}
