type SmartPollingOptions = {
    refresh: () => void | Promise<void>;
    shouldPoll?: () => boolean;
    activeIntervalMs?: number;
    hiddenIntervalMs?: number;
    runWhenVisible?: boolean;
};

export function startSmartPolling(options: SmartPollingOptions): () => void {
    const activeIntervalMs = options.activeIntervalMs ?? 5_000;
    const hiddenIntervalMs = options.hiddenIntervalMs ?? 60_000;
    const shouldPoll = options.shouldPoll ?? (() => true);
    const runWhenVisible = options.runWhenVisible ?? true;
    let timer: number | null = null;
    let stopped = false;
    let busy = false;

    const clearTimer = () => {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
    };

    const interval = () =>
        document.visibilityState === 'hidden'
            ? hiddenIntervalMs
            : activeIntervalMs;

    const poll = () => {
        if (stopped || busy || !shouldPoll()) {
            return;
        }

        busy = true;
        Promise.resolve(options.refresh()).finally(() => {
            busy = false;
        });
    };

    const schedule = () => {
        clearTimer();

        if (!stopped) {
            timer = window.setTimeout(tick, interval());
        }
    };

    const tick = () => {
        poll();
        schedule();
    };

    const handleVisibilityChange = () => {
        if (
            runWhenVisible &&
            document.visibilityState === 'visible' &&
            shouldPoll()
        ) {
            poll();
        }

        schedule();
    };

    document.addEventListener('visibilitychange', handleVisibilityChange);
    schedule();

    return () => {
        stopped = true;
        clearTimer();
        document.removeEventListener(
            'visibilitychange',
            handleVisibilityChange,
        );
    };
}
