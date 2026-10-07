<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\OpportunityModerationService;
use Illuminate\Http\Request;

/**
 * The "published without review" queue: what trusted providers put live, waiting
 * for an administrator to check after the fact.
 */
class AutoPublishedController extends Controller
{
    public function __construct(private readonly OpportunityModerationService $moderationService)
    {
    }

    public function index()
    {
        return view('admin.auto-published', [
            'listings' => $this->moderationService->autoPublishedQueue(),
        ]);
    }

    public function confirm(Request $request, int $id)
    {
        try {
            $listing = $this->moderationService->confirmAutoPublished($id, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', '"' . $listing->title . '" checked. It stays live.');
    }

    public function unpublish(Request $request, int $id)
    {
        $data = $request->validateWithBag('unpublish-' . $id, [
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'A reason is required - the provider is shown it as written.',
        ]);

        try {
            $listing = $this->moderationService->unpublish($id, $request->user(), $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', '"' . $listing->title . '" was taken off the public site and the provider told why.');
    }
}
