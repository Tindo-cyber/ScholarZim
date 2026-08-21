import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';

export function Card({
  children,
  className,
}: {
  children: ReactNode;
  className?: string;
}) {
  return <article className={['sz-card', className].filter(Boolean).join(' ')}>{children}</article>;
}

/** Card heading; pass `to` to make it a router link to the detail view. */
export function CardTitle({ children, to }: { children: ReactNode; to?: string }) {
  return (
    <h3 className="sz-card__title">{to ? <Link to={to}>{children}</Link> : children}</h3>
  );
}

export function CardMeta({ children }: { children: ReactNode }) {
  return <p className="sz-card__meta">{children}</p>;
}

/** Pinned to the bottom of the card, so cards in a row align regardless of body length. */
export function CardFooter({ children }: { children: ReactNode }) {
  return <div className="sz-card__footer">{children}</div>;
}
