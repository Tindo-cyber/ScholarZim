import { useId } from 'react';
import type { ReactNode } from 'react';

/**
 * A titled block within a page. Labels itself via aria-labelledby so the
 * section is announced with its heading rather than as an anonymous region.
 */
export default function Section({
  title,
  action,
  children,
}: {
  title: string;
  action?: ReactNode;
  children: ReactNode;
}) {
  const headingId = useId();

  return (
    <section className="sz-section" aria-labelledby={headingId}>
      <div className="sz-section__head">
        <h2 id={headingId} className="sz-page-title sz-section__title">
          {title}
        </h2>
        {action}
      </div>
      {children}
    </section>
  );
}
