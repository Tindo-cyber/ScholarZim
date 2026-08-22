<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;

/**
 * Cross-entity search behind the admin search bar. Each entity contributes a
 * small, uniformly shaped group of hits so one page can render them together.
 */
class AdminSearchService
{
    public function search(string $term, int $perGroup = 8): array
    {
        $term = trim($term);

        if ($term === '') {
            return ['users' => [], 'opportunities' => [], 'applications' => [], 'total' => 0];
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

        $users = User::with('role')
            ->where(fn ($q) => $q->where('full_name', 'like', $like)->orWhere('email', 'like', $like))
            ->limit($perGroup)
            ->get();

        $opportunities = Opportunity::with('provider')
            ->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('provider_name', 'like', $like))
            ->limit($perGroup)
            ->get();

        $applications = Application::with(['user', 'opportunity'])
            ->where(fn ($q) => $q
                ->whereHas('user', fn ($u) => $u->where('full_name', 'like', $like)->orWhere('email', 'like', $like))
                ->orWhereHas('opportunity', fn ($o) => $o->where('title', 'like', $like)))
            ->limit($perGroup)
            ->get();

        return [
            'users' => $users,
            'opportunities' => $opportunities,
            'applications' => $applications,
            'total' => $users->count() + $opportunities->count() + $applications->count(),
        ];
    }
}
