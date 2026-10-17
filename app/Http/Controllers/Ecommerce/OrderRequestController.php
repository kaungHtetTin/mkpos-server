<?php
namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Services\EcommerceService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrderRequestController extends Controller
{
    public function __construct(private EcommerceService $commerce, private TenantContext $tenant) {}
    private function authorized(Request $request): object
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:40'], 'credential' => ['required', 'string', 'size:64']]);
        return $this->commerce->track($data['reference'], $data['credential']);
    }

    public function store(Request $request): array
    {
        $data = $request->validate(['type' => ['required', 'in:cancellation,return'], 'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        abort_unless(trim($data['reason']) !== '', 422, 'Enter a reason for your request.');
        $order = $this->authorized($request);
        $path = $request->file('photo')?->store('shop-return-photos/'.$order->business_id, 'local');
        try {
            return DB::transaction(function () use ($order, $data, $path) {
                $order = DB::table('ecommerce_orders')->where('business_id', $order->business_id)->where('id', $order->id)->lockForUpdate()->first();
                $existing = DB::table('shop_order_requests')->where('order_id', $order->id)->where('type', $data['type'])->first();
                if ($existing) {
                    if ($path) Storage::disk('local')->delete($path);
                    return $this->commerce->orderView($order);
                }
                if ($data['type'] === 'cancellation') abort_if(in_array($order->status, ['completed', 'cancelled', 'expired', 'return_pending']), 409, 'This order cannot receive a cancellation request.');
                else abort_unless($order->status === 'completed' && !$order->sale_voided && $order->payment_status === 'paid', 409, 'Only a paid completed order can receive a return/refund request.');
                $automatic = $data['type'] === 'cancellation' && $order->status === 'pending' && $order->payment_status === 'unpaid';
                DB::table('shop_order_requests')->insert(['business_id' => $order->business_id, 'order_id' => $order->id,
                    'type' => $data['type'], 'status' => $automatic ? 'approved' : 'pending', 'reason' => trim($data['reason']),
                    'photo_path' => $data['type'] === 'return' ? $path : null, 'created_at' => now(), 'updated_at' => now()]);
                if ($data['type'] === 'cancellation' && $path) Storage::disk('local')->delete($path);
                $this->commerce->event($order->business_id, $order->id, $data['type'].'_requested', null, trim($data['reason']));
                if ($automatic) {
                    $this->commerce->transition($order->business_id, $order->id, 'cancelled', null, trim($data['reason']));
                    $this->commerce->event($order->business_id, $order->id, 'cancellation_approved');
                }
                return $this->commerce->orderView(DB::table('ecommerce_orders')->where('id', $order->id)->first());
            });
        } catch (\Throwable $e) { if ($path) Storage::disk('local')->delete($path); throw $e; }
    }

    public function resolve(Request $request, int $id, int $requestId): array
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected,resolved'], 'staff_note' => ['required', 'string', 'min:5', 'max:2000'], 'return_confirmed' => ['sometimes', 'boolean']]);
        return $this->commerce->scoped($this->tenant->requireId(), fn () => DB::transaction(function () use ($request, $id, $requestId, $data) {
            $business = $this->tenant->requireId();
            $order = DB::table('ecommerce_orders')->where('business_id', $business)->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            $row = DB::table('shop_order_requests')->where('business_id', $business)->where('order_id', $id)->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status === $data['status']) return $this->commerce->orderView($order, true);
            abort_unless($row->status === 'pending' || ($row->type === 'return' && $row->status === 'approved' && $data['status'] === 'resolved'), 409, 'This request has already been reviewed.');
            if ($data['status'] === 'resolved') abort_unless($row->type === 'return' && $order->sale_voided && $order->payment_status === 'refunded', 409, 'Use the controlled sale void and full refund workflow before resolving this request.');
            if ($row->type === 'cancellation' && $data['status'] === 'approved') {
                abort_if(in_array($order->status, ['completed', 'expired']), 409, 'This order can no longer be cancelled.');
                if (in_array($order->status, ['dispatched', 'return_pending'])) {
                    abort_unless($request->boolean('return_confirmed'), 422, 'Confirm goods have physically returned before cancelling.');
                    if ($order->status === 'dispatched') $this->commerce->transition($business, $id, 'return_pending', $request->user('web')->id, $data['staff_note']);
                }
                $this->commerce->transition($business, $id, 'cancelled', $request->user('web')->id, $data['staff_note'], $request->boolean('return_confirmed'));
            }
            DB::table('shop_order_requests')->where('id', $requestId)->where('business_id', $business)->update(['status' => $data['status'], 'staff_note' => trim($data['staff_note']), 'actor_id' => $request->user('web')->id, 'updated_at' => now()]);
            $this->commerce->event($business, $id, $row->type.'_'.$data['status'], $request->user('web')->id, trim($data['staff_note']));
            return $this->commerce->orderView(DB::table('ecommerce_orders')->where('id', $id)->where('business_id', $business)->first(), true);
        }));
    }

    public function photo(Request $request, int $id, int $requestId)
    {
        $row = DB::table('shop_order_requests')->where('business_id', $this->tenant->requireId())->where('order_id', $id)->where('id', $requestId)->first();
        abort_unless($row && $row->photo_path && Storage::disk('local')->exists($row->photo_path), 404);
        return response()->file(Storage::disk('local')->path($row->photo_path), ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
