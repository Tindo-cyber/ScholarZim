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
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
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
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'organisation_type' => ['required', Rule::in(ProviderOrgType::ALL)],
            'registration_number' => ['required', 'string', 'max:100'],
            'certificate' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'terms' => ['accepted'],
        ]);

        $this->registrationService->registerProvider($data, $request->file('certificate'));

        return redirect()
            ->route('login')
            ->with('successMessage', 'Registration received. An administrator will verify your organisation before you can publish scholarships.');
    }
}
