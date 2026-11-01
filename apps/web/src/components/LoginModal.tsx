import { useState } from 'react';
import { ApiError } from '../api/client';
import type { DirectoryDriver, RiderSummary } from '../api/types';

interface LoginModalProps {
  riders: RiderSummary[];
  drivers: DirectoryDriver[];
  onLoginAsRider: (phone: string) => Promise<void>;
  onLoginAsDriver: (phone: string) => Promise<void>;
  onClose?: () => void;
}

export function LoginModal({ riders, drivers, onLoginAsRider, onLoginAsDriver, onClose }: LoginModalProps) {
  const [role, setRole] = useState<'rider' | 'driver'>('rider');

  // Rider state
  const [riderPhone, setRiderPhone] = useState(riders[0]?.phone || '');
  const [riderBusy, setRiderBusy] = useState(false);
  const [riderError, setRiderError] = useState('');

  // Driver state
  const [driverPhone, setDriverPhone] = useState(drivers[0]?.phone || '');
  const [driverBusy, setDriverBusy] = useState(false);
  const [driverError, setDriverError] = useState('');

  const handleRiderLogin = async () => {
    setRiderBusy(true);
    setRiderError('');
    try {
      await onLoginAsRider(riderPhone.trim());
    } catch (err) {
      setRiderError(err instanceof ApiError ? err.message : 'Login failed. Try again.');
    } finally {
      setRiderBusy(false);
    }
  };

  const handleDriverLogin = async () => {
    setDriverBusy(true);
    setDriverError('');
    try {
      await onLoginAsDriver(driverPhone.trim());
    } catch (err) {
      setDriverError(err instanceof ApiError ? err.message : 'Login failed. Try again.');
    } finally {
      setDriverBusy(false);
    }
  };

  return (
    <div className="login-backdrop" onClick={(e) => { if (e.target === e.currentTarget && onClose) onClose(); }}>
      <div className="login-card">
        {onClose && (
          <button
            type="button"
            className="login-close-btn"
            onClick={onClose}
            aria-label="Close login dialog"
          >
            ×
          </button>
        )}
        <div className="login-brand">
          <span className="brand-dot" />
          <h2>RideBook Lahore</h2>
          <p className="login-subtitle">Choose your portal to continue</p>
        </div>

        <div className="login-tabs">
          <button
            type="button"
            className={`login-tab ${role === 'rider' ? 'active' : ''}`}
            onClick={() => setRole('rider')}
          >
            🧑 Rider Portal
          </button>
          <button
            type="button"
            className={`login-tab ${role === 'driver' ? 'active' : ''}`}
            onClick={() => setRole('driver')}
          >
            🚗 Driver Portal
          </button>
        </div>

        {role === 'rider' ? (
          <div className="login-section">
            <label className="field-label">Phone Number</label>
            {riders.length > 0 && (
              <select
                className="select-input"
                value={riderPhone}
                onChange={(e) => setRiderPhone(e.target.value)}
              >
                {riders.map((r) => (
                  <option key={r.id} value={r.phone}>
                    {r.fullName} — {r.phone}
                  </option>
                ))}
              </select>
            )}
            <input
              className="text-input"
              type="tel"
              placeholder="+923001234567"
              value={riderPhone}
              onChange={(e) => setRiderPhone(e.target.value)}
              style={{ marginTop: '0.5rem' }}
            />
            <p className="field-hint">Book economy or comfort rides across Lahore.</p>
            {riderError && <p className="error-text">{riderError}</p>}
            <button
              type="button"
              className="btn btn-primary btn-block"
              onClick={() => void handleRiderLogin()}
              disabled={riderBusy || !riderPhone.trim()}
            >
              {riderBusy ? 'Logging in…' : 'Log In as Rider'}
            </button>
          </div>
        ) : (
          <div className="login-section">
            <label className="field-label">Phone Number</label>
            {drivers.length > 0 && (
              <select
                className="select-input"
                value={driverPhone}
                onChange={(e) => setDriverPhone(e.target.value)}
              >
                {drivers.map((d) => (
                  <option key={d.id} value={d.phone}>
                    {d.fullName} — {d.vehicleModel} ({d.vehicleCategory.toUpperCase()} · {d.vehiclePlate})
                  </option>
                ))}
              </select>
            )}
            <input
              className="text-input"
              type="tel"
              placeholder="+923001234567"
              value={driverPhone}
              onChange={(e) => setDriverPhone(e.target.value)}
              style={{ marginTop: '0.5rem' }}
            />
            <p className="field-hint">Receive ride requests, accept bookings, and complete trips.</p>
            {driverError && <p className="error-text">{driverError}</p>}
            <button
              type="button"
              className="btn btn-primary btn-block"
              onClick={() => void handleDriverLogin()}
              disabled={driverBusy || !driverPhone.trim()}
            >
              {driverBusy ? 'Logging in…' : 'Log In as Driver'}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
