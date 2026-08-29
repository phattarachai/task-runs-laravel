import { useCallback, useEffect, useState } from 'react';

import { getJson } from './http';

const POLL_MS = 2000;

/**
 * Owns the tasks panel's live state. Broadcast-primary when the host has Echo (`window.Echo`)
 * and `task-runs.broadcast.enabled` is on — each TaskRunStatusChanged frame triggers one
 * snapshot refetch. Falls back to a 2s poll while anything is active and no healthy socket
 * covers it; with no Echo at all that plain poll is the whole mechanism.
 *
 * @param {{active: Array<object>, history: Array<object>, horizon: object, pollUrl: string, broadcast?: {enabled: boolean, channel: string, private: boolean}}} args
 * @returns {{active: Array<object>, history: Array<object>, horizon: object, pull: () => Promise<void>}}
 */
export function useTaskPoll({ active, history, horizon, pollUrl, broadcast }) {
    const [data, setData] = useState({ active, history, horizon });
    const [connected, setConnected] = useState(false);

    useEffect(() => {
        setData({ active, history, horizon });
    }, [active, history, horizon]);

    const pull = useCallback(async () => {
        try {
            setData(await getJson(pollUrl));
        } catch {
            // transient — the next frame or tick retries
        }
    }, [pollUrl]);

    const enabled = broadcast?.enabled === true;
    const channelName = broadcast?.channel;
    const isPrivate = broadcast?.private === true;

    useEffect(() => {
        const echo = typeof window === 'undefined' ? undefined : window.Echo;

        if (!echo || !enabled || !channelName) {
            return undefined;
        }

        const channel = isPrivate ? echo.private(channelName) : echo.channel(channelName);
        channel.listen('.TaskRunStatusChanged', pull);

        let unbind = () => {};

        try {
            const connection = echo.connector.pusher.connection;
            const sync = () => setConnected(connection.state === 'connected');
            sync();
            connection.bind('state_change', sync);
            unbind = () => connection.unbind('state_change', sync);
        } catch {
            setConnected(false);
        }

        return () => {
            channel.stopListening('.TaskRunStatusChanged', pull);
            unbind();
        };
    }, [enabled, channelName, isPrivate, pull]);

    const hasActive = data.active.length > 0;

    useEffect(() => {
        if (!hasActive || connected) {
            return undefined;
        }

        const timer = setInterval(pull, POLL_MS);

        return () => clearInterval(timer);
    }, [hasActive, connected, pull]);

    return { ...data, pull };
}
