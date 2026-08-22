<footer class="border-top px-3 px-lg-4 py-3 mt-auto">
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center small text-secondary">
        <span>&copy; {{ date('Y') }} ScholarZim</span>
        <span class="d-flex gap-3">
            <a class="link-secondary text-decoration-none" href="{{ route('scholarships.index') }}">Public listings</a>
            <a class="link-secondary text-decoration-none" href="{{ route('account.security') }}">Privacy</a>
        </span>
    </div>
</footer>
