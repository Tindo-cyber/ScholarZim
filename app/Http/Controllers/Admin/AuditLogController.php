<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->only(['action', 'actor', 'entity_type']);

        return view('admin.audit-log', [
            'entries' => $this->auditService->paginate($filters),
            'actions' => $this->auditService->distinctActions(),
            'filters' => $filters,
        ]);
    }
}
