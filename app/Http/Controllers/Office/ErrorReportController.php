<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Services\ErrorReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ErrorReportController extends Controller
{
    private function query()
    {
        return DB::table('error_reports as r')->leftJoin('businesses as b', 'b.id', '=', 'r.business_id')->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->select('r.*', 'b.name as business_name', 'u.name as reporter_name');
    }

    private function row(int $id): object
    {
        $row = $this->query()->where('r.id', $id)->first();
        abort_unless($row, 404);

        return $row;
    }

    private function view(object $row): array
    {
        $data = (array) $row;
        $data['version'] = hash('sha256', json_encode($data));
        $data['reference'] = app(ErrorReports::class)->publicView($row)['reference'];
        $data['screenshots'] = array_map(fn ($image, $index) => ['name' => $image['name'], 'size' => $image['size'],
            'path' => '/office/error-reports/'.$row->id.'/images/'.$index], json_decode($row->screenshots ?? '[]', true), array_keys(json_decode($row->screenshots ?? '[]', true)));

        return $data;
    }

    public function index(Request $request): array
    {
        $data = $request->validate(['q' => 'sometimes|nullable|string|max:200', 'status' => ['sometimes', Rule::in(['all', ...ErrorReports::STATUSES])],
            'priority' => ['sometimes', Rule::in(['all', ...ErrorReports::PRIORITIES])], 'page' => 'sometimes|integer|min:1', 'per_page' => ['sometimes', Rule::in([10, 25, 50, 100])]]);
        $query = $this->query();
        if ($data['q'] ?? '') {
            $query->where(fn ($q) => $q->where('r.title', 'like', '%'.$data['q'].'%')->orWhere('r.description', 'like', '%'.$data['q'].'%')->orWhere('b.name', 'like', '%'.$data['q'].'%'));
        }
        foreach (['status', 'priority'] as $key) {
            if (($data[$key] ?? 'all') !== 'all') {
                $query->where('r.'.$key, $data[$key]);
            }
        }
        $total = (clone $query)->count();
        $per = (int) ($data['per_page'] ?? 25);
        $last = max(1, (int) ceil($total / $per));
        $page = min((int) ($data['page'] ?? 1), $last);
        $rows = $query->orderByDesc('r.id')->offset(($page - 1) * $per)->limit($per)->get();

        return ['items' => $rows->map(fn ($row) => $this->view($row))->all(), 'total' => $total, 'page' => $page, 'per_page' => $per, 'last_page' => $last];
    }

    public function businesses(Request $request): array
    {
        $data = $request->validate(['q' => 'sometimes|nullable|string|max:120', 'offset' => 'sometimes|integer|min:0|max:10000']);
        $rows = DB::table('businesses')->when($data['q'] ?? '', fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))->orderBy('name')->orderBy('id')->offset($data['offset'] ?? 0)->limit(21)->get(['id', 'name']);

        return ['items' => $rows->take(20)->values()->all(), 'has_more' => $rows->count() > 20];
    }

    private function fields(Request $request): array
    {
        return $request->validate(['title' => 'required|string|max:160', 'description' => 'required|string|max:4000', 'expected_result' => 'nullable|string|max:2000',
            'attempted_steps' => 'nullable|string|max:3000', 'status' => ['required', Rule::in(ErrorReports::STATUSES)], 'priority' => ['required', Rule::in(ErrorReports::PRIORITIES)],
            'resolution' => 'nullable|string|max:4000', 'internal_note' => 'nullable|string|max:4000', 'app' => 'required|in:business_web,business_mobile,windows,unknown']);
    }

    public function show(int $report): array
    {
        return $this->view($this->row($report));
    }

    public function store(Request $request)
    {
        $data = $this->fields($request);
        $business = $request->validate(['business_id' => 'required|integer|exists:businesses,id']);
        $id = DB::table('error_reports')->insertGetId($data + $business + ['source' => 'office', 'created_by_admin_id' => $request->user('office')->id,
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return response()->json($this->show($id), 201);
    }

    public function update(Request $request, int $report): array
    {
        $data = $this->fields($request);
        $request->validate(['version' => 'required|string|size:64']);

        return DB::transaction(function () use ($data, $request, $report) {
            DB::table('error_reports')->where('id', $report)->lockForUpdate()->first();
            $current = $this->show($report);
            abort_unless(hash_equals($current['version'], $request->input('version')), 409, 'This report changed. Reopen it before saving.');
            DB::table('error_reports')->where('id', $report)->update($data + ['updated_by_admin_id' => $request->user('office')->id, 'updated_at' => now('UTC')]);

            return $this->show($report);
        });
    }

    public function destroy(Request $request, int $report): array
    {
        $request->validate(['version' => 'required|string|size:64']);

        return DB::transaction(function () use ($request, $report) {
            DB::table('error_reports')->where('id', $report)->lockForUpdate()->first();
            $row = $this->row($report);
            abort_unless(hash_equals($this->view($row)['version'], $request->input('version')), 409, 'This report changed. Reopen it before deleting.');
            DB::table('error_reports')->where('id', $report)->delete();
            DB::afterCommit(function () use ($row) {
                foreach (json_decode($row->screenshots ?? '[]', true) as $image) {
                    if (str_starts_with($image['_path'], 'error-report-images/'.$row->business_id.'/') && ! str_contains($image['_path'], '..')) {
                        Storage::disk('local')->delete($image['_path']);
                    }
                }
            });

            return ['deleted' => true];
        });
    }

    public function image(int $report, int $index)
    {
        return app(ErrorReports::class)->image($this->row($report), $index);
    }
}
