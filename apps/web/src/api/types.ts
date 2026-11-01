/**
 * Wire types, mirroring App\Http\Presenters\* on the API.
 *
 * Money is always integer minor units (paisa) on the wire; only the view layer
 * divides by 100 for display, so no floating point rounding ever reaches an
 * amount the rider was quoted.
 */

export type RideCategory = 'economy' | 'comfort';
export type RideStatus = 'requested' | 'accepted' | 'cancelled' | 'completed';
export type DriverStatus = 'available' | 'on_trip' | 'offline';

export interface PickupPoint {
  label: string;
  lat: number;
  lng: number;
}

export interface Fare {
  currency: string;
  totalMinor: number;
  totalPkr: number;
  breakdown: {
    baseMinor: number;
    distanceMinor: number;
    timeMinor: number;
    minimumMinor: number;
    surgeMultiplier: number;
  };
}

export interface DriverSummary {
  id: string;
  fullName: string;
  vehicleCategory: RideCategory;
  vehicleModel: string;
  vehiclePlate: string;
  rating: number;
  status: DriverStatus;
  city: string;
  phone: string;
}

export interface DirectoryDriver extends DriverSummary {
  isActive: boolean;
  location: { lat: number; lng: number } | null;
}

export interface Ride {
  id: string;
  status: RideStatus;
  category: RideCategory;
  city: string;
  riderId: string;
  driver: DriverSummary | null;
  pickup: PickupPoint;
  destination: PickupPoint;
  trip: {
    distanceMeters: number;
    durationSeconds: number;
    pickupEtaMinutes: number | null;
  };
  fare: Fare;
  cancellable: boolean;
  timeline: Array<{
    type: string;
    actorType: string;
    at: string | null;
    metadata: Record<string, unknown>;
  }>;
  requestedAt: string | null;
  acceptedAt: string | null;
  completedAt: string | null;
  cancelledAt: string | null;
  cancelledBy: string | null;
  cancellationReason: string | null;
  createdAt: string | null;
  updatedAt: string | null;
}

export interface Estimate {
  category: RideCategory;
  currency: string;
  fare: Pick<Fare, 'totalMinor' | 'totalPkr' | 'breakdown'>;
  distanceMeters: number;
  durationSeconds: number;
  pickupEtaMinutes: number | null;
  availableDrivers: number;
  vehicleExample: string;
}

export interface LahoreLocation {
  id: string;
  label: string;
  area: string;
  lat: number;
  lng: number;
}

export interface LocationsResponse {
  city: string;
  bounds: { min_lat: number; max_lat: number; min_lng: number; max_lng: number };
  locations: LahoreLocation[];
}

export interface RiderSummary {
  id: string;
  fullName: string;
  phone: string;
}

export interface DriverFeedResponse {
  driver: DirectoryDriver;
  activeRide: Ride | null;
  incomingRides: Ride[];
}

export interface AuthUser {
  token: string;          // Sanctum Bearer token — store in localStorage, send as Authorization header
  userType: 'rider' | 'driver';
  id: string;
  fullName: string;
  phone: string;
  activeRide?: Ride | null;
  // If driver
  vehicleCategory?: RideCategory;
  vehicleModel?: string;
  vehiclePlate?: string;
  rating?: number;
  status?: DriverStatus;
  currentLat?: number;
  currentLng?: number;
}

export const CATEGORY_LABELS: Record<RideCategory, string> = {
  economy: 'Economy',
  comfort: 'Comfort',
};

export const CATEGORY_BLURBS: Record<RideCategory, string> = {
  economy: 'Suzuki Alto, Cultus or similar — the cheapest way across town.',
  comfort: 'Toyota Yaris, Honda City or similar — more space, newer cars.',
};
