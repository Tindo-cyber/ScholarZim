import type { AnchorHTMLAttributes, ButtonHTMLAttributes, ReactNode } from 'react';
import { Link } from 'react-router-dom';

export type ButtonVariant = 'primary' | 'ghost' | 'danger';
export type ButtonSize = 'sm' | 'md';

interface Styling {
  variant?: ButtonVariant;
  size?: ButtonSize;
  block?: boolean;
  className?: string;
}

function buttonClass({ variant = 'primary', size = 'md', block, className }: Styling) {
  return [
    'sz-btn',
    variant !== 'primary' && `sz-btn--${variant}`,
    size !== 'md' && `sz-btn--${size}`,
    block && 'sz-btn--block',
    className,
  ]
    .filter(Boolean)
    .join(' ');
}

type ButtonProps = Styling &
  ButtonHTMLAttributes<HTMLButtonElement> & { children: ReactNode };

/** A real button — use for actions that stay on the page. */
export function Button({ variant, size, block, className, children, ...rest }: ButtonProps) {
  return (
    <button className={buttonClass({ variant, size, block, className })} {...rest}>
      {children}
    </button>
  );
}

type LinkButtonProps = Styling & { to: string; children: ReactNode };

/** Button-styled router link — navigates inside the SPA. */
export function LinkButton({ to, variant, size, block, className, children }: LinkButtonProps) {
  return (
    <Link to={to} className={buttonClass({ variant, size, block, className })}>
      {children}
    </Link>
  );
}

type ExternalLinkButtonProps = Styling &
  AnchorHTMLAttributes<HTMLAnchorElement> & { href: string; children: ReactNode };

/**
 * Button-styled plain anchor — a full page load that LEAVES the React app.
 * Use this for anything still served by Thymeleaf (login, the apply wizard,
 * report downloads). Kept separate from LinkButton on purpose: routing a
 * not-yet-ported path through React Router renders a 404 instead of the
 * working server page.
 */
export function ExternalLinkButton({
  href,
  variant,
  size,
  block,
  className,
  children,
  ...rest
}: ExternalLinkButtonProps) {
  return (
    <a href={href} className={buttonClass({ variant, size, block, className })} {...rest}>
      {children}
    </a>
  );
}
