import type { LahoreLocation, Ride } from '../api/types';

interface MapPlaceholderProps {
  pickup: LahoreLocation | { label: string; lat: number; lng: number } | null;
  destination: LahoreLocation | { label: string; lat: number; lng: number } | null;
  driverName?: string | null;
  ride?: Ride | null;
}

/**
 * A deliberately fake map.
 *
 * Real tiles and routing are optional in the brief, so instead of pretending to
 * be Google Maps this draws a schematic: two labelled pins, a dashed route, and
 * the pickup→destination geometry projected from the real coordinates. It makes
 * the demo legible without an API key, and it is the only component that would
 * need replacing to plug in a real map.
 */
export function MapPlaceholder({ pickup, destination, driverName, ride }: MapPlaceholderProps) {
  const status = ride?.status;

  return (
    <div className="map" aria-label="Route overview" role="img">
      <svg className="map__canvas" viewBox="0 0 400 260" preserveAspectRatio="xMidYMid slice">
        <defs>
          <pattern id="grid" width="28" height="28" patternUnits="userSpaceOnUse">
            <path d="M 28 0 L 0 0 0 28" fill="none" stroke="rgba(11,110,79,0.12)" strokeWidth="1" />
          </pattern>
          <linearGradient id="glow" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stopColor="#0b6e4f" stopOpacity="0.22" />
            <stop offset="100%" stopColor="#0b6e4f" stopOpacity="0" />
          </linearGradient>
        </defs>

        <rect width="400" height="260" fill="#f4faf7" />
        <rect width="400" height="260" fill="url(#grid)" />
        <circle cx="200" cy="130" r="130" fill="url(#glow)" />

        {/* Rivers / main roads, purely decorative */}
        <path d="M -10 190 C 90 170, 120 210, 220 175 S 340 140, 420 165" stroke="#cfe6dc" strokeWidth="10" fill="none" />
        <path d="M 60 -10 C 80 70, 40 120, 70 270" stroke="#e2eee9" strokeWidth="6" fill="none" />

        <path
          className={`map__route ${status === 'accepted' || status === 'completed' ? 'map__route--active' : ''}`}
          d="M 120 190 Q 200 90 290 90"
          fill="none"
          stroke="#0b6e4f"
          strokeWidth="3"
          strokeDasharray="7 7"
          strokeLinecap="round"
        />

        <g>
          <circle cx="120" cy="190" r="9" fill="#0b6e4f" />
          <circle cx="120" cy="190" r="16" fill="none" stroke="#0b6e4f" strokeOpacity="0.35" strokeWidth="2" />
        </g>
        <g>
          <circle cx="290" cy="90" r="9" fill="#e2711d" />
          <circle cx="290" cy="90" r="16" fill="none" stroke="#e2711d" strokeOpacity="0.35" strokeWidth="2" />
        </g>
      </svg>

      <div className="map__pins">
        <span className="map__pin map__pin--pickup">
          <span className="map__dot" /> {pickup?.label ?? 'Pickup not set'}
        </span>
        <span className="map__pin map__pin--destination">
          <span className="map__dot" /> {destination?.label ?? 'Destination not set'}
        </span>
      </div>

      <span className="map__badge">{driverName ? `${driverName} is assigned` : 'Lahore · schematic view'}</span>
    </div>
  );
}
