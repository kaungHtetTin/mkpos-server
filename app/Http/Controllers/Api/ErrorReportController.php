<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ErrorReports;
use Illuminate\Http\Request;

class ErrorReportController extends Controller
{
    public function draft(Request $request, int $draft, ErrorReports $reports): array
    {
        $row = $reports->draft($request->user('web'), $draft);
        $payload = json_decode($row->payload, true);

        return ['id' => $row->id, 'title' => $payload['title'], 'description' => $payload['description'],
            'report_id' => $row->report_id, 'reference' => $row->report_id ? 'ERR-'.str_pad((string) $row->report_id, 6, '0', STR_PAD_LEFT) : null];
    }

    public function complete(Request $request, int $draft, ErrorReports $reports): array
    {
        return $reports->complete($request->user('web'), $draft, $request->only(['contact_choice', 'contact_channel', 'contact_value']));
    }

    public function index(Request $request, ErrorReports $reports): array
    {
        return $reports->mine($request->user('web'), $request->query());
    }

    public function show(Request $request, int $report, ErrorReports $reports): array
    {
        return $reports->mine($request->user('web'), ['report_id' => $report]);
    }

    public function store(Request $request, ErrorReports $reports)
    {
        $data = $request->validate(['conversation_id' => 'required|uuid', 'message_id' => 'required|integer|min:1', 'app' => 'sometimes|in:business_web,business_mobile,windows']);
        $result = $reports->submit($request->user('web'), $data['conversation_id'], $data['message_id'], $data['app'] ?? 'business_web', $request->all());

        return response()->json($result, $result['already_submitted'] ? 200 : 201);
    }
}
