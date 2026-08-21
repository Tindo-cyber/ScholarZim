import type { ReactNode } from 'react';

export interface Detail {
  label: string;
  value: ReactNode;
}

/** Label/value pairs for detail views, laid out as a responsive grid. */
export default function DescriptionList({ items }: { items: Detail[] }) {
  return (
    <dl className="sz-details">
      {items.map((item) => (
        <div key={item.label}>
          <dt>{item.label}</dt>
          <dd>{item.value}</dd>
        </div>
      ))}
    </dl>
  );
}
