<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminSearchService;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(private readonly AdminSearchService $adminSearchService)
    {
    }

    public function index(Request $request)
    {
        $term = (string) $request->query('q', '');

        return view('admin.search', [
            'term' => $term,
            'results' => $this->adminSearchService->search($term),
        ]);
    }
}
