@props(['opportunity'])

@php
    use App\Support\OpportunityModerationStatus;

    $pending = OpportunityModerationStatus::isPending($opportunity->moderation_status);
@endphp

{{--
    The moderator's frame around a listing they are being asked to decide on.

    admin.moderation.show renders the public detail view, which is the right
    call - a moderator should see exactly what an applicant would see, not a
    summary of it. What was missing was everything around that: the page said
    nothing about why an administrator was looking at it, nothing about where
    the listing sat in review, and offered no way to act on it. The decision
    lived only in the dashboard queue, so reading a listing properly meant
    reading it here and then going back to a table to approve it from memory.

    The four things a review screen has to answer, in order: what is being
    reviewed, why it is here, what its status is, and what can be done about it.

    No new states and no new routes. The two forms post to the same
    admin.moderation.approve / admin.moderation.reject the queue posts to, and
    the actions are offered only while the listing is PENDING, because
    OpportunityModerationService::requirePending() refuses anything else - an
    Approve button on a decided listing would be a button that only ever
    produces an error.
--}}
<div class="card border-primary mb-4" id="moderation-decision">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <h2 class="h6 fw-semibold mb-0 d-flex align-items-center gap-2">
            <x-icon name="shield-check" :size="18" class="text-primary" />
            {{ $pending ? 'Awaiting your decision' : 'Moderation record' }}
        </h2>
        <x-status-badge :label="$opportunity->lifecycleLabel()" :tone="$opportunity->lifecycleTone()" />
    </div>

    <div class="card-body">
        <dl class="row g-2 mb-0 small">
            <dt class="col-sm-3 text-secondary fw-normal">Submitted by</dt>
            <dd class="col-sm-9 fw-semibold mb-0">{{ $opportunity->awardingBody() }}</dd>

            <dt class="col-sm-3 text-secondary fw-normal">Submitted</dt>
            <dd class="col-sm-9 mb-0">
                @if($opportunity->submitted_at)
                    {{ $opportunity->submitted_at->format('d M Y H:i') }}
                    <span class="text-secondary">({{ $opportunity->submitted_at->diffForHumans() }})</span>
                @else
                    <span class="text-secondary">Not recorded</span>
                @endif
            </dd>

            @if($opportunity->reviewed_at)
                <dt class="col-sm-3 text-secondary fw-normal">Reviewed</dt>
                <dd class="col-sm-9 mb-0">
                    {{ $opportunity->reviewed_at->format('d M Y H:i') }}
                    @if($opportunity->reviewed_by)
                        <span class="text-secondary">by {{ $opportunity->reviewed_by }}</span>
                    @endif
                </dd>
            @endif

            @if($opportunity->rejection_reason)
                <dt class="col-sm-3 text-secondary fw-normal">Reason given</dt>
                <dd class="col-sm-9 mb-0">{{ $opportunity->rejection_reason }}</dd>
            @endif
        </dl>
    </div>

    @if($pending)
        <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
            {{--
                Approving is the moment the listing becomes public and every
                matching applicant is told about it. A notification cannot be
                recalled, so the dialog says so before the click rather than the
                flash message saying so after it.
            --}}
            <x-confirm-dialog :id="'publish-' . $opportunity->opportunity_id"
                              :action="route('admin.moderation.approve', $opportunity->opportunity_id)"
                              :title="'Publish: ' . $opportunity->title"
                              trigger-label="Approve and publish"
                              trigger-class="btn btn-success"
                              trigger-icon="check-circle"
                              confirm-label="Publish listing"
                              tone="success"
                              message="This puts the listing on the public site straight away, tells the provider it is live, and announces it to applicants it matches. Announcements cannot be recalled." />

            <x-confirm-dialog :id="'decline-detail-' . $opportunity->opportunity_id"
                              :action="route('admin.moderation.reject', $opportunity->opportunity_id)"
                              :title="'Decline: ' . $opportunity->title"
                              trigger-label="Decline"
                              trigger-class="btn btn-outline-danger"
                              confirm-label="Decline listing"
                              message="The listing stays unpublished and the provider is notified. They see your reason word for word.">
                <x-form.textarea name="reason" :id="'decline-detail-reason-' . $opportunity->opportunity_id"
                                 label="Reason (sent to the provider)" :rows="4" required />
            </x-confirm-dialog>

            <a class="btn btn-outline-secondary ms-sm-auto" href="{{ route('admin.dashboard') }}#scholarship-moderation">
                Back to the queue
            </a>
        </div>
    @else
        <div class="card-footer small text-secondary">
            This listing has already been decided, so there is nothing to approve or decline here.
            <a class="text-decoration-none" href="{{ route('admin.dashboard') }}#scholarship-moderation">Open the review queue</a>
            to see what is still waiting.
        </div>
    @endif
</div>
