<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\OpportunityReportService;
use Illuminate\Http\Request;

/** The administrators' queue of listings students have reported. */
class ListingReportController extends Controller
{
    public function __construct(private readonly OpportunityReportService $reportService)
    {
    }

    public function index()
    {
        return view('admin.listing-reports', [
            'listings' => $this->reportService->queue(),
        ]);
    }

    public function dismiss(Request $request, int $id)
    {
        try {
            $listing = $this->reportService->dismiss($id, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', 'Reports on "' . $listing->title . '" dismissed.');
    }

    public function uphold(Request $request, int $id)
    {
        $data = $request->validateWithBag('uphold-' . $id, [
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'A reason is required - the provider is shown it as written.',
        ]);

        try {
            $listing = $this->reportService->uphold($id, $request->user(), $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', 'Reports on "' . $listing->title . '" upheld. The listing is down and the provider has lost trust.');
    }
}
