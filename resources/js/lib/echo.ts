import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

export type RealtimeRole = 'owner' | 'cashier' | 'employee';

export type RealtimeEventName =
    | 'balance_update'
    | 'new_transaction'
    | 'cash_in_pending'
    | 'float_status_changed'
    | 'ping';

export type RealtimePayload = Record<string, unknown>;

export type RealtimeHandlers = Partial<
    Record<RealtimeEventName, (payload: RealtimePayload) => void>
>;

export type NgweLweEcho = Echo<'reverb'>;

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

let activeEcho: NgweLweEcho | null = null;

export function createNgweLweEcho(token: string | null): NgweLweEcho | null {
    const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;

    if (!key) {
        return null;
    }

    activeEcho?.disconnect();
    window.Pusher = Pusher;

    activeEcho = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost:
            (import.meta.env.VITE_REVERB_HOST as string | undefined) ||
            window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8080),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
        forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: token
                ? {
                      Authorization: `Bearer ${token}`,
                  }
                : {},
        },
    });

    return activeEcho;
}

export function disconnectNgweLweEcho(): void {
    activeEcho?.disconnect();
    activeEcho = null;
}

export function subscribeToRoleChannel(
    echo: NgweLweEcho,
    role: RealtimeRole,
    handlers: RealtimeHandlers,
): () => void {
    return subscribe(echo, role, handlers);
}

export function subscribeToUserChannel(
    echo: NgweLweEcho,
    userId: number,
    handlers: RealtimeHandlers,
): () => void {
    return subscribe(echo, `user.${userId}`, handlers);
}

function subscribe(
    echo: NgweLweEcho,
    channelName: string,
    handlers: RealtimeHandlers,
): () => void {
    const channel = echo.private(channelName);

    Object.entries(handlers).forEach(([eventName, handler]) => {
        if (handler) {
            channel.listen(`.${eventName}`, handler);
        }
    });

    return () => echo.leave(channelName);
}
