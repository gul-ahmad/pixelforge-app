<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BuildSpendReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SpendReportRequest;
use Inertia\Inertia;
use Inertia\Response;

/** Operators' report: charges, provider costs and profit per day and per user. */
class SpendReportController extends Controller
{
    public function __invoke(SpendReportRequest $request, BuildSpendReport $buildReport): Response
    {
        return Inertia::render('admin/SpendReport', [
            'report' => $buildReport->handle($request->startDate(), $request->endDate()),
            'maxDays' => SpendReportRequest::MAX_DAYS,
        ]);
    }
}
