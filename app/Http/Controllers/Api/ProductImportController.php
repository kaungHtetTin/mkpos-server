<?php

namespace App\Http\Controllers\Api;

use App\Services\ProductImportService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductImportController extends ApiController
{
    public function __construct(private ProductImportService $imports) {}

    public function index(Request $request): array
    {
        return $this->page(DB::table('product_imports')->where('business_id', app(TenantContext::class)->requireId())
            ->select('id', 'filename', 'status', 'total_rows', 'created_at')->orderByDesc('created_at')->orderByDesc('id'), $request, 20, 50);
    }

    public function store(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:csv']]);
        $file = $request->file('file');

        return $this->imports->preview($file->get(), $file->getClientOriginalName(), (int) $request->user('web')->id);
    }

    public function show(string $id): array
    {
        return $this->imports->summary($id);
    }

    public function rows(Request $request, string $id): array
    {
        $this->imports->find($id);
        $query = DB::table('product_import_rows')->where('import_id', $id)
            ->select('id', 'row_number', 'name', 'status', 'errors', 'product_id')->orderBy('row_number');
        if ($request->boolean('issues_only')) {
            $query->whereIn('status', ['invalid', 'failed']);
        }
        $result = $this->page($query, $request, 20, 100);
        $decode = fn ($row) => array_merge($row, ['errors' => json_decode($row['errors'] ?? '[]', true)]);
        if (isset($result['items'])) {
            $result['items'] = array_map($decode, $result['items']);
        } else {
            $result = array_map($decode, $result);
        }

        return $result;
    }

    public function process(Request $request, string $id): array
    {
        return $this->imports->process($id, (int) $request->user('web')->id);
    }
}
