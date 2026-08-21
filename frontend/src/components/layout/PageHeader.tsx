import type { ReactNode } from 'react';

/**
 * The single <h1> for a view, plus optional supporting text and actions.
 * Every page uses this so heading level and spacing stay consistent.
 */
export default function PageHeader({
  title,
  lead,
  actions,
}: {
  title: string;
  lead?: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <header className="sz-page-header">
      <div>
        <h1 className="sz-page-title">{title}</h1>
        {lead && <p className="sz-page-lead">{lead}</p>}
      </div>
      {actions && <div className="sz-page-header__actions">{actions}</div>}
    </header>
  );
}
