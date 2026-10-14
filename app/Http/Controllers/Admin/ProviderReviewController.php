<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminUserService;
use App\Support\Countries;
use App\Support\ProviderRegistrationDetails;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

/**
 * One organisation's full verification record, and the two review actions that
 * are not a final decision: asking for more information, and recording
 * registration details (the country of an account from before it was asked).
 * Approving and rejecting stay with UserController, the same routes the
 * dashboard queue has always used.
 */
class ProviderReviewController extends Controller
{
    public function __construct(private readonly AdminUserService $adminUserService)
    {
    }

    public function show(int $userId)
    {
        $provider = $this->adminUserService->providerForReview($userId);

        return view('admin.provider-review', [
            'provider' => $provider,
            'profile' => $provider->providerProfile,
            'countries' => Countries::options(),
        ]);
    }

    public function requestInformation(Request $request, int $id)
    {
        $data = $request->validateWithBag('requestInfo', [
            'note' => ['required', 'string', 'max:1000'],
        ], [], ['note' => 'what you need']);

        try {
            $user = $this->adminUserService->requestProviderInformation($id, $request->user(), $data['note']);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', $user->displayName() . ' has been asked for more information. It stays unable to publish until you verify it.');
    }

    public function updateRegistration(Request $request, int $id)
    {
        $request->merge(ProviderRegistrationDetails::prepare($request->all()));

        $data = $request->validateWithBag(
            'registration',
            ProviderRegistrationDetails::rules(requireNumberOrNote: false),
            ProviderRegistrationDetails::messages(),
            ProviderRegistrationDetails::attributes()
        );

        try {
            $user = $this->adminUserService->updateProviderRegistration($id, $request->user(), $data);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', 'Registration details for ' . $user->displayName() . ' saved.');
    }
}
