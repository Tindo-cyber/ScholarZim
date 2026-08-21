import { useState } from 'react';
import type { FormEvent } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { useAsync } from '@/lib/useAsync';
import { formatNumber } from '@/lib/format';
import PageHeader from '@/components/layout/PageHeader';
import AsyncBoundary from '@/components/AsyncBoundary';
import ScholarshipCard from '@/components/ScholarshipCard';
import { Button, EmptyState, Pagination } from '@/components/ui';

const PAGE_SIZE = 12;

export default function ScholarshipsPage() {
  // URL is the source of truth so filters survive refresh and can be shared.
  const [searchParams, setSearchParams] = useSearchParams();
  const keyword = searchParams.get('keyword') ?? '';
  const page = Math.max(0, Number(searchParams.get('page') ?? '0') || 0);

  const [draft, setDraft] = useState(keyword);

  const results = useAsync(
    () => api.scholarships({ keyword: keyword || undefined, page, size: PAGE_SIZE }),
    [keyword, page],
  );

  function submitSearch(event: FormEvent) {
    event.preventDefault();
    const next = new URLSearchParams();
    if (draft.trim()) next.set('keyword', draft.trim());
    setSearchParams(next);
  }

  function clearSearch() {
    setDraft('');
    setSearchParams(new URLSearchParams());
  }

  function goToPage(next: number) {
    const params = new URLSearchParams(searchParams);
    params.set('page', String(next));
    setSearchParams(params);
    window.scrollTo({ top: 0 });
  }

  const total = results.data?.totalElements ?? 0;

  return (
    <>
      <PageHeader
        title="Scholarships"
        lead={
          results.data
            ? `${formatNumber(total)} ${total === 1 ? 'opportunity' : 'opportunities'} found`
            : 'Search open scholarships and academic opportunities.'
        }
      />

      <form className="sz-search" onSubmit={submitSearch} role="search">
        <input
          className="sz-input"
          type="search"
          aria-label="Search scholarships"
          placeholder="Search by title, provider, or field of study"
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
        />
        <Button type="submit">Search</Button>
        {keyword && (
          <Button type="button" variant="ghost" onClick={clearSearch}>
            Clear
          </Button>
        )}
      </form>

      <AsyncBoundary
        state={results}
        isEmpty={(result) => result.content.length === 0}
        errorTitle="Could not load scholarships"
        empty={
          <EmptyState
            title={`No scholarships match ${keyword ? `“${keyword}”` : 'your search'}.`}
            action={
              keyword ? (
                <Button variant="ghost" onClick={clearSearch}>
                  Clear search
                </Button>
              ) : undefined
            }
          />
        }
      >
        {(result) => (
          <>
            <div className="sz-grid">
              {result.content.map((item) => (
                <ScholarshipCard key={item.id} scholarship={item} />
              ))}
            </div>
            <Pagination page={result.page} totalPages={result.totalPages} onChange={goToPage} />
          </>
        )}
      </AsyncBoundary>
    </>
  );
}
