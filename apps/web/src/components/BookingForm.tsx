import { ApiError } from '../api/client';
import { CATEGORY_BLURBS, CATEGORY_LABELS, type LahoreLocation, type RideCategory } from '../api/types';
import { formatDistance, formatDuration, formatMoneyMinor } from '../lib/format';
import type { useEstimate } from '../hooks/useEstimate';
import { EmptyState, ErrorBanner, EstimateSkeleton, Spinner } from './States';

interface BookingFormProps {
  locations: LahoreLocation[];
  pickupId: string;
  destinationId: string;
  category: RideCategory;
  estimate: ReturnType<typeof useEstimate>;
  submitting: boolean;
  submitError: ApiError | null;
  onChange: (patch: { pickupId?: string; destinationId?: string; category?: RideCategory }) => void;
  onSwap: () => void;
  onSubmit: () => void;
}

export function BookingForm({
  locations,
  pickupId,
  destinationId,
  category,
  estimate,
  submitting,
  submitError,
  onChange,
  onSwap,
  onSubmit,
}: BookingFormProps) {
  const canRequest = estimate.status === 'success' && !submitting && !estimate.samePlace;
  const noDrivers = estimate.status === 'success' && estimate.data.availableDrivers === 0;

  return (
    <form
      className="booking"
      onSubmit={(event) => {
        event.preventDefault();
        onSubmit();
      }}
    >
      <div className="route">
      <div className="field">
        <label htmlFor="pickup">Pickup</label>
        <select
          id="pickup"
          value={pickupId}
          onChange={(event) => onChange({ pickupId: event.target.value })}
          disabled={submitting}
        >
          <option value="">Choose a pickup point…</option>
          {locations.map((location) => (
            <option key={location.id} value={location.id} disabled={location.id === destinationId}>
              {location.label}
            </option>
          ))}
        </select>
      </div>

      <button type="button" className="btn btn--swap" onClick={onSwap} aria-label="Swap pickup and destination">
        <span aria-hidden="true">⇅</span> Swap
      </button>

      <div className="field">
        <label htmlFor="destination">Destination</label>
        <select
          id="destination"
          value={destinationId}
          onChange={(event) => onChange({ destinationId: event.target.value })}
          disabled={submitting}
        >
          <option value="">Where to?</option>
          {locations.map((location) => (
            <option key={location.id} value={location.id} disabled={location.id === pickupId}>
              {location.label}
            </option>
          ))}
        </select>
      </div>
      </div>

      {estimate.samePlace ? (
        <p className="hint hint--warn" role="status">
          Pickup and destination are the same place.
        </p>
      ) : null}

      <fieldset className="categories">
        <legend>Ride category</legend>
        <div className="categories__grid">
          {(Object.keys(CATEGORY_LABELS) as RideCategory[]).map((option) => {
            const selected = option === category;

            return (
              <label key={option} className={`category ${selected ? 'category--selected' : ''}`}>
                <input
                  type="radio"
                  name="category"
                  value={option}
                  checked={selected}
                  onChange={() => onChange({ category: option })}
                  disabled={submitting}
                />
                <span className="category__icon" aria-hidden="true">
                  {option === 'economy' ? '🚗' : '🚙'}
                </span>
                <span className="category__name">{CATEGORY_LABELS[option]}</span>
                <span className="category__blurb">{CATEGORY_BLURBS[option]}</span>
                {estimate.status === 'success' && selected ? (
                  <span className="category__price">{formatMoneyMinor(estimate.data.fare.totalMinor)}</span>
                ) : null}
              </label>
            );
          })}
        </div>
      </fieldset>

      <div className="estimate" aria-live="polite">
        {estimate.status === 'loading' ? <EstimateSkeleton /> : null}

        {estimate.status === 'idle' ? (
          <EmptyState title="Choose a pickup and a destination">We&apos;ll show the fare and pickup time here.</EmptyState>
        ) : null}

        {estimate.status === 'error' ? (
          <ErrorBanner
            error={estimate.error}
            title="Couldn't price this trip"
            onRetry={estimate.retry}
            compact
          />
        ) : null}

        {estimate.status === 'success' ? (
          <>
            <div className="estimate__headline">
              <div>
                <span className="estimate__label">Estimated fare</span>
                <strong className="estimate__fare" data-testid="fare-total">
                  {formatMoneyMinor(estimate.data.fare.totalMinor)}
                </strong>
              </div>
              <div>
                <span className="estimate__label">Pickup in</span>
                <strong className="estimate__eta" data-testid="pickup-eta">
                  {estimate.data.pickupEtaMinutes === null ? '—' : `${estimate.data.pickupEtaMinutes} min`}
                </strong>
              </div>
            </div>

            <dl className="estimate__facts">
              <div>
                <dt>Trip</dt>
                <dd>
                  {formatDistance(estimate.data.distanceMeters)} · {formatDuration(estimate.data.durationSeconds)}
                </dd>
              </div>
              <div>
                <dt>Drivers nearby</dt>
                <dd data-testid="drivers-nearby">{estimate.data.availableDrivers}</dd>
              </div>
              <div>
                <dt>Vehicle</dt>
                <dd>{estimate.data.vehicleExample}</dd>
              </div>
            </dl>

            {noDrivers ? (
              <EmptyState title="No drivers in this area right now">
                You can still send the request — we keep matching for a few minutes.
              </EmptyState>
            ) : null}
          </>
        ) : null}
      </div>

      {submitError ? <ErrorBanner error={submitError} title="Ride not requested" compact /> : null}

      <button type="submit" className="btn btn--primary btn--block" disabled={!canRequest}>
        {submitting ? (
          <>
            <Spinner label="Requesting ride" /> Requesting…
          </>
        ) : (
          'Request ride'
        )}
      </button>

      <p className="hint">
        Fares are quotes, not meters: the price is fixed when you request and stored with the ride.
      </p>
    </form>
  );
}
