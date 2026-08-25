<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Opportunity;
use App\Services\OpportunityModerationService;
use App\Services\OpportunityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ModerationController extends Controller
{
    public function __construct(
        private readonly OpportunityModerationService $moderationService,
        private readonly OpportunityService $opportunityService,
    ) {
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
            // Surfaced on the preview so the moderator sees a likely double
            // submission before they publish it, not after.
            'duplicates' => $this->opportunityService->findPotentialDuplicates($opportunity),
        ]);
    }

    /**
     * One decision across a selection from the queue.
     *
     * Declines still carry a written reason, exactly as the single-listing path
     * does - it is shown to the provider verbatim, so a bulk decline cannot be a
     * silent one.
     */
    public function bulkReview(Request $request)
    {
        $data = $request->validate([
            'opportunities' => ['required', 'array', 'min:1'],
            'opportunities.*' => ['integer'],
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->moderationService->bulkReview(
            $data['opportunities'],
            $data['decision'],
            $request->user(),
            $data['reason'] ?? null
        );

        $message = $data['decision'] === 'approve'
            ? $result['approved'] . ' scholarship(s) published.'
            : $result['rejected'] . ' scholarship(s) declined and the providers notified.';

        if ($result['failed'] !== []) {
            return back()
                ->with('successMessage', $message)
                ->with('errorMessage', 'Skipped: ' . implode('; ', $result['failed']));
        }

        return back()->with('successMessage', $message);
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
