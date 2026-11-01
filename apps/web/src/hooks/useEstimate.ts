import { useCallback, useEffect, useState } from 'react';
import { ApiError, rideApi } from '../api/client';
import type { Estimate, LahoreLocation, RideCategory } from '../api/types';

export type AsyncState<T> =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'success'; data: T }
  | { status: 'error'; error: ApiError };

/**
 * Live fare + ETA quote.
 *
 * Every change of pickup, destination or category re-quotes. In-flight requests
 * are aborted when the inputs change, so a slow response for an old destination
 * can never overwrite the price of the current one (the classic race that makes
 * a rider see the wrong fare at checkout).
 */
export function useEstimate(
  pickup: LahoreLocation | null,
  destination: LahoreLocation | null,
  category: RideCategory,
) {
  const [reloadToken, setReloadToken] = useState(0);
  const [state, setState] = useState<AsyncState<Estimate>>({ status: 'idle' });

  const samePlace = pickup !== null && destination !== null && pickup.id === destination.id;

  useEffect(() => {
    if (!pickup || !destination || pickup.id === destination.id) {
      setState({ status: 'idle' });
      return;
    }

    const controller = new AbortController();
    setState({ status: 'loading' });

    rideApi
      .estimate(
        {
          category,
          pickup: { lat: pickup.lat, lng: pickup.lng },
          destination: { lat: destination.lat, lng: destination.lng },
        },
        controller.signal,
      )
      .then((data) => setState({ status: 'success', data }))
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        setState({ status: 'error', error: error as ApiError });
      });

    return () => controller.abort();
  }, [pickup, destination, category, reloadToken]);

  const retry = useCallback(() => setReloadToken((token) => token + 1), []);

  return { ...state, retry, samePlace };
}
