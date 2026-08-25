<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Serves the OpenAPI description of v1.
 *
 * The spec lives in resources/api rather than public/, so it is published
 * deliberately through this route instead of by sitting in the web root, and it
 * is version-controlled next to the code it describes.
 */
class DocsController extends Controller
{
    public function spec(): JsonResponse
    {
        $path = resource_path('api/openapi.json');

        abort_unless(is_readable($path), 404, 'API specification not found.');

        $spec = json_decode((string) file_get_contents($path), true);

        abort_if($spec === null, 500, 'API specification is not valid JSON.');

        // The server URL is stamped at request time so the spec is correct on
        // localhost, staging, and production without three copies of the file.
        $spec['servers'] = [[
            'url' => url('/api/v1'),
            'description' => 'This deployment',
        ]];

        return response()->json($spec, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** Human-readable version of the same document. */
    public function page()
    {
        $spec = json_decode((string) file_get_contents(resource_path('api/openapi.json')), true) ?? [];

        return view('public.api-docs', [
            'spec' => $spec,
            'baseUrl' => url('/api/v1'),
        ]);
    }
}
