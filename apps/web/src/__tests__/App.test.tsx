import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { Estimate, LocationsResponse, Ride, RiderSummary } from '../api/types';

const LOCATIONS: LocationsResponse = {
  city: 'Lahore',
  bounds: { min_lat: 31.2, max_lat: 31.75, min_lng: 74.05, max_lng: 74.6 },
  locations: [
    { id: 'gulberg-iii', label: 'Gulberg III, Main Boulevard', area: 'Gulberg', lat: 31.5204, lng: 74.344 },
    { id: 'dha-phase-5', label: 'DHA Phase 5, Khayaban-e-Bukhari', area: 'DHA', lat: 31.4697, lng: 74.4123 },
    { id: 'johar-town', label: 'Johar Town, G Block', area: 'Johar Town', lat: 31.4697, lng: 74.2728 },
  ],
};

const RIDERS: RiderSummary[] = [{ id: 'rider-1', fullName: 'Ayesha Malik', phone: '+923001234501' }];

const DRIVERS = [
  {
    id: 'driver-1',
    fullName: 'Ali Raza',
    vehicleCategory: 'economy' as const,
    vehicleModel: 'Suzuki Alto',
    vehiclePlate: 'LEB-1234',
    rating: 4.8,
    status: 'available' as const,
    city: 'Lahore',
    phone: '+923211111101',
    isActive: true,
    location: { lat: 31.52, lng: 74.345 },
  },
];

const ESTIMATE: Estimate = {
  category: 'economy',
  currency: 'PKR',
  fare: {
    totalMinor: 123395,
    totalPkr: 1233.95,
    breakdown: {
      baseMinor: 16000,
      distanceMinor: 71435,
      timeMinor: 35960,
      minimumMinor: 26000,
      surgeMultiplier: 1,
    },
  },
  distanceMeters: 10990,
  durationSeconds: 1798,
  pickupEtaMinutes: 4,
  availableDrivers: 5,
  vehicleExample: 'Suzuki Alto, Cultus or similar',
};

const RIDE: Ride = {
  id: 'ride-12345678',
  status: 'requested',
  category: 'economy',
  city: 'Lahore',
  riderId: 'rider-1',
  driver: null,
  pickup: { label: 'Gulberg III, Main Boulevard', lat: 31.5204, lng: 74.344 },
  destination: { label: 'DHA Phase 5, Khayaban-e-Bukhari', lat: 31.4697, lng: 74.4123 },
  trip: { distanceMeters: 10990, durationSeconds: 1798, pickupEtaMinutes: 4 },
  fare: ESTIMATE.fare as Ride['fare'],
  cancellable: true,
  timeline: [{ type: 'ride_requested', actorType: 'rider', at: new Date().toISOString(), metadata: {} }],
  requestedAt: new Date().toISOString(),
  acceptedAt: null,
  completedAt: null,
  cancelledAt: null,
  cancelledBy: null,
  cancellationReason: null,
  createdAt: new Date().toISOString(),
  updatedAt: new Date().toISOString(),
};

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  });
}

interface Overrides {
  estimate?: () => Promise<Response> | Response;
  createRide?: () => Promise<Response> | Response;
  getRide?: () => Promise<Response> | Response;
  cancel?: () => Promise<Response> | Response;
}

interface RecordedCall {
  url: string;
  method: string;
  headers: Record<string, string>;
}

function installFetch(overrides: Overrides = {}) {
  const calls: RecordedCall[] = [];

  const impl = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input);
    const method = (init?.method ?? 'GET').toUpperCase();
    const headers = (init?.headers ?? {}) as Record<string, string>;
    calls.push({ url, method, headers });

    if (method === 'POST' && url.endsWith('/rides/estimate')) {
      return overrides.estimate ? overrides.estimate() : json({ data: ESTIMATE });
    }

    if (method === 'POST' && url.endsWith('/rides')) {
      return overrides.createRide ? overrides.createRide() : json({ data: RIDE }, 201);
    }

    if (method === 'GET' && /\/rides\/[^/]+$/.test(url)) {
      return overrides.getRide ? overrides.getRide() : json({ data: RIDE });
    }

    if (method === 'POST' && url.endsWith('/cancel')) {
      return overrides.cancel ? overrides.cancel() : json({ data: { ...RIDE, status: 'cancelled', cancellable: false } });
    }

    if (url.endsWith('/locations')) return json({ data: LOCATIONS });
    if (url.endsWith('/riders')) return json({ data: RIDERS });
    if (url.endsWith('/drivers')) return json({ data: DRIVERS });

    throw new Error(`Unhandled request: ${method} ${url}`);
  });

  vi.stubGlobal('fetch', impl);

  return { calls };
}

async function renderBookedApp() {
  render(<App />);

  // The form is ready once the first quote has been rendered.
  await screen.findByTestId('fare-total');
}

describe('rider booking flow', () => {
  beforeEach(() => {
    installFetch();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('shows a loading state while the fare is being calculated', async () => {
    installFetch({ estimate: () => new Promise<Response>(() => {}) });

    render(<App />);

    expect(await screen.findByTestId('estimate-skeleton')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /request ride/i })).toBeDisabled();
  });

  it('renders the fare and pickup ETA once the quote arrives', async () => {
    await renderBookedApp();

    expect(screen.getByTestId('fare-total')).toHaveTextContent('PKR 1,234');
    expect(screen.getByTestId('pickup-eta')).toHaveTextContent('4 min');
    expect(screen.getByTestId('drivers-nearby')).toHaveTextContent('5');
    expect(screen.getByRole('button', { name: /request ride/i })).toBeEnabled();
  });

  it('shows an error state and recovers when the rider retries', async () => {
    const user = userEvent.setup();
    let attempts = 0;

    installFetch({
      estimate: () => {
        attempts += 1;

        return attempts === 1
          ? json({ error: { code: 'SERVICE_UNAVAILABLE', message: 'Pricing is temporarily unavailable' } }, 503)
          : json({ data: ESTIMATE });
      },
    });

    render(<App />);

    const banner = await screen.findByTestId('error-banner');
    expect(banner).toHaveTextContent(/pricing is temporarily unavailable/i);
    expect(screen.getByRole('button', { name: /request ride/i })).toBeDisabled();

    await user.click(screen.getByRole('button', { name: /try again/i }));

    await waitFor(() => expect(screen.getByTestId('fare-total')).toBeInTheDocument());
    expect(screen.queryByTestId('error-banner')).not.toBeInTheDocument();
    expect(attempts).toBe(2);
  });

  it('shows an empty state when no drivers are nearby', async () => {
    installFetch({ estimate: () => json({ data: { ...ESTIMATE, availableDrivers: 0, pickupEtaMinutes: null } }) });

    render(<App />);

    // The idle "choose a destination" placeholder is replaced by the quote, which
    // then reports that nobody is available right now.
    expect(await screen.findByText(/no drivers in this area/i)).toBeInTheDocument();
    expect(screen.getByTestId('drivers-nearby')).toHaveTextContent('0');
  });

  it('requests a ride with an idempotency key and shows the status view', async () => {
    const user = userEvent.setup();
    const { calls } = installFetch();

    render(<App />);
    await screen.findByTestId('fare-total');

    await user.click(screen.getByRole('button', { name: /request ride/i }));

    expect(await screen.findByTestId('ride-status')).toHaveTextContent('requested');
    expect(screen.getByTestId('ride-fare')).toHaveTextContent('PKR 1,234');
    expect(screen.getByRole('heading', { name: /looking for a driver/i })).toBeInTheDocument();

    const createCall = calls.find((call) => call.method === 'POST' && call.url.endsWith('/rides'));
    expect(createCall?.headers['Idempotency-Key']).toBeTruthy();
  });

  it('keeps the form and explains the failure when the request is rejected', async () => {
    const user = userEvent.setup();

    installFetch({
      createRide: () =>
        json(
          {
            error: {
              code: 'ACTIVE_RIDE_EXISTS',
              message: 'You already have a ride in progress',
              details: { rideId: 'ride-999' },
            },
          },
          409,
        ),
    });

    render(<App />);
    await screen.findByTestId('fare-total');

    await user.click(screen.getByRole('button', { name: /request ride/i }));

    const banner = await screen.findByTestId('error-banner');
    expect(banner).toHaveTextContent(/already have a ride in progress/i);
    expect(banner).toHaveTextContent('ACTIVE_RIDE_EXISTS');
    // Nothing is lost: the form is still there and still usable.
    expect(screen.getByTestId('fare-total')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /request ride/i })).toBeEnabled();
  });

  it('cancels a ride and shows the cancelled state', async () => {
    const user = userEvent.setup();
    installFetch();

    render(<App />);
    await screen.findByTestId('fare-total');
    await user.click(screen.getByRole('button', { name: /request ride/i }));
    await screen.findByTestId('ride-status');

    await user.type(screen.getByLabelText(/reason for cancelling/i), 'Changed plans');
    await user.click(screen.getByRole('button', { name: /cancel ride/i }));

    await waitFor(() => expect(screen.getByTestId('ride-status')).toHaveTextContent('cancelled'));
    expect(screen.getByRole('button', { name: /book another ride/i })).toBeInTheDocument();
  });
});

describe('bootstrap', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('offers a retry when the API cannot be reached at all', async () => {
    const user = userEvent.setup();
    let attempts = 0;

    installFetch();

    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        attempts += 1;

        if (attempts <= 2) throw new TypeError('Failed to fetch');

        const url = String(input);
        if (url.endsWith('/locations')) return json({ data: LOCATIONS });
        if (url.endsWith('/riders')) return json({ data: RIDERS });
        if (url.endsWith('/drivers')) return json({ data: DRIVERS });
        return json({ data: ESTIMATE });
      }),
    );

    render(<App />);

    const banner = await screen.findByTestId('error-banner');
    expect(banner).toHaveTextContent(/can't reach the ridebook server/i);

    await user.click(screen.getByRole('button', { name: /try again/i }));

    await waitFor(() => expect(screen.getByTestId('fare-total')).toBeInTheDocument());
  });
});
