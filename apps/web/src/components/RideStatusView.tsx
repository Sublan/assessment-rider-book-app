import { useState } from 'react';
import { ApiError } from '../api/client';
import type { Ride } from '../api/types';
import { formatDistance, formatDuration, formatMoneyMinor, formatTime } from '../lib/format';
import { ErrorBanner, Spinner } from './States';

const STATUS_COPY: Record<Ride['status'], { title: string; note: string }> = {
  requested: {
    title: 'Looking for a driver',
    note: 'We are offering your ride to the closest available drivers.',
  },
  accepted: {
    title: 'Driver on the way',
    note: 'Your driver has accepted the ride and is heading to the pickup point.',
  },
  completed: { title: 'Ride completed', note: 'Thanks for riding with RideBook.' },
  cancelled: { title: 'Ride cancelled', note: 'No fare was charged for this ride.' },
};

const STEP_LABELS: Record<string, string> = {
  ride_requested: 'Ride requested',
  ride_accepted: 'Driver assigned',
  ride_completed: 'Trip completed',
  ride_cancelled: 'Ride cancelled',
};

interface RideStatusViewProps {
  ride: Ride;
  cancelling: boolean;
  cancelError: ApiError | null;
  onCancel: (reason?: string) => void;
  onBookAnother: () => void;
  pollError: ApiError | null;
  onRetryPoll: () => void;
}

export function RideStatusView({
  ride,
  cancelling,
  cancelError,
  onCancel,
  onBookAnother,
  pollError,
  onRetryPoll,
}: RideStatusViewProps) {
  const [reason, setReason] = useState('');
  const copy = STATUS_COPY[ride.status];
  const isFinished = ride.status === 'completed' || ride.status === 'cancelled';

  return (
    <section className="status" aria-live="polite">
      <header className="status__head">
        <span className={`pill pill--${ride.status}`} data-testid="ride-status">
          {ride.status}
        </span>
        <h2>{copy.title}</h2>
        <p className="status__note">{copy.note}</p>
        <p className="status__id">Ride {ride.id.slice(0, 8)} · {ride.category}</p>
      </header>

      <div className="status__grid">
        <div className="stat">
          <span className="stat__label">Fare</span>
          <strong className="stat__value" data-testid="ride-fare">{formatMoneyMinor(ride.fare.totalMinor)}</strong>
          <span className="stat__hint">
            {ride.fare.breakdown.surgeMultiplier > 1 ? `${ride.fare.breakdown.surgeMultiplier}× surge` : 'Standard fare'}
          </span>
        </div>
        <div className="stat">
          <span className="stat__label">Trip</span>
          <strong className="stat__value">
            {formatDistance(ride.trip.distanceMeters)} · {formatDuration(ride.trip.durationSeconds)}
          </strong>
          <span className="stat__hint">
            {ride.trip.pickupEtaMinutes === null ? 'Waiting for a driver' : `${ride.trip.pickupEtaMinutes} min pickup`}
          </span>
        </div>
      </div>

      {ride.driver ? (
        <div className="driver" data-testid="assigned-driver">
          <span className="driver__avatar" aria-hidden="true">
            {ride.driver.fullName.charAt(0)}
          </span>
          <div className="driver__details">
            <strong>{ride.driver.fullName}</strong>
            <span>
              {ride.driver.vehicleModel} · {ride.driver.vehiclePlate}
            </span>
            <span className="driver__rating">★ {ride.driver.rating.toFixed(1)}</span>
          </div>
          <a className="driver__call" href={`tel:${ride.driver.phone}`}>
            Call
          </a>
        </div>
      ) : null}

      <ol className="timeline">
        {ride.timeline.map((event, index) => (
          <li key={`${event.type}-${index}`} className="timeline__item">
            <span className="timeline__dot" aria-hidden="true" />
            <span className="timeline__label">{STEP_LABELS[event.type] ?? event.type}</span>
            <span className="timeline__time">{formatTime(event.at)}</span>
          </li>
        ))}
      </ol>

      {pollError ? (
        <ErrorBanner error={pollError} title="Live updates paused" onRetry={onRetryPoll} compact />
      ) : null}

      {cancelError ? <ErrorBanner error={cancelError} title="Could not cancel" compact /> : null}

      {ride.cancellable ? (
        <div className="status__actions">
          <label className="sr-only" htmlFor="cancel-reason">
            Reason for cancelling
          </label>
          <input
            id="cancel-reason"
            className="input"
            placeholder="Reason (optional)"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            maxLength={200}
            disabled={cancelling}
          />
          <button
            type="button"
            className="btn btn--danger"
            onClick={() => onCancel(reason.trim() || undefined)}
            disabled={cancelling}
          >
            {cancelling ? <Spinner label="Cancelling ride" /> : 'Cancel ride'}
          </button>
        </div>
      ) : null}

      {isFinished ? (
        <button type="button" className="btn btn--primary btn--block" onClick={onBookAnother}>
          Book another ride
        </button>
      ) : null}
    </section>
  );
}
