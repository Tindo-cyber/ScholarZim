import { ExternalLinkButton } from '@/components/ui';

/**
 * Marks a view whose React shell exists but whose data still lives behind a
 * Thymeleaf controller with no REST equivalent yet.
 *
 * This is deliberately loud rather than a silent empty state: the page is not
 * finished, and it should not look finished. It also links straight to the
 * working Thymeleaf page so nothing is actually unreachable mid-migration.
 */
export default function PendingEndpoint({
  endpoint,
  thymeleafPath,
  description,
}: {
  /** REST endpoint that needs to exist before this view can load data. */
  endpoint: string;
  /** The server-rendered page still serving this feature today. */
  thymeleafPath: string;
  description: string;
}) {
  return (
    <div className="sz-pending">
      <p className="sz-pending__label">Not ported yet</p>
      <p>{description}</p>
      <p className="sz-card__meta">
        Needs <code>{endpoint}</code> on the backend.
      </p>
      {/* Plain anchor: this path is still served by Thymeleaf, so it must be a
          full page load rather than a React Router navigation. */}
      <ExternalLinkButton href={thymeleafPath} variant="ghost">
        Open the current page
      </ExternalLinkButton>
    </div>
  );
}
