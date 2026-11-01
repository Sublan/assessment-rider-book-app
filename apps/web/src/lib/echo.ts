import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo: unknown;
  }
}

window.Pusher = Pusher;

const reverbKey = import.meta.env.VITE_REVERB_APP_KEY ?? 'zyclntl4jofon7wbb3aw';
const reverbHost = import.meta.env.VITE_REVERB_HOST ?? window.location.hostname ?? 'localhost';
const reverbPort = Number(import.meta.env.VITE_REVERB_PORT ?? 8080);
const reverbScheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http';

export const echo = new Echo({
  broadcaster: 'reverb',
  key: reverbKey,
  wsHost: reverbHost,
  wsPort: reverbPort,
  wssPort: reverbPort,
  forceTLS: reverbScheme === 'https',
  enabledTransports: ['ws', 'wss'],
});
