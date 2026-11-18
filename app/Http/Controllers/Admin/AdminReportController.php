<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminReportRequest;
use App\Services\Admin\ReconciliationReportService;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    public function __invoke(
        AdminReportRequest $request,
        ReconciliationReportService $reportService,
    ): Response {
        $validated = $request->validated();

        $report = $reportService->generate(
            startDate: (string) $validated['start_date'],
            endDate: (string) $validated['end_date'],
            view: (string) $validated['view'],
        );

        return Inertia::render('admin/Report', $report);
    }
}
