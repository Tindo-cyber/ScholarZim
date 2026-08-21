import type { ReactNode } from 'react';

/* Loading, empty, and error presentation. Kept together because pages should
   pick one of them, not invent their own. */

export type AlertTone = 'info' | 'success' | 'warning' | 'error';

export function Alert({ tone = 'info', children }: { tone?: AlertTone; children: ReactNode }) {
  return (
    // Errors and warnings interrupt; info and success are announced politely.
    <div
      className={`sz-alert sz-alert--${tone}`}
      role={tone === 'error' || tone === 'warning' ? 'alert' : 'status'}
    >
      {children}
    </div>
  );
}

export function Spinner({ label = 'Loading' }: { label?: string }) {
  return (
    <span className="sz-state">
      <span className="sz-spinner" aria-hidden="true" />
      <span className="sz-visually-hidden">{label}</span>
    </span>
  );
}

/** Placeholder blocks shown while a grid of cards loads. */
export function SkeletonGrid({ count = 6 }: { count?: number }) {
  return (
    <div className="sz-grid" aria-hidden="true">
      {Array.from({ length: count }, (_, index) => (
        <div key={index} className="sz-skeleton" />
      ))}
    </div>
  );
}

export function EmptyState({
  title,
  description,
  action,
}: {
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <div className="sz-state">
      <p className="sz-state__title">{title}</p>
      {description && <p>{description}</p>}
      {action}
    </div>
  );
}

export function ErrorState({
  title = 'Something went wrong',
  description = 'Please try again.',
  action,
}: {
  title?: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <div className="sz-state sz-state--error" role="alert">
      <p className="sz-state__title">{title}</p>
      <p>{description}</p>
      {action}
    </div>
  );
}
