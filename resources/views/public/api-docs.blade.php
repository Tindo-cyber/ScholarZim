@extends('layouts.public')

@section('title', 'Developer API')

@section('meta_description', 'Read-only JSON API for the ScholarZim scholarship catalogue, plus token access to an applicant\'s own applications and ScholarFit recommendations.')

@section('content')
    <div class="container py-4 py-lg-5">

        <x-page-header title="Developer API"
                       :subtitle="$spec['info']['version'] ? 'Version ' . $spec['info']['version'] : null"
                       eyebrow="For integrations" />

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="mb-0">{!! nl2br(e($spec['info']['description'] ?? '')) !!}</div>
                    </div>
                </div>

                @foreach(($spec['tags'] ?? []) as $tag)
                    <h2 class="h5 fw-bold mb-1">{{ $tag['name'] }}</h2>
                    <p class="text-secondary">{{ $tag['description'] ?? '' }}</p>

                    <div class="d-grid gap-3 mb-4">
                        @foreach(($spec['paths'] ?? []) as $path => $methods)
                            @foreach($methods as $method => $operation)
                                @continue(! in_array($tag['name'], $operation['tags'] ?? [], true))

                                <div class="card">
                                    <div class="card-body">
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                            <x-status-badge :label="strtoupper($method)" tone="primary" />
                                            <code class="fw-semibold">{{ $baseUrl }}{{ $path }}</code>
                                            @if(isset($operation['security']))
                                                <x-status-badge label="Token required" tone="warning" icon="lock"
                                                                class="ms-auto" />
                                            @endif
                                        </div>

                                        <p class="mb-2">{{ $operation['summary'] ?? '' }}</p>

                                        @if(! empty($operation['description']))
                                            <p class="small text-secondary">{{ $operation['description'] }}</p>
                                        @endif

                                        @if(! empty($operation['parameters']))
                                            <details class="small">
                                                <summary class="fw-semibold">
                                                    {{ count($operation['parameters']) }} parameter(s)
                                                </summary>
                                                <ul class="mt-2 mb-0 ps-3">
                                                    @foreach($operation['parameters'] as $parameter)
                                                        <li>
                                                            <code>{{ $parameter['name'] }}</code>
                                                            {{-- Built as one expression: a bare @endif directly after a
                                                                 word character is not recognised as a directive. --}}
                                                            <span class="text-secondary">
                                                                ({{ $parameter['in'] }},
                                                                {{ ($parameter['schema']['type'] ?? 'string')
                                                                    . (empty($parameter['required']) ? '' : ', required') }})
                                                            </span>
                                                            @if(! empty($parameter['description']))
                                                                — {{ $parameter['description'] }}
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </details>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                @endforeach
            </div>

            <div class="col-lg-4">
                <div class="card mb-4 position-sticky" style="top: 5.5rem;">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Getting started</h2>
                    </div>
                    <div class="card-body small">
                        <p>The catalogue needs no key at all:</p>
                        <pre class="bg-body-tertiary rounded-3 p-3 mb-3"><code>curl {{ $baseUrl }}/scholarships?sort=deadline</code></pre>

                        <p>
                            For your own applications and recommendations, create a token on the
                            @auth
                                <a href="{{ route('account.security') }}">account security page</a>
                            @else
                                account security page (sign in first)
                            @endauth
                            and send it as a bearer token:
                        </p>
                        <pre class="bg-body-tertiary rounded-3 p-3 mb-3"><code>curl -H "Authorization: Bearer &lt;token&gt;" \
  {{ $baseUrl }}/me/recommendations</code></pre>

                        <p class="mb-0">
                            The full machine-readable description lives at
                            <a href="{{ route('api.v1.openapi') }}">openapi.json</a>.
                        </p>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Limits and rules</h2>
                    </div>
                    <div class="card-body small">
                        <ul class="mb-0 ps-3 d-grid gap-2">
                            <li>60 catalogue requests a minute; 120 with a token.</li>
                            <li>Up to 50 listings per page.</li>
                            <li>
                                Only listings that have cleared moderation and are still open are ever returned,
                                which is the same rule the public site applies.
                            </li>
                            <li>Read-only. Applications are submitted through the site, not the API.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
