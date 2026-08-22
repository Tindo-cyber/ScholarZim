<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminUserService;
use App\Support\AccountStatus;
use App\Support\RoleNames;
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
        return view('admin.users', [
            'users' => $this->adminUserService->paginate($request->only(['role', 'status', 'q'])),
            'roles' => RoleNames::ALL,
            'statuses' => [AccountStatus::ACTIVE, AccountStatus::PENDING, AccountStatus::SUSPENDED, AccountStatus::REJECTED],
            'filters' => $request->only(['role', 'status', 'q']),
        ]);
    }

    public function create()
    {
        return view('admin.create-user', ['roles' => RoleNames::ALL]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role_name' => ['required', Rule::in(RoleNames::ALL)],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user = $this->adminUserService->createUser($data, $request->user());

        return redirect()
            ->route('admin.users.index')
            ->with('successMessage', 'Created account for ' . $user->email . '.');
    }

    public function approveProvider(Request $request, int $id)
    {
        $user = $this->adminUserService->approveProvider($id, $request->user());

        return back()->with('successMessage', $user->displayName() . ' can now publish scholarships.');
    }

    public function rejectProvider(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $user = $this->adminUserService->rejectProvider($id, $request->user(), $data['reason']);

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
