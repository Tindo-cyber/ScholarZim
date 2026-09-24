<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\RegistrationService;
use App\Support\FormOptions;
use App\Support\ProviderOrgType;
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
            'full_name' => ['required', 'string', 'max:255', 'regex:' . FormOptions::NAME_PATTERN],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:' . FormOptions::PHONE_PATTERN],
            'password' => ['required', 'confirmed', 'regex:/[A-Z]/', Password::min(8)->letters()->numbers()],
            'terms' => ['accepted'],
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
        ]);
    }

    public function registerProvider(Request $request)
    {
        $data = $request->validate([
            // Not FormOptions::NAME_PATTERN: this is an organisation or
            // contact name ("Chikafu Education Trust"), free text for the
            // same reason institution_name is - see FormOptions::NAME_PATTERN's
            // own docblock.
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:' . FormOptions::PHONE_PATTERN],
            'organisation_type' => ['required', Rule::in(ProviderOrgType::ALL)],
            'registration_number' => ['required', 'string', 'max:100'],
            'certificate' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
            'password' => ['required', 'confirmed', 'regex:/[A-Z]/', Password::min(8)->letters()->numbers()],
            'terms' => ['accepted'],
        ]);

        $this->registrationService->registerProvider($data, $request->file('certificate'));

        return redirect()
            ->route('login')
            ->with('successMessage', 'Registration received. An administrator will verify your organisation before you can publish scholarships.');
    }
}
