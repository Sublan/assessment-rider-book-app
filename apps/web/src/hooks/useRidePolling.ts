import { useCallback, useEffect, useState } from 'react';
import { ApiError, rideApi } from '../api/client';
import type { Ride } from '../api/types';
import { echo } from '../lib/echo';

const POLL_INTERVAL_MS = 4000;

/**
 * Real-time ride synchronization via Laravel Reverb WebSockets,
 * with polling as an automatic fallback.
 */
export function useRidePolling(initial: Ride | null) {
  const [ride, setRide] = useState<Ride | null>(initial);
  const [error, setError] = useState<ApiError | null>(null);

  useEffect(() => {
    setRide(initial);
    setError(null);
  }, [initial]);

  const isTerminal = ride?.status === 'completed' || ride?.status === 'cancelled';
  const rideId = ride?.id;

  const refresh = useCallback(async () => {
    if (!rideId) return;

    try {
      setRide(await rideApi.getRide(rideId));
      setError(null);
    } catch (cause) {
      setError(cause as ApiError);
    }
  }, [rideId]);

  // 1. Instant Real-Time WebSocket listening via Laravel Reverb
  useEffect(() => {
    if (!rideId || isTerminal) return;

    try {
      const channel = echo.channel(`ride.${rideId}`);

      channel.listen('.RideUpdated', (event: { ride: Ride }) => {
        if (event?.ride) {
          setRide(event.ride);
          setError(null);
        }
      });

      return () => {
        channel.stopListening('.RideUpdated');
        echo.leaveChannel(`ride.${rideId}`);
      };
    } catch {
      // If WebSocket fails (e.g. offline/reverb down), fallback poller below handles it
    }
  }, [rideId, isTerminal]);

  // 2. Resilient Polling Fallback (ensures freshness even if WebSocket drops)
  useEffect(() => {
    if (!rideId || isTerminal) return;

    const timer = setInterval(() => {
      rideApi
        .getRide(rideId)
        .then((next) => {
          setRide(next);
          setError(null);
        })
        .catch((cause: unknown) => setError(cause as ApiError));
    }, POLL_INTERVAL_MS);

    return () => clearInterval(timer);
  }, [rideId, isTerminal]);

  return { ride, error, setRide, refresh };
}
