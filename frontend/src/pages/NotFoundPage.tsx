import { EmptyState, LinkButton } from '@/components/ui';

export default function NotFoundPage() {
  return (
    <EmptyState
      title="Page not found"
      description="The page you are looking for does not exist or has moved."
      action={<LinkButton to="/">Back to home</LinkButton>}
    />
  );
}
