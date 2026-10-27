<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Rules\InternationalPhoneNumber;
use App\Services\AdminUserService;
use App\Support\AccountStatus;
use App\Support\FormOptions;
use App\Support\RoleNames;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function __construct(private readonly AdminUserService $adminUserService)
    {
    }

    public function index(Request $request)
    {
        // A repeated key (?q[]=x) arrives as an array; only plain text is a filter.
        $filters = array_filter($request->only(['role', 'status', 'q']), 'is_string');

        return view('admin.users', [
            'users' => $this->adminUserService->paginate($filters),
            'roles' => RoleNames::ALL,
            'statuses' => [AccountStatus::ACTIVE, AccountStatus::PENDING, AccountStatus::SUSPENDED, AccountStatus::REJECTED],
            'filters' => $filters,
        ]);
    }

    public function create()
    {
        return view('admin.create-user', ['roles' => RoleNames::ALL]);
    }

    public function store(Request $request)
    {
        // A provider account created here uses full_name as an organisation name ("Chikafu Education Trust"): free
        // text, but it has to contain a letter, the same as at self-registration (UserValidationRules). The
        // person-name rule would reject a legitimate organisation name that happens to contain a digit.
        $isProvider = $request->input('role_name') === RoleNames::PROVIDER;

        $nameRule = $isProvider
            ? \App\Support\UserValidationRules::organisationName()
            : \App\Support\UserValidationRules::personName();

        // Same reasoning as the name rule above: a provider account's phone
        // is a work/organisation number, international and possibly a
        // landline or switchboard - see InternationalPhoneNumber's docblock.
        $phoneRule = $isProvider
            ? ['nullable', 'string', new InternationalPhoneNumber()]
            : ['nullable', 'string', 'regex:' . FormOptions::PHONE_PATTERN];

        $data = $request->validate([
            'full_name' => $nameRule,
            'email' => [...\App\Rules\EmailAddress::rules(), 'unique:users,email'],
            'phone' => $phoneRule,
            'role_name' => ['bail', 'required', Rule::in(RoleNames::ALL)],
            'password' => \App\Support\UserValidationRules::password(),
        ]);

        $user = $this->adminUserService->createUser($data, $request->user());

        return redirect()
            ->route('admin.users.index')
            ->with('successMessage', 'Created account for ' . $user->email . '.');
    }

    public function approveProvider(Request $request, int $id)
    {
        try {
            $user = $this->adminUserService->approveProvider($id, $request->user(), $request->boolean('website_checked'));
        } catch (ModelNotFoundException $e) {
            // Not the "refused" case below - an unknown id stays a plain 404.
            throw $e;
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', $user->displayName() . ' can now publish scholarships.');
    }

    public function rejectProvider(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['bail', 'required', 'string', 'max:500']]);

        try {
            $user = $this->adminUserService->rejectProvider($id, $request->user(), $data['reason']);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', 'Provider ' . $user->displayName() . ' was rejected.');
    }

    public function suspend(Request $request, int $id)
    {
        try {
            $user = $this->adminUserService->suspend($id, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', $user->displayName() . ' has been suspended.');
    }

    public function reactivate(Request $request, int $id)
    {
        $user = $this->adminUserService->reactivate($id, $request->user());

        return back()->with('successMessage', $user->displayName() . ' has been reactivated.');
    }

    public function destroy(Request $request, int $id)
    {
        try {
            $this->adminUserService->delete($id, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', 'Account deleted.');
    }
}
