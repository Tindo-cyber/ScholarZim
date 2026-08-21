import { formatNumber } from '@/lib/format';

export default function StatTile({
  value,
  label,
  hint,
}: {
  value: number | string;
  label: string;
  hint?: string;
}) {
  return (
    <div className="sz-stat">
      <span className="sz-stat__value">
        {typeof value === 'number' ? formatNumber(value) : value}
      </span>
      <span className="sz-stat__label">{label}</span>
      {hint && <p className="sz-stat__hint">{hint}</p>}
    </div>
  );
}
