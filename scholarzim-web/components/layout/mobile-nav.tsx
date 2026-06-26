'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Home, Search, GraduationCap } from 'lucide-react';
import { cn, appUrl } from '@/lib/utils';

const items = [
  { href: '/', label: 'Home', icon: Home },
  { href: '/scholarships', label: 'Browse', icon: Search },
  { href: appUrl('/login'), label: 'Account', icon: GraduationCap, external: true },
];

export function MobileNav() {
  const pathname = usePathname();

  return (
    <nav className="fixed bottom-0 left-0 right-0 z-50 flex justify-around border-t border-slate-200 bg-white/95 px-2 pb-[env(safe-area-inset-bottom)] pt-2 backdrop-blur md:hidden">
      {items.map(({ href, label, icon: Icon, external }) => {
        const active = !external && pathname === href;
        const className = cn(
          'flex min-h-11 min-w-11 flex-col items-center justify-center gap-0.5 text-[10px] font-medium',
          active ? 'text-brand' : 'text-slate-500'
        );
        return external ? (
          <a key={href} href={href} className={className}>
            <Icon className="h-5 w-5" />
            {label}
          </a>
        ) : (
          <Link key={href} href={href} className={className}>
            <Icon className="h-5 w-5" />
            {label}
          </Link>
        );
      })}
    </nav>
  );
}
