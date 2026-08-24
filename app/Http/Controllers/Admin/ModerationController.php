<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Opportunity;
use App\Services\OpportunityModerationService;
use Illuminate\Http\Request;

class ModerationController extends Controller
{
    public function __construct(private readonly OpportunityModerationService $moderationService)
    {
    }

    /**
     * Read-only preview from the moderation queue. Reuses the public detail
     * view, but looks the listing up directly since it is still PENDING and
     * would not pass OpportunityService::findPubliclyVisible()'s visibility check.
     */
    public function show(int $id)
    {
        $opportunity = Opportunity::with('provider')->findOrFail($id);

        return view('public.detail', [
            'opportunity' => $opportunity,
            'isSaved' => false,
            'fit' => null,
            'related' => collect(),
        ]);
    }

    public function approve(Request $request, int $id)
    {
        try {
            $opportunity = $this->moderationService->approve($id, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', '"' . $opportunity->title . '" is now live on the public site.');
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $opportunity = $this->moderationService->reject($id, $request->user(), $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', '"' . $opportunity->title . '" was declined and the provider notified.');
    }
}
