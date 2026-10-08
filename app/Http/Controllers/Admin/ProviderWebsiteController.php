<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProviderWebsiteService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProviderWebsiteController extends Controller
{
    public function __construct(private readonly ProviderWebsiteService $websiteService)
    {
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(ProviderWebsiteService::DECISIONS)],
        ]);

        try {
            $provider = $this->websiteService->decide($request->user(), $id, $data['decision']);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', $data['decision'] === ProviderWebsiteService::CONFIRM
            ? $provider->full_name . '\'s website is confirmed. Their application links are now checked against it.'
            : 'Confirmation of ' . $provider->full_name . '\'s website withdrawn.');
    }
}
