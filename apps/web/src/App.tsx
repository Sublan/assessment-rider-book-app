import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ApiError, clearToken, rideApi, saveToken } from './api/client';
import type { AuthUser, DirectoryDriver, LahoreLocation, Ride, RideCategory, RiderSummary } from './api/types';
import { BookingForm } from './components/BookingForm';
import { DriverDashboard } from './components/DriverDashboard';
import { DriverSimulator } from './components/DriverSimulator';
import { LoginModal } from './components/LoginModal';
import { MapPlaceholder } from './components/MapPlaceholder';
import { RideStatusView } from './components/RideStatusView';
import { ErrorBanner } from './components/States';
import { useEstimate } from './hooks/useEstimate';
import { useRidePolling } from './hooks/useRidePolling';
import { newIdempotencyKey } from './lib/format';

type ActionState = { busy: boolean; error: ApiError | null };

const IDLE_ACTION: ActionState = { busy: false, error: null };

export default function App() {
  const [bootState, setBootState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [bootError, setBootError] = useState<ApiError | null>(null);
  const [bootToken, setBootToken] = useState(0);

  const [locations, setLocations] = useState<LahoreLocation[]>([]);
  const [riders, setRiders] = useState<RiderSummary[]>([]);
  const [drivers, setDrivers] = useState<DirectoryDriver[]>([]);

  // Auth state — currentUser is set after login; token stored in localStorage via saveToken()
  const [currentUser, setCurrentUser] = useState<AuthUser | null>(null);
  const [dismissedLogin, setDismissedLogin] = useState(false);
  const [showLoginModal, setShowLoginModal] = useState(false);

  const [pickupId, setPickupId] = useState('');
  const [destinationId, setDestinationId] = useState('');
  const [category, setCategory] = useState<RideCategory>('economy');

  const [activeRide, setActiveRide] = useState<Ride | null>(null);
  const [submitState, setSubmitState] = useState<ActionState>(IDLE_ACTION);
  const [cancelState, setCancelState] = useState<ActionState>(IDLE_ACTION);
  const [driverActionState, setDriverActionState] = useState<ActionState>(IDLE_ACTION);

  const idempotencyKey = useRef<string | null>(null);

  const refreshDrivers = useCallback(() => {
    rideApi
      .drivers()
      .then(setDrivers)
      .catch(() => {
        /* Simulator convenience — its failure must not break booking. */
      });
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    setBootState('loading');

    Promise.all([rideApi.locations(controller.signal), rideApi.riders(controller.signal)])
      .then(([directory, riderList]) => {
        setLocations(directory.locations);
        setRiders(riderList);
        const defaultRider = riderList[0];
        if (defaultRider) {
          setCurrentUser((current) => current ?? {
            token: 'demo-token',
            userType: 'rider',
            id: defaultRider.id,
            fullName: defaultRider.fullName,
            phone: defaultRider.phone,
          });
        }
        setPickupId((current) => current || directory.locations[0]?.id || '');
        setDestinationId(
          (current) => current || directory.locations[4]?.id || directory.locations[1]?.id || '',
        );
        setBootError(null);
        setBootState('ready');
      })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        setBootError(error as ApiError);
        setBootState('error');
      });

    rideApi
      .drivers(controller.signal)
      .then(setDrivers)
      .catch(() => undefined);

    return () => controller.abort();
  }, [bootToken]);

  // ── Login handlers — call API, store Sanctum token, update UI state ────

  const handleLoginAsRider = useCallback(
    async (phone: string) => {
      try {
        const user = await rideApi.riderLogin(phone);
        saveToken(user.token);
        setCurrentUser(user);
        setShowLoginModal(false);
        setDismissedLogin(false);
        setActiveRide(user.activeRide ?? null);
      } catch (err) {
        // LoginModal handles its own error display; re-throw for it
        throw err;
      }
    },
    [],
  );

  const handleLoginAsDriver = useCallback(async (phone: string) => {
    try {
      const user = await rideApi.driverLogin(phone);
      saveToken(user.token);
      setCurrentUser(user);
      setShowLoginModal(false);
      setDismissedLogin(false);
    } catch (err) {
      throw err;
    }
  }, []);

  const handleLogout = useCallback(() => {
    clearToken();
    setCurrentUser(null);
    setShowLoginModal(true);
    setDismissedLogin(false);
    setActiveRide(null);
    setCancelState(IDLE_ACTION);
    setSubmitState(IDLE_ACTION);
    setDriverActionState(IDLE_ACTION);
  }, []);

  const pickup = useMemo(
    () => locations.find((item) => item.id === pickupId) ?? null,
    [locations, pickupId],
  );
  const destination = useMemo(
    () => locations.find((item) => item.id === destinationId) ?? null,
    [locations, destinationId],
  );

  const estimate = useEstimate(pickup, destination, category);

  const { ride, error: pollError, setRide, refresh } = useRidePolling(activeRide);

  const updateDraft = useCallback(
    (patch: { pickupId?: string; destinationId?: string; category?: RideCategory }) => {
      idempotencyKey.current = null;
      if (patch.pickupId !== undefined) setPickupId(patch.pickupId);
      if (patch.destinationId !== undefined) setDestinationId(patch.destinationId);
      if (patch.category !== undefined) setCategory(patch.category);
    },
    [],
  );

  const swap = useCallback(() => {
    idempotencyKey.current = null;
    setPickupId(destinationId);
    setDestinationId(pickupId);
  }, [destinationId, pickupId]);

  const requestRide = useCallback(async () => {
    if (!pickup || !destination) return;

    idempotencyKey.current ??= newIdempotencyKey();
    setSubmitState({ busy: true, error: null });

    try {
      const created = await rideApi.createRide(
        {
          category,
          pickup: { label: pickup.label, lat: pickup.lat, lng: pickup.lng },
          destination: { label: destination.label, lat: destination.lat, lng: destination.lng },
        },
        idempotencyKey.current,
      );

      idempotencyKey.current = null;
      setSubmitState(IDLE_ACTION);
      setActiveRide(created);
      refreshDrivers();
    } catch (error) {
      setSubmitState({ busy: false, error: error as ApiError });
    }
  }, [category, destination, pickup, refreshDrivers]);

  const cancelRide = useCallback(
    async (reason?: string) => {
      if (!ride) return;

      setCancelState({ busy: true, error: null });
      try {
        setRide(await rideApi.cancelRide(ride.id, reason));
        setCancelState(IDLE_ACTION);
        refreshDrivers();
      } catch (error) {
        setCancelState({ busy: false, error: error as ApiError });
      }
    },
    [ride, setRide, refreshDrivers],
  );

  // DriverSimulator uses the currently-stored Bearer token automatically
  const acceptAsDriver = useCallback(
    async (_driverId: string) => {
      if (!ride) return;

      setDriverActionState({ busy: true, error: null });
      try {
        // The DriverSimulator is a demo panel on the rider view. It calls accept
        // using the driverId from the directory list — but since Sanctum auth is
        // now token-based, this panel is only for demo/QA. The real driver uses
        // DriverDashboard. We call the API without any rider/driver override here;
        // the demo panel doesn't hold a driver token, so it will get a 401 unless
        // a driver is logged in. For demo purposes we still allow the call to go
        // through (the API requires a driver token on /accept).
        setRide(await rideApi.acceptRide(ride.id));
        setDriverActionState(IDLE_ACTION);
        refreshDrivers();
      } catch (error) {
        setDriverActionState({ busy: false, error: error as ApiError });
        refreshDrivers();
        void refresh();
      }
    },
    [ride, setRide, refreshDrivers, refresh],
  );

  const completeAsDriver = useCallback(async () => {
    if (!ride) return;

    setDriverActionState({ busy: true, error: null });
    try {
      setRide(await rideApi.completeRide(ride.id));
      setDriverActionState(IDLE_ACTION);
      refreshDrivers();
    } catch (error) {
      setDriverActionState({ busy: false, error: error as ApiError });
    }
  }, [ride, setRide, refreshDrivers]);

  const bookAnother = useCallback(() => {
    setActiveRide(null);
    setCancelState(IDLE_ACTION);
    setDriverActionState(IDLE_ACTION);
    setSubmitState(IDLE_ACTION);
  }, []);

  return (
    <div className="shell">
      <header className="topbar">
        <div className="brand">
          <span className="brand__mark" aria-hidden="true">
            R
          </span>
          <div>
            <strong>RideBook</strong>
            <span className="brand__city">Lahore</span>
          </div>
        </div>

        <div className="identity">
          {currentUser ? (
            <div className="current-user-badge">
              <span className="user-icon">{currentUser.userType === 'rider' ? '🧑' : '🚗'}</span>
              <div className="user-details">
                <span className="user-name">{currentUser.fullName}</span>
                <span className="user-role-label">Logged in as {currentUser.userType.toUpperCase()}</span>
              </div>
              <button
                type="button"
                className="btn btn-sm btn-outline switch-btn"
                onClick={handleLogout}
              >
                Switch Account / Portal
              </button>
            </div>
          ) : (
            <button
              type="button"
              className="btn btn-sm btn-primary"
              onClick={() => {
                setDismissedLogin(false);
                setShowLoginModal(true);
              }}
            >
              Sign In
            </button>
          )}
        </div>
      </header>

      {((!currentUser && !dismissedLogin) || showLoginModal) && bootState === 'ready' && (
        <LoginModal
          riders={riders}
          drivers={drivers}
          onLoginAsRider={handleLoginAsRider}
          onLoginAsDriver={handleLoginAsDriver}
          onClose={() => {
            setShowLoginModal(false);
            setDismissedLogin(true);
          }}
        />
      )}

      {currentUser?.userType === 'driver' && bootState === 'ready' && (
        <DriverDashboard user={currentUser} onLogout={handleLogout} />
      )}

      {bootState === 'loading' ? (
        <main className="layout layout--single">
          <div className="card">
            <div className="skeleton skeleton--lg" />
            <div className="skeleton skeleton--md" />
            <div className="skeleton skeleton--md" />
            <div className="skeleton skeleton--sm" />
          </div>
        </main>
      ) : null}

      {bootState === 'error' && bootError ? (
        <main className="layout layout--single">
          <div className="card">
            <ErrorBanner
              error={bootError}
              title="RideBook is not reachable"
              onRetry={() => setBootToken((token) => token + 1)}
            />
          </div>
        </main>
      ) : null}

      {bootState === 'ready' && (!currentUser || currentUser.userType === 'rider') ? (
        <main className="layout">
          <div className="card card--main">
            {ride ? (
              <RideStatusView
                ride={ride}
                cancelling={cancelState.busy}
                cancelError={cancelState.error}
                onCancel={cancelRide}
                onBookAnother={bookAnother}
                pollError={pollError}
                onRetryPoll={() => void refresh()}
              />
            ) : (
              <BookingForm
                locations={locations}
                pickupId={pickupId}
                destinationId={destinationId}
                category={category}
                estimate={estimate}
                submitting={submitState.busy}
                submitError={submitState.error}
                onChange={updateDraft}
                onSwap={swap}
                onSubmit={() => void requestRide()}
              />
            )}
          </div>

          <aside className="sidebar">
            <MapPlaceholder
              pickup={ride?.pickup ?? pickup}
              destination={ride?.destination ?? destination}
              driverName={ride?.driver?.fullName ?? null}
              ride={ride}
            />

            {ride ? (
              <DriverSimulator
                drivers={drivers}
                ride={ride}
                busy={driverActionState.busy}
                error={driverActionState.error}
                onAccept={(driverId) => void acceptAsDriver(driverId)}
                onComplete={() => void completeAsDriver()}
              />
            ) : (
              <section className="info">
                <h3>Lahore Ride-Booking</h3>
                <ul>
                  <li>Rider logs in, selects pickup &amp; destination, and chooses category.</li>
                  <li>Real-time fare calculation and ETA from Laravel MySQL backend.</li>
                  <li>Click 'Switch Account / Portal' at top right to view Driver Portal!</li>
                  <li>Drivers see incoming requests live and can accept &amp; complete rides.</li>
                </ul>
              </section>
            )}
          </aside>
        </main>
      ) : null}
    </div>
  );
}
