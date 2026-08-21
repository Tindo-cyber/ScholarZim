import { Button } from './Button';

/** Zero-based page index, matching the backend's PageResult. */
export default function Pagination({
  page,
  totalPages,
  onChange,
}: {
  page: number;
  totalPages: number;
  onChange: (page: number) => void;
}) {
  if (totalPages <= 1) return null;

  return (
    <nav className="sz-pagination" aria-label="Pagination">
      <Button variant="ghost" onClick={() => onChange(page - 1)} disabled={page === 0}>
        Previous
      </Button>
      <span className="sz-pagination__status" aria-live="polite">
        Page {page + 1} of {totalPages}
      </span>
      <Button
        variant="ghost"
        onClick={() => onChange(page + 1)}
        disabled={page + 1 >= totalPages}
      >
        Next
      </Button>
    </nav>
  );
}
