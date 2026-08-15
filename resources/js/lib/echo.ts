import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

export type RealtimeRole = 'admin' | 'cashier' | 'teller';

export type RealtimeEventName =
    | 'balance_update'
    | 'new_transaction'
    | 'cash_in_pending'
    | 'cash_in_confirmed'
    | 'cash_in_cancelled'
    | 'float_update'
    | 'float_status_changed'
    | 'ping';

export type RealtimePayload = Record<string, unknown>;
export type RealtimeConnectionState =
    | 'connected'
    | 'connecting'
    | 'disconnected'
    | 'unavailable'
    | 'failed'
    | 'error';

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
        disableStats: true,
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

export function watchNgweLweEchoConnection(
    echo: NgweLweEcho,
    onChange: (state: RealtimeConnectionState) => void,
): () => void {
    const connection = (
        echo as unknown as {
            connector?: {
                pusher?: {
                    connection?: {
                        state?: string;
                        bind?: (
                            event: string,
                            callback: (payload?: unknown) => void,
                        ) => void;
                        unbind?: (
                            event: string,
                            callback: (payload?: unknown) => void,
                        ) => void;
                    };
                };
            };
        }
    ).connector?.pusher?.connection;

    if (!connection?.bind || !connection.unbind) {
        onChange('unavailable');

        return () => undefined;
    }

    const normalize = (state: unknown): RealtimeConnectionState => {
        if (
            state === 'connected' ||
            state === 'connecting' ||
            state === 'disconnected' ||
            state === 'unavailable' ||
            state === 'failed' ||
            state === 'error'
        ) {
            return state;
        }

        return 'disconnected';
    };

    const handleStateChange = (payload?: unknown) => {
        const state =
            typeof payload === 'object' &&
            payload !== null &&
            'current' in payload
                ? (payload as { current?: unknown }).current
                : payload;

        onChange(normalize(state));
    };

    connection.bind('state_change', handleStateChange);
    onChange(normalize(connection.state));

    return () => connection.unbind?.('state_change', handleStateChange);
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
