<?php

namespace App\Http\Controllers;

use App\Jobs\BuildSalesExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function export(Request $request): JsonResponse
    {
        $month = $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];

        BuildSalesExport::dispatch($month);

        return response()->json(['status' => 'queued'], 202);
    }
}
