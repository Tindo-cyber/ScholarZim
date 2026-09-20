@extends('layouts.app')

@section('title', 'Admin dashboard')

@php
    $waiting = $pendingProviders->count() + $moderationQueue->count();
@endphp

@section('content')

    {{--
        An administration console, read top to bottom as three questions:

          what is the platform          the overview strip
          what is waiting on me         the attention area and the two queues
          what has been happening       recent activity, then trends

        The order used to be the other way round in emphasis. Four full-size
        metric cards opened the page, so "3,140 applications" - a number that
        changes on its own and asks nothing of anybody - had exactly the weight
        of a provider who has been waiting four days to be verified. The totals
        are still here and still first, because an operator does want the shape
        of the platform before the detail; they are just no longer the loudest
        thing on the screen.
    --}}
    <x-page-header title="Platform overview"
                   subtitle="ScholarZim administration and platform health."
                   :eyebrow="$greeting">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('admin.search') }}">Search</a>
            <a class="btn btn-primary" href="{{ route('admin.users.create') }}">Create user</a>
        </x-slot:actions>
    </x-page-header>

    {{--
        Reference figures, deliberately quiet: one card, four columns, no icons
        and no colour. Everything here is a total that nobody has to act on.
    --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3 text-center text-sm-start">
                @foreach([
                    ['Users', number_format($stats['totalUsers']),
                        $stats['applicants'] . ' students, ' . $stats['admins'] . ' admins',
                        route('admin.users.index')],
                    ['Providers', number_format($stats['providers']),
                        'Organisations that can publish',
                        route('admin.users.index') . '?role=' . \App\Support\RoleNames::PROVIDER],
                    ['Scholarships', number_format($stats['activeOpportunities']),
                        'live of ' . number_format($stats['totalOpportunities']) . ' ever posted', null],
                    ['Applications', number_format($stats['totalApplications']),
                        number_format($stats['acceptedApplications']) . ' granted, '
                            . number_format($stats['rejectedApplications']) . ' not successful', null],
                ] as [$label, $value, $hint, $href])
                    <div class="col-6 col-lg-3">
                        <div class="sz-eyebrow">{{ $label }}</div>
                        <div class="fs-3 fw-bold lh-1 my-1 sz-tabular">
                            @if($href)
                                <a class="text-body text-decoration-none" href="{{ $href }}">{{ $value }}</a>
                            @else
                                {{ $value }}
                            @endif
                        </div>
                        <div class="small text-secondary">{{ $hint }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <h2 class="h5 fw-bold mb-3">Needs attention</h2>

    @if($waiting === 0)
        {{--
            Nothing is waiting, said once. Two cards reading zero would suggest
            two queues to work through, which is the opposite of what is true.
        --}}
        <div class="card mb-4">
            <div class="card-body d-flex gap-3 align-items-center">
                <span class="sz-stat-icon bg-success-subtle text-success rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0">
                    <x-icon name="check-circle" :size="20" />
                </span>
                <div>
                    <div class="fw-semibold">Nothing is waiting on you</div>
                    <p class="small text-secondary mb-0">
                        New provider registrations and newly submitted scholarships appear here the
                        moment they arrive.
                    </p>
                </div>
            </div>
        </div>
    @else
        <div class="row g-3 mb-4">
            @if($pendingProviders->isNotEmpty())
                <div class="col-md-6">
                    <x-stat-card label="Providers to verify" :value="$pendingProviders->count()"
                                 icon="shield-check" tone="warning"
                                 :hint="'Oldest submitted ' . $pendingProviders->first()->submitted_at?->diffForHumans()"
                                 :href="route('admin.dashboard') . '#provider-verification'" />
                </div>
            @endif

            @if($moderationQueue->isNotEmpty())
                <div class="col-md-6">
                    <x-stat-card label="Scholarships to review" :value="$moderationQueue->count()"
                                 icon="check-circle" tone="warning"
                                 :hint="'Oldest submitted ' . $moderationQueue->first()->submitted_at?->diffForHumans()"
                                 :href="route('admin.dashboard') . '#scholarship-moderation'" />
                </div>
            @endif
        </div>
    @endif

    {{--
        Applications awaiting a decision are not in the attention area on
        purpose. Every one of those decisions belongs to the provider who posted
        the listing; the state machine gives an administrator no part in it. Put
        here it would read as an administrator's backlog, which it is not - the
        count stays in the overview strip above, where it is a fact about the
        platform rather than a task.
    --}}

    <div class="card mb-4" id="provider-verification">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <h3 class="h6 fw-semibold mb-0">
                Providers awaiting verification
                @if($pendingProviders->isNotEmpty())
                    <span class="badge rounded-pill bg-warning-subtle text-warning ms-1">{{ $pendingProviders->count() }}</span>
                @endif
            </h3>
            <a class="small text-decoration-none" href="{{ route('admin.users.index') }}">All users</a>
        </div>

        <x-data-table :columns="[
                          'Organisation',
                          'Type',
                          'Submitted',
                          'Status',
                          ['label' => 'Decision', 'align' => 'end'],
                      ]"
                      :hover="false"
                      :empty="$pendingProviders->isEmpty()"
                      empty-title="No organisation is waiting on verification"
                      empty-message="A provider who registers has to be verified before they can publish anything, and they appear here as soon as they sign up."
                      empty-icon="shield-check"
                      empty-level="h4">
            @foreach($pendingProviders as $profile)
                <tr>
                    <x-data-table.cell label="Organisation">
                        <span class="fw-semibold d-block">{{ $profile->user?->displayName() }}</span>
                        <span class="small text-secondary d-block">Reg. {{ $profile->registration_number }}</span>
                        @if($profile->certificate_filename)
                            <a class="small text-decoration-none d-inline-flex align-items-center gap-1 mt-1"
                               href="{{ route('admin.providers.certificate', $profile->user_id) }}"
                               target="_blank" rel="noopener">
                                <x-icon name="eye" :size="14" />{{ $profile->certificate_filename }}
                            </a>
                        @endif
                    </x-data-table.cell>

                    <x-data-table.cell label="Type" class="small">{{ $profile->organisationTypeLabel() }}</x-data-table.cell>

                    <x-data-table.cell label="Submitted" class="small text-secondary">
                        {{ $profile->submitted_at?->format('d M Y') ?? 'Not recorded' }}
                        @if($profile->submitted_at)
                            <span class="d-block">{{ $profile->submitted_at->diffForHumans() }}</span>
                        @endif
                    </x-data-table.cell>

                    <x-data-table.cell label="Status">
                        <x-status-badge label="Awaiting review" tone="warning" />
                    </x-data-table.cell>

                    <x-data-table.cell label="Decision" align="end">
                        <div class="d-inline-flex flex-wrap gap-2 justify-content-end">
                            {{--
                                Approving hands an outside organisation the right
                                to publish on the platform, and tells them so by
                                email. Worth a sentence before the click.
                            --}}
                            <x-confirm-dialog :id="'verify-provider-' . $profile->user_id"
                                              :action="route('admin.providers.approve', $profile->user_id)"
                                              :title="'Verify ' . $profile->user?->displayName() . '?'"
                                              trigger-label="Approve"
                                              trigger-class="btn btn-sm btn-success"
                                              confirm-label="Verify provider"
                                              tone="success"
                                              message="They can publish scholarships from that moment, and every listing they post still comes here for review before it goes public. Check the registration certificate first." />

                            <x-confirm-dialog :id="'reject-provider-' . $profile->user_id"
                                              :action="route('admin.providers.reject', $profile->user_id)"
                                              :title="'Reject ' . $profile->user?->displayName() . '?'"
                                              trigger-label="Reject"
                                              trigger-class="btn btn-sm btn-outline-danger"
                                              confirm-label="Reject provider"
                                              message="The organisation is told it cannot publish, and sees your reason word for word.">
                                <x-form.textarea name="reason" :id="'provider-reason-' . $profile->user_id"
                                                 label="Reason (sent to the organisation)" :rows="4" required />
                            </x-confirm-dialog>
                        </div>
                    </x-data-table.cell>
                </tr>
            @endforeach
        </x-data-table>
    </div>

    <div class="card mb-4" id="scholarship-moderation">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <h3 class="h6 fw-semibold mb-0">
                Scholarships awaiting review
                @if($moderationQueue->isNotEmpty())
                    <span class="badge rounded-pill bg-warning-subtle text-warning ms-1">{{ $moderationQueue->count() }}</span>
                @endif
            </h3>
        </div>

        <x-data-table data-bulk-table data-bulk-form="bulk-moderation-form"
                      :columns="[
                          ['label' => 'Select', 'hidden' => true, 'width' => '2.5rem'],
                          'Scholarship',
                          'Provider',
                          'Submitted',
                          'Status',
                          ['label' => 'Decision', 'align' => 'end'],
                      ]"
                      :hover="false"
                      :empty="$moderationQueue->isEmpty()"
                      empty-title="Nothing is waiting for review"
                      empty-message="A scholarship arrives here the moment a provider submits it, and stays unpublished until it is approved."
                      empty-icon="check-circle"
                      empty-level="h4">
            @foreach($moderationQueue as $opportunity)
                <tr>
                    <x-data-table.cell>
                        {{--
                            Bound to the bulk form below by id: each row already
                            contains its own decision forms, and a form inside a
                            form is dropped by the browser.
                        --}}
                        <input class="form-check-input" type="checkbox"
                               form="bulk-moderation-form"
                               name="opportunities[]" value="{{ $opportunity->opportunity_id }}"
                               data-bulk-item
                               aria-label="Select {{ $opportunity->title }}">
                    </x-data-table.cell>

                    <x-data-table.cell label="Scholarship">
                        <a class="fw-semibold d-block text-body text-decoration-none"
                           href="{{ route('admin.moderation.show', $opportunity->opportunity_id) }}">
                            {{ $opportunity->title }}
                        </a>
                        <span class="small text-secondary d-block">
                            {{ $opportunity->education_level ? \App\Support\EducationLevel::label($opportunity->education_level) : 'Any level' }} &middot;
                            {{ $opportunity->target_field ?: 'Any field' }} &middot;
                            closes {{ $opportunity->deadline?->format('d M Y') ?? 'rolling' }}
                        </span>

                        @if(($opportunity->duplicate_candidates ?? collect())->isNotEmpty())
                            {{-- A prompt to look, never an automatic refusal. --}}
                            <a class="badge rounded-pill bg-warning-subtle text-warning text-decoration-none mt-1 d-inline-flex align-items-center gap-1"
                               href="{{ route('admin.moderation.show', $opportunity->opportunity_id) }}">
                                <x-icon name="shield" :size="12" />
                                {{ $opportunity->duplicate_candidates->count() }} possible duplicate(s)
                            </a>
                        @endif
                    </x-data-table.cell>

                    <x-data-table.cell label="Provider" class="small text-secondary">
                        {{ $opportunity->awardingBody() }}
                    </x-data-table.cell>

                    <x-data-table.cell label="Submitted" class="small text-secondary">
                        {{ $opportunity->submitted_at?->format('d M Y') ?? 'Not recorded' }}
                        @if($opportunity->submitted_at)
                            <span class="d-block">{{ $opportunity->submitted_at->diffForHumans() }}</span>
                        @endif
                    </x-data-table.cell>

                    <x-data-table.cell label="Status">
                        <x-status-badge :label="$opportunity->lifecycleLabel()" :tone="$opportunity->lifecycleTone()" />
                    </x-data-table.cell>

                    <x-data-table.cell label="Decision" align="end">
                        <div class="d-inline-flex flex-wrap gap-2 justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('admin.moderation.show', $opportunity->opportunity_id) }}">
                                Review
                            </a>

                            {{--
                                Publishing is a broadcast: the provider is told,
                                and every applicant the listing matches is told.
                                The same dialog is on the review screen, so the
                                two ways of approving one listing say the same
                                thing about what approving does.
                            --}}
                            <x-confirm-dialog :id="'publish-queue-' . $opportunity->opportunity_id"
                                              :action="route('admin.moderation.approve', $opportunity->opportunity_id)"
                                              :title="'Publish: ' . $opportunity->title"
                                              trigger-label="Approve"
                                              trigger-class="btn btn-sm btn-success"
                                              confirm-label="Publish listing"
                                              tone="success"
                                              message="This puts the listing on the public site straight away, tells the provider it is live, and announces it to applicants it matches. Announcements cannot be recalled." />

                            <x-confirm-dialog :id="'decline-' . $opportunity->opportunity_id"
                                              :action="route('admin.moderation.reject', $opportunity->opportunity_id)"
                                              :title="'Decline: ' . $opportunity->title"
                                              trigger-label="Decline"
                                              confirm-label="Decline listing"
                                              message="The listing stays unpublished and the provider is notified. They see your reason word for word.">
                                <x-form.textarea name="reason" :id="'reason-' . $opportunity->opportunity_id"
                                                 label="Reason (sent to the provider)" :rows="4" required />
                            </x-confirm-dialog>
                        </div>
                    </x-data-table.cell>
                </tr>
            @endforeach
        </x-data-table>

        @if($moderationQueue->isNotEmpty())
            <div class="card-footer">
                <form method="POST" action="{{ route('admin.moderation.bulk') }}" id="bulk-moderation-form">
                    @csrf

                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-lg-3">
                            <label class="form-label small" for="bulk-decision">Decision</label>
                            <select class="form-select form-select-sm" id="bulk-decision" name="decision">
                                <option value="approve">Approve and publish</option>
                                <option value="reject">Decline</option>
                            </select>
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label small" for="bulk-mod-reason">Reason (declines only)</label>
                            <input type="text" class="form-control form-control-sm" id="bulk-mod-reason"
                                   name="reason" maxlength="500"
                                   placeholder="Shown to the provider verbatim">
                        </div>
                        <div class="col-12 col-lg-3 d-grid">
                            <button class="btn btn-sm btn-primary" type="submit">
                                Apply to <span data-bulk-count>0</span> selected
                            </button>
                        </div>
                    </div>

                    <p class="form-text mb-0 mt-2">
                        Each listing in a batch goes through the same checks as a single decision: providers are
                        notified, applicants are announced to on approval, and every action is audited.
                    </p>
                </form>
            </div>
        @endif
    </div>

    <h2 class="h5 fw-bold mb-3">Recent activity</h2>

    <div class="card mb-4">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <h3 class="h6 fw-semibold mb-0">The last {{ $recentActivity->count() }} recorded events</h3>
            <a class="small text-decoration-none" href="{{ route('admin.audit') }}">Full audit log</a>
        </div>

        @if($recentActivity->isEmpty())
            <x-empty-state title="Nothing recorded yet"
                           message="Sign-ins, decisions, and changes to any record are written to the audit log as they happen."
                           icon="shield" level="h4" />
        @else
            <ul class="list-group list-group-flush">
                @foreach($recentActivity as $entry)
                    <li class="list-group-item d-flex flex-wrap gap-2 align-items-start">
                        <x-status-badge :label="\App\Support\AuditAction::displayLabel($entry->action)"
                                        :tone="\App\Support\AuditAction::badgeTone($entry->action)" />
                        <div class="min-w-0 flex-grow-1">
                            {{--
                                Only when there is something to say. Most entries
                                carry a sentence; a sign-in carries none, and the
                                badge and the line below already name the action,
                                the actor and the record - so a placeholder here
                                just repeated "nothing" down the whole card.
                            --}}
                            @if($entry->details)
                                <span class="small d-block sz-clamp-2">{{ $entry->details }}</span>
                            @endif
                            <span class="small text-secondary">
                                {{ $entry->actor_email ?: 'System' }} &middot;
                                <x-audit-entity :type="$entry->entity_type" :id="$entry->entity_id" /> &middot;
                                {{ $entry->created_at?->diffForHumans() }}
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <h2 class="h5 fw-bold mb-3">Platform trends</h2>

    {{--
        A link rather than a copy of the charts. The twelve-month series live on
        the analytics page and are queried there; rendering them here as well
        would run the same three aggregations again on a page whose job is the
        queues above it.
    --}}
    <div class="card">
        <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div class="min-w-0">
                <div class="fw-semibold">Twelve months of sign-ups, listings and applications</div>
                <p class="small text-secondary mb-0">
                    Where the platform is growing, which fields of study are being funded, and how
                    applications have been decided.
                </p>
            </div>
            <a class="btn btn-outline-secondary" href="{{ route('admin.analytics') }}">Open analytics</a>
        </div>
    </div>

@endsection
