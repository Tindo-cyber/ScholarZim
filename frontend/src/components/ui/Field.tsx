import { useId } from 'react';
import type {
  InputHTMLAttributes,
  ReactNode,
  SelectHTMLAttributes,
  TextareaHTMLAttributes,
} from 'react';

interface FieldShell {
  label: string;
  hint?: string;
  error?: string;
}

/**
 * Wires label, hint, and error to the control with generated ids so the
 * association is never left to hand-written markup — that is the part that
 * silently breaks screen-reader support when pages are written by hand.
 */
function useFieldIds(hint?: string, error?: string) {
  const id = useId();
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined;
  return { id, hintId, errorId, describedBy };
}

function Shell({
  label,
  hint,
  error,
  id,
  hintId,
  errorId,
  children,
}: FieldShell & {
  id: string;
  hintId?: string;
  errorId?: string;
  children: ReactNode;
}) {
  return (
    <div className="sz-field">
      <label className="sz-field__label" htmlFor={id}>
        {label}
      </label>
      {hint && (
        <span className="sz-field__hint" id={hintId}>
          {hint}
        </span>
      )}
      {children}
      {error && (
        <span className="sz-field__error" id={errorId} role="alert">
          {error}
        </span>
      )}
    </div>
  );
}

export function TextField({
  label,
  hint,
  error,
  className,
  ...rest
}: FieldShell & InputHTMLAttributes<HTMLInputElement>) {
  const { id, hintId, errorId, describedBy } = useFieldIds(hint, error);
  return (
    <Shell label={label} hint={hint} error={error} id={id} hintId={hintId} errorId={errorId}>
      <input
        id={id}
        className={['sz-input', error && 'sz-input--invalid', className].filter(Boolean).join(' ')}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        {...rest}
      />
    </Shell>
  );
}

export function TextAreaField({
  label,
  hint,
  error,
  className,
  ...rest
}: FieldShell & TextareaHTMLAttributes<HTMLTextAreaElement>) {
  const { id, hintId, errorId, describedBy } = useFieldIds(hint, error);
  return (
    <Shell label={label} hint={hint} error={error} id={id} hintId={hintId} errorId={errorId}>
      <textarea
        id={id}
        className={['sz-input', error && 'sz-input--invalid', className].filter(Boolean).join(' ')}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        {...rest}
      />
    </Shell>
  );
}

export function SelectField({
  label,
  hint,
  error,
  options,
  placeholder,
  className,
  ...rest
}: FieldShell &
  SelectHTMLAttributes<HTMLSelectElement> & {
    options: { value: string; label: string }[];
    placeholder?: string;
  }) {
  const { id, hintId, errorId, describedBy } = useFieldIds(hint, error);
  return (
    <Shell label={label} hint={hint} error={error} id={id} hintId={hintId} errorId={errorId}>
      <select
        id={id}
        className={['sz-input', error && 'sz-input--invalid', className].filter(Boolean).join(' ')}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        {...rest}
      >
        {placeholder && <option value="">{placeholder}</option>}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </Shell>
  );
}
