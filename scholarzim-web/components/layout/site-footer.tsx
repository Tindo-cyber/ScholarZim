import Link from 'next/link';
import { appUrl } from '@/lib/utils';

export function SiteFooter() {
  return (
    <footer className="border-t border-slate-200 bg-white">
      <div className="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-10 md:flex-row md:items-center md:justify-between">
        <div>
          <p className="font-display text-lg font-bold text-brand">ScholarZim</p>
          <p className="mt-1 text-sm text-slate-500">Empowering Zimbabwean students to find funding.</p>
        </div>
        <div className="flex flex-wrap gap-4 text-sm text-slate-600">
          <Link href="/scholarships" className="hover:text-brand">Scholarships</Link>
          <a href={appUrl('/register/provider')} className="hover:text-brand">For providers</a>
          <a href={appUrl('/login')} className="hover:text-brand">Sign in</a>
        </div>
      </div>
      <div className="border-t border-slate-100 py-4 text-center text-xs text-slate-400">
        © {new Date().getFullYear()} ScholarZim
      </div>
    </footer>
  );
}
