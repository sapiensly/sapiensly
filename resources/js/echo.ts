import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// One public-facing port feeds both ws/wss; forceTLS picks which is used.
// Default off the scheme (443 for https, 80 for http) — `Number(undefined)`
// is NaN, so `||` (not `??`) is what falls through to the default.
const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
const port = Number(import.meta.env.VITE_REVERB_PORT) || (scheme === 'https' ? 443 : 80);

const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
});

export default echo;
