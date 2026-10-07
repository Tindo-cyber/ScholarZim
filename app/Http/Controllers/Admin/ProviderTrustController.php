<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProviderTrustService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProviderTrustController extends Controller
{
    public function __construct(private readonly ProviderTrustService $trustService)
    {
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(ProviderTrustService::DECISIONS)],
        ], [
            'decision.in' => 'Choose grant, revoke or clear.',
        ]);

        try {
            $provider = $this->trustService->set($request->user(), $id, $data['decision']);
        } catch (HttpExceptionInterface $e) {
            // A 404 for a user who is not a provider is an answer, not a failure to
            // explain on the page; HttpException is a RuntimeException, so it has to
            // be let through before the catch below swallows it.
            throw $e;
        } catch (\RuntimeException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', ProviderTrustService::describe($data['decision'], $provider->full_name));
    }
}
