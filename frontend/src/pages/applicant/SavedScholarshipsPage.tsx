import { useCallback, useState } from 'react';
import { api } from '@/lib/api';
import { useAsync } from '@/lib/useAsync';
import PageHeader from '@/components/layout/PageHeader';
import AsyncBoundary from '@/components/AsyncBoundary';
import ScholarshipCard from '@/components/ScholarshipCard';
import { Alert, Button, EmptyState, LinkButton } from '@/components/ui';

/** Backed by GET /api/applicant/saved and DELETE /api/applicant/saved/{id}. */
export default function SavedScholarshipsPage() {
  // Bumping this refetches after a removal, so the list cannot drift from the
  // server's view of what is saved.
  const [reloadKey, setReloadKey] = useState(0);
  const [removing, setRemoving] = useState<number | null>(null);
  const [removeError, setRemoveError] = useState<string | null>(null);

  const state = useAsync(() => api.saved(), [reloadKey]);

  const remove = useCallback(async (id: number) => {
    setRemoving(id);
    setRemoveError(null);
    try {
      await api.unsave(id);
      setReloadKey((key) => key + 1);
    } catch {
      setRemoveError('Could not remove that scholarship. Please try again.');
    } finally {
      setRemoving(null);
    }
  }, []);

  return (
    <>
      <PageHeader title="Saved scholarships" lead="Opportunities you have bookmarked." />

      {removeError && <Alert tone="error">{removeError}</Alert>}

      <AsyncBoundary
        state={state}
        isEmpty={(page) => page.content.length === 0}
        errorTitle="Could not load your saved scholarships"
        skeletonCount={4}
        empty={
          <EmptyState
            title="You have not saved anything yet"
            description="Bookmark a scholarship to keep track of it here."
            action={<LinkButton to="/scholarships">Browse scholarships</LinkButton>}
          />
        }
      >
        {(page) => (
          <div className="sz-grid">
            {page.content.map((item) => (
              <ScholarshipCard
                key={item.id}
                scholarship={item}
                action={
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => remove(item.id)}
                    disabled={removing === item.id}
                  >
                    {removing === item.id ? 'Removing…' : 'Remove'}
                  </Button>
                }
              />
            ))}
          </div>
        )}
      </AsyncBoundary>
    </>
  );
}
