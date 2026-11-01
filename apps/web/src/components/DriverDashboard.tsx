import { useEffect, useState } from 'react';
import { ApiError, rideApi } from '../api/client';
import type { AuthUser, DirectoryDriver, DriverStatus, Ride } from '../api/types';
import { echo } from '../lib/echo';
import { formatPkr } from '../lib/format';
import { ErrorBanner } from './States';

interface DriverDashboardProps {
  user: AuthUser;
  onLogout: () => void;
}

export function DriverDashboard({ user, onLogout }: DriverDashboardProps) {
  const [driver, setDriver] = useState<DirectoryDriver | null>(null);
  const [activeRide, setActiveRide] = useState<Ride | null>(null);
  const [incomingRides, setIncomingRides] = useState<Ride[]>([]);
  const [error, setError] = useState<ApiError | null>(null);
  const [actionBusy, setActionBusy] = useState(false);

  const fetchFeed = async () => {
    try {
      // Bearer token is read automatically from localStorage by rideApi
      const feed = await rideApi.driverFeed();
      setDriver(feed.driver);
      setActiveRide(feed.activeRide);
      setIncomingRides(feed.incomingRides);
      setError(null);
    } catch (err) {
      setError(err as ApiError);
    }
  };

  useEffect(() => {
    void fetchFeed();
    const interval = setInterval(() => void fetchFeed(), 4000);

    // Real-time listener for incoming/updated rides via Reverb
    try {
      const channel = echo.channel('rides');
      channel.listen('.RideUpdated', () => {
        void fetchFeed();
      });

      return () => {
        clearInterval(interval);
        channel.stopListening('.RideUpdated');
        echo.leaveChannel('rides');
      };
    } catch {
      return () => clearInterval(interval);
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user.id]);

  const handleToggleStatus = async (newStatus: DriverStatus) => {
    setActionBusy(true);
    try {
      await rideApi.updateDriverStatus(user.id, newStatus);
      await fetchFeed();
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setActionBusy(false);
    }
  };

  const handleAcceptRide = async (rideId: string) => {
    setActionBusy(true);
    try {
      await rideApi.acceptRide(rideId);
      await fetchFeed();
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setActionBusy(false);
    }
  };

  const handleCompleteRide = async (rideId: string) => {
    setActionBusy(true);
    try {
      await rideApi.completeRide(rideId);
      await fetchFeed();
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setActionBusy(false);
    }
  };

  const currentStatus = driver?.status ?? user.status ?? 'offline';

  return (
    <div className="driver-dashboard">
      <header className="driver-header">
        <div className="driver-profile-info">
          <span className="brand-dot" />
          <div>
            <h2>{user.fullName}</h2>
            <div className="driver-meta">
              <span>🚘 {user.vehicleModel} ({user.vehiclePlate})</span>
              <span>⭐ {user.rating}</span>
              <span className={`status-badge status-${currentStatus}`}>
                {currentStatus.toUpperCase()}
              </span>
            </div>
          </div>
        </div>

        <div className="driver-header-actions">
          <div className="status-toggle-group">
            <button
              type="button"
              className={`btn btn-sm ${currentStatus === 'available' ? 'btn-success' : 'btn-ghost'}`}
              disabled={actionBusy || currentStatus === 'on_trip'}
              onClick={() => void handleToggleStatus('available')}
            >
              🟢 Online
            </button>
            <button
              type="button"
              className={`btn btn-sm ${currentStatus === 'offline' ? 'btn-danger' : 'btn-ghost'}`}
              disabled={actionBusy || currentStatus === 'on_trip'}
              onClick={() => void handleToggleStatus('offline')}
            >
              ⚪ Offline
            </button>
          </div>
          <button type="button" className="btn btn-outline btn-sm" onClick={onLogout}>
            Log Out
          </button>
        </div>
      </header>

      {error && <ErrorBanner error={error} />}

      <main className="driver-main-grid">
        {/* Active Trip Section */}
        {activeRide ? (
          <section className="card active-trip-card">
            <div className="card-header">
              <h3>🚨 Current Active Trip</h3>
              <span className="status-badge status-accepted">ACCEPTED</span>
            </div>
            <div className="active-trip-body">
              <div className="trip-locations">
                <div className="location-row">
                  <span className="loc-bullet pickup-bullet" />
                  <div>
                    <label>Pickup Location</label>
                    <strong>{activeRide.pickup.label}</strong>
                  </div>
                </div>
                <div className="location-row">
                  <span className="loc-bullet dropoff-bullet" />
                  <div>
                    <label>Destination</label>
                    <strong>{activeRide.destination.label}</strong>
                  </div>
                </div>
              </div>

              <div className="trip-summary-stats">
                <div className="stat-item">
                  <span className="stat-label">Fare (Cash)</span>
                  <span className="stat-value">{formatPkr(activeRide.fare.totalMinor)}</span>
                </div>
                <div className="stat-item">
                  <span className="stat-label">Category</span>
                  <span className="stat-value capitalize">{activeRide.category}</span>
                </div>
                <div className="stat-item">
                  <span className="stat-label">Est. Distance</span>
                  <span className="stat-value">{(activeRide.trip.distanceMeters / 1000).toFixed(1)} km</span>
                </div>
              </div>

              <div className="active-trip-actions">
                <button
                  type="button"
                  className="btn btn-primary btn-block btn-lg"
                  disabled={actionBusy}
                  onClick={() => void handleCompleteRide(activeRide.id)}
                >
                  {actionBusy ? 'Completing Ride...' : '🏁 Complete Ride (Trip Finished)'}
                </button>
              </div>
            </div>
          </section>
        ) : (
          <section className="card empty-trip-card">
            <h3>No Active Trip</h3>
            <p>
              {currentStatus === 'available'
                ? 'You are Online. Available rides matching your vehicle will appear below.'
                : 'You are currently Offline. Turn Online to start receiving incoming passenger requests.'}
            </p>
          </section>
        )}

        {/* Incoming / Requested Rides Feed */}
        <section className="card incoming-rides-card">
          <div className="card-header">
            <h3>📡 Incoming Ride Requests</h3>
            <span className="badge">{incomingRides.length} available</span>
          </div>

          {incomingRides.length === 0 ? (
            <div className="empty-state-small">
              <p>No new requests in Lahore right now. Waiting for riders...</p>
            </div>
          ) : (
            <div className="incoming-rides-list">
              {incomingRides.map((ride) => (
                <div key={ride.id} className="incoming-ride-item">
                  <div className="incoming-ride-main">
                    <div className="incoming-badge-row">
                      <span className="badge category-badge">{ride.category.toUpperCase()}</span>
                      <span className="incoming-fare">{formatPkr(ride.fare.totalMinor)}</span>
                    </div>
                    <div className="incoming-route">
                      <div>🟢 <strong>{ride.pickup.label}</strong></div>
                      <div>🏁 <strong>{ride.destination.label}</strong></div>
                    </div>
                    <div className="incoming-meta">
                      <span>{(ride.trip.distanceMeters / 1000).toFixed(1)} km</span>
                      <span>·</span>
                      <span>~{Math.round(ride.trip.durationSeconds / 60)} min</span>
                    </div>
                  </div>
                  <div className="incoming-actions">
                    <button
                      type="button"
                      className="btn btn-success btn-block"
                      disabled={actionBusy || !!activeRide}
                      onClick={() => void handleAcceptRide(ride.id)}
                    >
                      Accept Ride
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </section>
      </main>
    </div>
  );
}
