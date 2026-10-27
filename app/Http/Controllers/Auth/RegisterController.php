<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Rules\InternationalPhoneNumber;
use App\Services\RegistrationService;
use App\Support\Countries;
use App\Support\FormOptions;
use App\Support\ProviderOrgType;
use App\Support\ProviderRegistrationDetails;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function __construct(private readonly RegistrationService $registrationService)
    {
    }

    public function showApplicantForm()
    {
        return view('auth.register', [
            'educationLevels' => FormOptions::educationLevelGroups(),
        ]);
    }

    public function registerApplicant(Request $request)
    {
        $data = $request->validate([
            'full_name' => \App\Support\UserValidationRules::personName(),
            'email' => [...\App\Rules\EmailAddress::rules(), 'unique:users,email'],
            'phone' => ['bail', 'nullable', 'string', 'regex:' . FormOptions::PHONE_PATTERN],
            'password' => \App\Support\UserValidationRules::password(),
        ]);

        $this->registrationService->registerApplicant($data);

        return redirect()
            ->route('login')
            ->with('successMessage', 'Account created. Check your inbox to verify your email address, then sign in.');
    }

    public function showProviderForm()
    {
        return view('auth.register-provider', [
            'orgTypes' => ProviderOrgType::options(),
            'countries' => Countries::options(),
        ]);
    }

    public function registerProvider(Request $request)
    {
        $request->merge(ProviderRegistrationDetails::prepare($request->all()));

        $data = $request->validate([
            // The organisation's registered name ("Chikafu Education Trust"):
            // what users.full_name holds for a provider, and what students see
            // on its listings. Not FormOptions::NAME_PATTERN - an organisation
            // name is free text, for the same reason institution_name is (see
            // that constant's docblock). The person registering is contact_name.
            // Free text (digits and ampersands are fine), but it has to be a name: at least one letter.
            'full_name' => \App\Support\UserValidationRules::organisationName(),
            // The person registering, and their position - what an administrator
            // reads to judge whether they may act for the organisation.
            // A person: the same rule as every other personal name (letters, spaces, apostrophes, hyphens, full stops).
            'contact_name' => \App\Support\UserValidationRules::personName(),
            'contact_role' => ['bail', 'required', 'string', 'max:150', 'regex:/\p{L}/u'],
            'email' => [...\App\Rules\EmailAddress::rules(), 'unique:users,email'],
            // Not FormOptions::PHONE_PATTERN: a provider's own work/organisation
            // number is international and may be a landline, a switchboard, or
            // carry an extension - see InternationalPhoneNumber's own docblock.
            'phone' => ['bail', 'nullable', 'string', new InternationalPhoneNumber()],
            'organisation_type' => ['bail', 'required', Rule::in(ProviderOrgType::ALL)],
            // Country of registration, and the registration number or the reason
            // there is none. Where the organisation is registered says nothing
            // about where the scholarships it posts are held.
            ...ProviderRegistrationDetails::rules(),
            'certificate' => ['bail', 'required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
            'password' => \App\Support\UserValidationRules::password(),
            // The organisation's confirmation that it is registered and that this
            // person may act for it. Not terms of use: the admin still verifies
            // the uploaded certificate, this is the applicant's own statement.
            'authorised' => ['accepted'],
        ], ProviderRegistrationDetails::messages() + [
            'full_name.required' => 'Enter your organisation\'s registered name.',
        ], ProviderRegistrationDetails::attributes() + [
            'full_name' => 'organisation name',
            'contact_name' => 'contact person\'s name',
            'contact_role' => 'position or role',
            'certificate' => 'registration document',
        ]);

        $this->registrationService->registerProvider($data, $request->file('certificate'));

        return redirect()
            ->route('login')
            ->with('successMessage', 'Registration received. An administrator will verify your organisation before you can publish scholarships.');
    }
}
