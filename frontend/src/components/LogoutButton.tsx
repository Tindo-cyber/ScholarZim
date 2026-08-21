import { csrfToken } from '@/lib/api';
import { Button } from '@/components/ui';

/**
 * Spring Security's /logout is a CSRF-protected POST, so this is a real form
 * with a hidden _csrf field rather than a link or a fetch call.
 */
export default function LogoutButton() {
  const token = csrfToken();

  return (
    <form method="post" action="/logout">
      {token && <input type="hidden" name="_csrf" value={token} />}
      <Button type="submit" variant="ghost" size="sm">
        Sign out
      </Button>
    </form>
  );
}
