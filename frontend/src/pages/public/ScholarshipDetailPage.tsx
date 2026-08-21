import { Link, useParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { useAsync } from '@/lib/useAsync';
import { formatDate } from '@/lib/format';
import PageHeader from '@/components/layout/PageHeader';
import Section from '@/components/layout/Section';
import AsyncBoundary from '@/components/AsyncBoundary';
import {
  Badge,
  Card,
  DescriptionList,
  ExternalLinkButton,
  LinkButton,
  toneForStatus,
} from '@/components/ui';

export default function ScholarshipDetailPage() {
  const { id } = useParams<{ id: string }>();
  const state = useAsync(() => api.scholarship(id!), [id]);

  return (
    <AsyncBoundary
      state={state}
      errorTitle="This scholarship could not be found"
      errorDescription="It may have been withdrawn or the link may be out of date."
    >
      {(scholarship) => (
        <article>
          <p>
            <Link to="/scholarships">&larr; All scholarships</Link>
          </p>

          <PageHeader
            title={scholarship.title}
            lead={scholarship.providerName}
            actions={
              /* The application wizard is still Thymeleaf, so this leaves the SPA. */
              <ExternalLinkButton href={`/apply/${scholarship.id}`}>
                Apply for this scholarship
              </ExternalLinkButton>
            }
          />

          <Card>
            <DescriptionList
              items={[
                { label: 'Education level', value: scholarship.educationLevel || '—' },
                { label: 'Field of study', value: scholarship.targetField || 'Any' },
                { label: 'Funding', value: scholarship.fundingType || '—' },
                { label: 'Country', value: scholarship.country || '—' },
                { label: 'Deadline', value: formatDate(scholarship.deadline) },
                {
                  label: 'Status',
                  value: (
                    <Badge tone={toneForStatus(scholarship.status)}>{scholarship.status}</Badge>
                  ),
                },
              ]}
            />
          </Card>

          <Section title="About this opportunity">
            {/* Descriptions are plain text from the provider form — rendering as
                text (not HTML) is what keeps this safe from stored XSS. */}
            <p style={{ whiteSpace: 'pre-wrap' }}>{scholarship.description}</p>
          </Section>

          <LinkButton to="/scholarships" variant="ghost">
            Back to scholarships
          </LinkButton>
        </article>
      )}
    </AsyncBoundary>
  );
}
