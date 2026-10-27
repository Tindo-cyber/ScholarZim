<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Services\RegistrationService;
use App\Support\ProviderRegistrationDetails;
use Illuminate\Http\Request;

/**
 * An organisation awaiting verification correcting or adding to what it
 * registered with, from its dashboard - how it answers an administrator's
 * request for more information. RegistrationService refuses once reviewed.
 */
class RegistrationDetailsController extends Controller
{
    public function __construct(private readonly RegistrationService $registrationService)
    {
    }

    public function update(Request $request)
    {
        $request->merge(ProviderRegistrationDetails::prepare($request->all()));

        $data = $request->validateWithBag('registration', [
            ...ProviderRegistrationDetails::rules(),
            'contact_name' => \App\Support\UserValidationRules::personName(),
            'contact_role' => ['required', 'string', 'max:150', 'regex:/\p{L}/u'],
            // The same file rules as registration.
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
        ], ProviderRegistrationDetails::messages(), ProviderRegistrationDetails::attributes() + [
            'contact_name' => 'contact person\'s name',
            'contact_role' => 'position or role',
            'certificate' => 'registration document',
        ]);

        try {
            $this->registrationService->updatePendingRegistration($request->user(), $data, $request->file('certificate'));
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return redirect()
            ->to(route('provider.dashboard') . '#registration-details')
            ->with('successMessage', 'Registration details updated. An administrator will review them before you can publish.');
    }
}
