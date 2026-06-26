import type { Metadata } from 'next';
import { SiteHeader } from '@/components/layout/site-header';
import { SiteFooter } from '@/components/layout/site-footer';
import { MobileNav } from '@/components/layout/mobile-nav';
import './globals.css';

export const metadata: Metadata = {
  title: {
    default: 'ScholarZim — Zimbabwe Scholarships',
    template: '%s | ScholarZim',
  },
  description: 'Find and apply for scholarships in Zimbabwe — matched to your profile.',
  themeColor: '#006b3f',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body className="min-h-screen pb-16 md:pb-0">
        <SiteHeader />
        <main>{children}</main>
        <SiteFooter />
        <MobileNav />
      </body>
    </html>
  );
}
