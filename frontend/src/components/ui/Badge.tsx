import type { ReactNode } from 'react';

export type BadgeTone = 'success' | 'neutral' | 'info' | 'warning' | 'danger';

/** Maps the backend's status strings onto a tone, so status colour is decided once. */
export function toneForStatus(status: string | null | undefined): BadgeTone {
  switch (status?.toUpperCase()) {
    case 'ACTIVE':
    case 'APPROVED':
    case 'ACCEPTED':
      return 'success';
    case 'PENDING':
    case 'UNDER_REVIEW':
    case 'SUBMITTED':
      return 'warning';
    case 'REJECTED':
    case 'CLOSED':
    case 'EXPIRED':
    case 'SUSPENDED':
      return 'danger';
    default:
      return 'neutral';
  }
}

export default function Badge({
  children,
  tone = 'success',
}: {
  children: ReactNode;
  tone?: BadgeTone;
}) {
  return (
    <span className={['sz-badge', tone !== 'success' && `sz-badge--${tone}`].filter(Boolean).join(' ')}>
      {children}
    </span>
  );
}
