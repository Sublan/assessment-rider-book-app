import { ApiError } from '../api/client';
import type { DirectoryDriver, Ride } from '../api/types';
import { ErrorBanner, Spinner } from './States';

interface DriverSimulatorProps {
  drivers: DirectoryDriver[];
  ride: Ride;
  busy: boolean;
  error: ApiError | null;
  onAccept: (driverId: string) => void;
  onComplete: () => void;
}

/**
 * Demo-only controls standing in for the driver app.
 *
 * The brief only asks for a rider app, but a ride cannot move through its
 * lifecycle without a driver acting on it — so this panel plays that role (and
 * doubles as a live demo of the concurrency rule: tap Accept on a second driver
 * after one has taken the ride and you get a 409, not a double booking).
 */
export function DriverSimulator({ drivers, ride, busy, error, onAccept, onComplete }: DriverSimulatorProps) {
  const available = drivers.filter((driver) => driver.status === 'available' && driver.isActive);

  return (
    <section className="simulator">
      <header className="simulator__head">
        <h3>Driver simulator</h3>
        <span className="tag">demo only</span>
      </header>

      {error ? <ErrorBanner error={error} title="Driver action failed" compact /> : null}

      {ride.status === 'requested' ? (
        <>
          <p className="simulator__note">
            {available.length === 0
              ? 'No available drivers to accept this ride.'
              : `${available.length} available driver(s). Accept as one of them:`}
          </p>
          <ul className="simulator__list">
            {available.map((driver) => (
              <li key={driver.id}>
                <div className="simulator__driver">
                  <strong>{driver.fullName}</strong>
                  <span>
                    {driver.vehicleModel} · {driver.vehiclePlate} · {driver.vehicleCategory}
                  </span>
                </div>
                <button type="button" className="btn btn--small" onClick={() => onAccept(driver.id)} disabled={busy}>
                  {busy ? <Spinner label="Accepting" /> : 'Accept'}
                </button>
              </li>
            ))}
          </ul>
        </>
      ) : null}

      {ride.status === 'accepted' ? (
        <div className="simulator__complete">
          <p className="simulator__note">{ride.driver?.fullName} has the ride. Complete it when the trip ends.</p>
          <button type="button" className="btn btn--primary btn--block" onClick={onComplete} disabled={busy}>
            {busy ? <Spinner label="Completing ride" /> : 'Complete trip'}
          </button>
        </div>
      ) : null}

      {ride.status === 'completed' || ride.status === 'cancelled' ? (
        <p className="simulator__note">This ride is finished — nothing left to do.</p>
      ) : null}
    </section>
  );
}
