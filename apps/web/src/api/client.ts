import type {
  AuthUser,
  DirectoryDriver,
  DriverFeedResponse,
  DriverStatus,
  Estimate,
  LocationsResponse,
  Ride,
  RideCategory,
  RiderSummary,
} from './types';

const BASE_URL = import.meta.env.VITE_API_URL ?? '/api';

/**
 * A typed failure carrying the API's stable error code, so the UI can branch on
 * `code` (e.g. RIDE_ALREADY_ACCEPTED) while still showing `message` verbatim.
 */
export class ApiError extends Error {
  constructor(
    readonly code: string,
    message: string,
    readonly status: number,
    readonly details?: Record<string, string[]>,
    readonly requestId?: string,
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** True when retrying the same request could plausibly succeed. */
  get isRetryable(): boolean {
    // status 0 means the request never reached the API (offline, proxy down),
    // which is exactly the case where "try again" is the right affordance.
    return (
      this.status === 0 ||
      this.status >= 500 ||
      this.code === 'RATE_LIMITED' ||
      this.code === 'SERVICE_UNAVAILABLE' ||
      this.code === 'NETWORK_ERROR'
    );
  }

  /** First field-level validation message, if the server sent any. */
  get firstFieldError(): string | null {
    if (!this.details) return null;

    const [field, messages] = Object.entries(this.details)[0] ?? [];
    if (!field || !messages?.length) return null;

    return `${field.replace(/\./g, ' ')}: ${messages[0]}`;
  }
}

// ---------------------------------------------------------------------------
// Token storage helpers
// ---------------------------------------------------------------------------

const TOKEN_KEY = 'ridebook_token';

/** Persist a Sanctum token after login. */
export function saveToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token);
}

/** Remove the stored token on logout. */
export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY);
}

/** Retrieve the stored token (null if not logged in). */
export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY);
}

// ---------------------------------------------------------------------------
// Core fetch wrapper
// ---------------------------------------------------------------------------

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH';
  body?: unknown;
  /** Extra headers (merged on top of defaults + Authorization). */
  headers?: Record<string, string>;
  signal?: AbortSignal;
  /** When true, do NOT attach the stored Bearer token (used for login endpoints). */
  skipAuth?: boolean;
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const token = options.skipAuth ? null : getToken();

  const authHeader: Record<string, string> = token
    ? { Authorization: `Bearer ${token}` }
    : {};

  let response: Response;

  try {
    response = await fetch(`${BASE_URL}${path}`, {
      method: options.method ?? 'GET',
      headers: {
        Accept: 'application/json',
        ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...authHeader,
        ...options.headers,
      },
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: options.signal,
    });
  } catch (cause) {
    if (cause instanceof DOMException && cause.name === 'AbortError') throw cause;

    // A network failure (offline, DNS, proxy down) never reaches the API, so it
    // gets a synthetic code rather than surfacing a raw TypeError to the view.
    throw new ApiError('NETWORK_ERROR', "Can't reach the RideBook server. Check your connection and try again.", 0);
  }

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    const error = (payload as { error?: { code?: string; message?: string; details?: Record<string, string[]>; requestId?: string } } | null)?.error;

    throw new ApiError(
      error?.code ?? 'UNKNOWN_ERROR',
      error?.message ?? `Request failed with status ${response.status}`,
      response.status,
      error?.details,
      error?.requestId,
    );
  }

  return (payload as { data: T }).data;
}

// ---------------------------------------------------------------------------
// Public API surface
// ---------------------------------------------------------------------------

export interface CreateRideInput {
  category: RideCategory;
  pickup: { label: string; lat: number; lng: number };
  destination: { label: string; lat: number; lng: number };
}

export const rideApi = {
  // ── Public / unauthenticated ────────────────────────────────────────────

  locations: (signal?: AbortSignal) =>
    request<LocationsResponse>('/locations', { signal }),

  riders: (signal?: AbortSignal) =>
    request<RiderSummary[]>('/riders', { signal }),

  drivers: (signal?: AbortSignal) =>
    request<DirectoryDriver[]>('/drivers', { signal }),

  estimate: (
    input: {
      category: RideCategory;
      pickup: { lat: number; lng: number };
      destination: { lat: number; lng: number };
    },
    signal?: AbortSignal,
  ) => request<Estimate>('/rides/estimate', { method: 'POST', body: input, signal }),

  // ── Auth — always skipAuth so we don't accidentally send a stale token ──

  riderLogin: (phone: string) =>
    request<AuthUser>('/auth/rider/login', {
      method: 'POST',
      body: { phone },
      skipAuth: true,
    }),

  driverLogin: (phone: string) =>
    request<AuthUser>('/auth/driver/login', {
      method: 'POST',
      body: { phone },
      skipAuth: true,
    }),

  // ── Rider actions — Bearer token from localStorage ──────────────────────

  createRide: (input: CreateRideInput, idempotencyKey: string) =>
    request<Ride>('/rides', {
      method: 'POST',
      body: input,
      headers: { 'Idempotency-Key': idempotencyKey },
    }),

  getRide: (rideId: string, signal?: AbortSignal) =>
    request<Ride>(`/rides/${rideId}`, { signal }),

  cancelRide: (rideId: string, reason?: string) =>
    request<Ride>(`/rides/${rideId}/cancel`, {
      method: 'POST',
      body: { reason },
    }),

  riderActiveRide: (signal?: AbortSignal) =>
    request<{ activeRide: Ride | null }>('/rider/active-ride', { signal }),

  // ── Driver actions — Bearer token from localStorage ─────────────────────

  acceptRide: (rideId: string) =>
    request<Ride>(`/rides/${rideId}/accept`, { method: 'POST' }),

  completeRide: (rideId: string) =>
    request<Ride>(`/rides/${rideId}/complete`, { method: 'POST' }),

  driverFeed: (signal?: AbortSignal) =>
    request<DriverFeedResponse>('/driver/feed', { signal }),

  updateDriverStatus: (driverId: string, status: DriverStatus, lat?: number, lng?: number) =>
    request<{ id: string; status: DriverStatus }>(`/drivers/${driverId}/status`, {
      method: 'PATCH',
      body: { status, lat, lng },
    }),
};
