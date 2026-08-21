import type { ReactNode } from 'react';
import { EmptyState, ErrorState, SkeletonGrid } from '@/components/ui/Feedback';

interface AsyncState<T> {
  data: T | null;
  loading: boolean;
  error: Error | null;
}

/**
 * Renders the one correct thing for a useAsync result: skeletons while loading,
 * an error state on failure, an empty state when there is nothing to show, and
 * otherwise the content.
 *
 * Every data view needs this exact ladder, and hand-writing it is where the
 * empty case tends to get forgotten — leaving a page that looks broken when it
 * is simply empty.
 */
export default function AsyncBoundary<T>({
  state,
  isEmpty,
  empty,
  errorTitle,
  errorDescription,
  skeletonCount,
  children,
}: {
  state: AsyncState<T>;
  /** Defaults to treating an empty array as empty. */
  isEmpty?: (data: T) => boolean;
  empty?: ReactNode;
  errorTitle?: string;
  errorDescription?: string;
  skeletonCount?: number;
  children: (data: T) => ReactNode;
}) {
  if (state.loading) {
    return <SkeletonGrid count={skeletonCount} />;
  }

  if (state.error || state.data === null) {
    return <ErrorState title={errorTitle} description={errorDescription} />;
  }

  const treatAsEmpty = isEmpty
    ? isEmpty(state.data)
    : Array.isArray(state.data) && state.data.length === 0;

  if (treatAsEmpty) {
    return <>{empty ?? <EmptyState title="Nothing to show yet" />}</>;
  }

  return <>{children(state.data)}</>;
}
