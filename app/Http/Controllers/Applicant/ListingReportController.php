<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Services\OpportunityReportService;
use App\Support\ReportReason;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ListingReportController extends Controller
{
    public function __construct(private readonly OpportunityReportService $reportService)
    {
    }

    /**
     * Validated into a bag named for the listing's dialog, so a refused report
     * reopens that dialog with its error (see components/confirm-dialog).
     */
    public function store(Request $request, int $id)
    {
        $data = $request->validateWithBag('report-' . $id, [
            'reason' => ['required', Rule::in(ReportReason::values())],
            'details' => ['nullable', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Choose what is wrong with this listing.',
            'reason.in' => 'Choose one of the reasons listed.',
        ]);

        try {
            $this->reportService->submit($request->user(), $id, $data['reason'], $data['details'] ?? null);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', 'Thank you. An administrator will look at your report.');
    }
}
