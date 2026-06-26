'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Menu, GraduationCap } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn, appUrl } from '@/lib/utils';

const links = [
  { href: '/scholarships', label: 'Scholarships' },
];

export function SiteHeader() {
  const pathname = usePathname();
  const [open, setOpen] = useState(false);

  return (
    <header className="sticky top-0 z-50 border-b border-slate-200/80 bg-white/90 backdrop-blur-md">
      <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4">
        <Link href="/" className="flex items-center gap-2 font-display text-lg font-bold text-brand">
          <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand text-white">
            <GraduationCap className="h-5 w-5" />
          </span>
          ScholarZim
        </Link>

        <nav className="hidden items-center gap-6 md:flex">
          {links.map((link) => (
            <Link
              key={link.href}
              href={link.href}
              className={cn(
                'text-sm font-medium transition-colors hover:text-brand',
                pathname === link.href ? 'text-brand' : 'text-slate-600'
              )}
            >
              {link.label}
            </Link>
          ))}
        </nav>

        <div className="hidden items-center gap-2 md:flex">
          <Button variant="ghost" asChild>
            <a href={appUrl('/login')}>Sign in</a>
          </Button>
          <Button asChild>
            <a href={appUrl('/register')}>Register free</a>
          </Button>
        </div>

        <button
          type="button"
          className="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 md:hidden"
          onClick={() => setOpen((v) => !v)}
          aria-label="Toggle menu"
        >
          <Menu className="h-5 w-5" />
        </button>
      </div>

      {open && (
        <div className="border-t border-slate-200 bg-white px-4 py-4 md:hidden">
          <div className="flex flex-col gap-3">
            {links.map((link) => (
              <Link key={link.href} href={link.href} className="font-medium text-slate-700" onClick={() => setOpen(false)}>
                {link.label}
              </Link>
            ))}
            <a href={appUrl('/login')} className="font-medium text-slate-700">Sign in</a>
            <Button asChild className="w-full">
              <a href={appUrl('/register')}>Register free</a>
            </Button>
          </div>
        </div>
      )}
    </header>
  );
}
