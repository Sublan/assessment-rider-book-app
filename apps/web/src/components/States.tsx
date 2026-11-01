import type { ReactNode } from 'react';
import { ApiError } from '../api/client';

export function Spinner({ label = 'Loading' }: { label?: string }) {
  return (
    <span className="spinner" role="status" aria-label={label}>
      <span className="spinner__dot" />
      <span className="spinner__dot" />
      <span className="spinner__dot" />
    </span>
  );
}

interface ErrorBannerProps {
  error: ApiError | Error;
  title?: string;
  onRetry?: () => void;
  retrying?: boolean;
  compact?: boolean;
}

/**
 * One error surface for the whole app.
 *
 * The message comes from the API envelope (already human readable), the code is
 * shown so a bug report can quote it, and a retry is only offered when retrying
 * could actually help — a 422 will fail again no matter how many times the rider
 * taps the button.
 */
export function ErrorBanner({ error, title, onRetry, retrying = false, compact = false }: ErrorBannerProps) {
  const apiError = error instanceof ApiError ? error : null;
  const retryable = apiError === null || apiError.isRetryable;
  const fieldError = apiError?.firstFieldError ?? null;

  return (
    <div className={`alert ${compact ? 'alert--compact' : ''}`} role="alert" data-testid="error-banner">
      <div className="alert__body">
        <strong className="alert__title">{title ?? 'Something went wrong'}</strong>
        <p className="alert__message">{error.message}</p>
        {fieldError ? <p className="alert__hint">{fieldError}</p> : null}
        {apiError ? (
          <p className="alert__meta">
            {apiError.code}
            {apiError.requestId ? ` · ref ${apiError.requestId.slice(0, 8)}` : ''}
          </p>
        ) : null}
      </div>
      {onRetry && retryable ? (
        <button type="button" className="btn btn--ghost" onClick={onRetry} disabled={retrying}>
          {retrying ? 'Retrying…' : 'Try again'}
        </button>
      ) : null}
    </div>
  );
}

export function EmptyState({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="empty" data-testid="empty-state">
      <span className="empty__icon" aria-hidden="true">
        🛺
      </span>
      <p className="empty__title">{title}</p>
      {children ? <p className="empty__body">{children}</p> : null}
    </div>
  );
}

export function EstimateSkeleton() {
  return (
    <div className="estimate estimate--loading" aria-busy="true" data-testid="estimate-skeleton">
      <div className="skeleton skeleton--lg" />
      <div className="skeleton skeleton--sm" />
      <div className="skeleton skeleton--md" />
      <span className="sr-only">Calculating your fare…</span>
    </div>
  );
}
