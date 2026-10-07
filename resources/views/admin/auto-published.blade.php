@extends('layouts.app')

@section('title', 'Published without review')

@section('content')

    <x-page-header title="Published without review"
                   :subtitle="$listings->count() . ' listing(s) from trusted providers went live without waiting for you. Check them when you can.'"
                   eyebrow="Governance" />

    {{--
        What a trusted provider put live, in the order it has been public
        unchecked, longest first. Each one ends one of two ways: "Looks fine"
        takes it out of this list and leaves it live, "Unpublish" takes it off the
        public site with a reason the provider is shown. Anything the risk checker
        flagged never gets here - it waited for an ordinary review - so what is
        listed is what looked clean when it was posted.
    --}}
    <div class="card mb-4">
        @if($listings->isEmpty())
            <div class="card-body text-secondary">
                Nothing is waiting. Every listing a trusted provider has published has been checked.
            </div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Scholarship</th>
                            <th scope="col">Provider</th>
                            <th scope="col">Live since</th>
                            <th scope="col" class="text-end">Check</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($listings as $listing)
                            <tr>
                                <td>
                                    <a class="fw-semibold text-decoration-none"
                                       href="{{ route('admin.moderation.show', $listing->opportunity_id) }}">{{ $listing->title }}</a>
                                    <span class="small text-secondary d-block">{{ \App\Support\EducationLevel::label($listing->education_level) }}</span>
                                </td>
                                <td>{{ $listing->awardingBody() }}</td>
                                <td class="small text-nowrap">
                                    {{ $listing->reviewed_at?->format('d M Y H:i') }}
                                    <span class="text-secondary d-block">{{ $listing->reviewed_at?->diffForHumans() }}</span>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
                                        <form method="POST" action="{{ route('admin.auto-published.confirm', $listing->opportunity_id) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-success">Looks fine</button>
                                        </form>

                                        <x-confirm-dialog :id="'unpublish-' . $listing->opportunity_id"
                                                          :action="route('admin.auto-published.unpublish', $listing->opportunity_id)"
                                                          :title="'Unpublish: ' . $listing->title"
                                                          trigger-label="Unpublish"
                                                          trigger-class="btn btn-sm btn-outline-danger"
                                                          confirm-label="Unpublish"
                                                          message="This takes the listing off the public site. The provider is told why and can resubmit it.">
                                            <x-form.textarea name="reason" :rows="3" required
                                                             :id="'unpublish-reason-' . $listing->opportunity_id"
                                                             :bag="'unpublish-' . $listing->opportunity_id"
                                                             label="Reason (shown to the provider as written)" />
                                        </x-confirm-dialog>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

@endsection
