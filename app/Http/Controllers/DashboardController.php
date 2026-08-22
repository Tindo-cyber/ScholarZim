<?php

namespace App\Http\Controllers;

use App\Support\RoleNames;
use Illuminate\Http\Request;

/** Legacy /dashboard entry point: forwards to whichever dashboard fits the role. */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        return redirect(RoleNames::dashboardUrl($request->user()->roleName()));
    }
}
