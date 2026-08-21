import type { ReactNode } from 'react';
import { Card, CardTitle, CardMeta, CardFooter, Badge } from '@/components/ui';
import type { Opportunity } from '@/lib/types';
import { formatDeadline } from '@/lib/format';

export default function ScholarshipCard({
  scholarship,
  action,
}: {
  scholarship: Opportunity;
  /** Optional trailing control, e.g. a Remove button on the saved list. */
  action?: ReactNode;
}) {
  const meta = [scholarship.educationLevel, scholarship.country].filter(Boolean).join(' · ');

  return (
    <Card>
      <CardTitle to={`/scholarships/${scholarship.id}`}>{scholarship.title}</CardTitle>
      <CardMeta>{scholarship.providerName}</CardMeta>
      {meta && <CardMeta>{meta}</CardMeta>}
      <CardFooter>
        {scholarship.fundingType ? <Badge>{scholarship.fundingType}</Badge> : <span />}
        <span className="sz-card__meta">{formatDeadline(scholarship.deadline)}</span>
      </CardFooter>
      {action}
    </Card>
  );
}
