<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OpportunityResource;
use App\Services\ApplicationService;
use App\Services\NotificationService;
use App\Services\RecommendationService;
use App\Services\ScholarFit\ScoredOpportunity;
use App\Support\RoleNames;
use Illuminate\Http\Request;

/**
 * The signed-in applicant's own data.
 *
 * Reachable two ways, both handled by the `auth:sanctum,web` guard list on the
 * route: a Bearer token for an integration, or the ordinary web session for the
 * dashboard shell's own fetches. Either way the user is whoever the guard says,
 * never an id in the request.
 */
class MeController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ApplicationService $applicationService,
        private readonly RecommendationService $recommendationService,
    ) {
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $profile = $user->applicantProfile;

        return response()->json([
            'id' => $user->user_id,
            'name' => $user->displayName(),
            'email' => $user->email,
            'role' => $user->roleName(),
            'roleLabel' => RoleNames::displayLabel($user->roleName()),
            'accountStatus' => $user->account_status,
            'emailVerified' => (bool) $user->email_verified,
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
            'unreadNotifications' => $this->notificationService->unreadCount($user->user_id),
            'profileCompletion' => $profile?->completionPercentage(),
            'profileMissing' => $profile?->missingFields() ?? [],
        ]);
    }

    /** Powers the bell dropdown without a full page load. */
    public function notifications(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'unread' => $this->notificationService->unreadCount($user->user_id),
            'items' => $this->notificationService->latestForUser($user->user_id, 8)->map(fn ($n) => [
                'id' => $n->notification_id,
                'message' => $n->message,
                'link' => $n->link,
                'icon' => $n->icon(),
                'tone' => $n->tone(),
                'isRead' => (bool) $n->is_read,
                'createdAt' => $n->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function applications(Request $request)
    {
        abort_unless($request->user()->isApplicant(), 403, 'Only applicants have applications.');

        $applications = $this->applicationService->forApplicant($request->user());

        return response()->json([
            'data' => $applications->map(fn ($application) => [
                'id' => $application->application_id,
                'status' => $application->application_status,
                'statusLabel' => $application->statusLabel(),
                'submittedAt' => $application->submitted_at?->toIso8601String(),
                'interviewAt' => $application->interview_at?->toIso8601String(),
                'awaitingResponse' => $application->awaitsApplicantResponse(),
                'scholarship' => [
                    'id' => $application->opportunity?->opportunity_id,
                    'title' => $application->opportunity?->title,
                    'url' => $application->opportunity
                        ? url('/scholarships/' . $application->opportunity->opportunity_id)
                        : null,
                ],
            ])->values(),
        ]);
    }

    /** ScholarFit-ranked listings, with the same breakdown the site renders. */
    public function recommendations(Request $request)
    {
        abort_unless($request->user()->isApplicant(), 403, 'Only applicants receive recommendations.');

        $limit = min(max((int) $request->query('limit', 10), 1), 50);
        $scored = $this->recommendationService->forUser($request->user(), $limit);

        return response()->json([
            'data' => array_map(static fn (ScoredOpportunity $s) => [
                'matchScore' => $s->matchScore,
                'confidence' => $s->breakdown->confidenceLevel,
                'explanation' => $s->breakdown->explanation,
                'missingRequirements' => $s->breakdown->missingRequirements,
                'scholarship' => (new OpportunityResource($s->opportunity))->toArray($request),
            ], $scored),
        ]);
    }
}
