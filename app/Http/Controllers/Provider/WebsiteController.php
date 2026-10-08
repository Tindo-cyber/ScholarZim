<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Services\ProviderWebsiteService;
use App\Support\WebsiteAddress;
use Illuminate\Http\Request;

/** A provider stating their organisation's website, from the dashboard. */
class WebsiteController extends Controller
{
    public function __construct(private readonly ProviderWebsiteService $websiteService)
    {
    }

    public function update(Request $request)
    {
        $request->merge(['website' => WebsiteAddress::normalise($request->input('website'))]);

        $data = $request->validateWithBag('website', [
            'website' => WebsiteAddress::rules(),
        ], [
            'website.url' => 'Enter your organisation\'s web address, for example kariba-trust.org.',
        ]);

        try {
            $this->websiteService->set($request->user(), $data['website'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', filled($data['website'] ?? null)
            ? 'Website saved. An administrator will confirm it.'
            : 'Website removed.');
    }
}
