import { createContext, useContext, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { api } from './api';
import type { CurrentUser, Role } from './types';

const ANONYMOUS: CurrentUser = {
  authenticated: false,
  userId: null,
  fullName: null,
  email: null,
  role: null,
  superAdmin: false,
};

interface SessionValue {
  user: CurrentUser;
  loading: boolean;
  hasRole: (...roles: Role[]) => boolean;
}

const SessionContext = createContext<SessionValue>({
  user: ANONYMOUS,
  loading: true,
  hasRole: () => false,
});

/**
 * Loads /api/me once and shares it. This drives which navigation shell renders
 * and which routes are reachable — but it is convenience, not security. Every
 * endpoint re-checks authorisation server-side, so a tampered client gains
 * nothing beyond a broken-looking page.
 */
export function SessionProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<CurrentUser>(ANONYMOUS);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    api
      .me()
      .then((value) => {
        if (!cancelled) setUser(value);
      })
      .catch(() => {
        if (!cancelled) setUser(ANONYMOUS);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const hasRole = (...roles: Role[]) =>
    user.authenticated && user.role !== null && roles.includes(user.role);

  return (
    <SessionContext.Provider value={{ user, loading, hasRole }}>
      {children}
    </SessionContext.Provider>
  );
}

// eslint-disable-next-line react-refresh/only-export-components
export function useSession() {
  return useContext(SessionContext);
}
