import './task-runs.css';

import { useState } from 'react';

import { HorizonPill } from './HorizonPill';
import { postJson } from './http';
import { typeLabel } from './lib';
import { TaskRow } from './TaskRow';
import { useTaskPoll } from './useTaskPoll';

function Section({ title, runs, cancelUrl, onChanged }) {
    if (runs.length === 0) {
        return null;
    }

    return (
        <section className="tr-section">
            <h2 className="tr-section-title">{title}</h2>
            <ul className="tr-list">
                {runs.map((run) => (
                    <TaskRow key={run.id} run={run} cancelUrl={cancelUrl} onChanged={onChanged} />
                ))}
            </ul>
        </section>
    );
}

/**
 * The default tasks panel: active runs with progress bars + cancel, recent history, a worker
 * health pill, and a run button per whitelisted type. Neutral styling, themeable via the
 * `--tr-*` tokens on `.tr-root` (dark under a `.dark` ancestor or `.tr-root.scheme-dark`).
 */
export function TaskRunsPanel({ active, history, horizon, config }) {
    const data = useTaskPoll({
        active,
        history,
        horizon,
        pollUrl: config.endpoints.poll,
        broadcast: config.broadcast,
    });
    const [starting, setStarting] = useState(false);
    const empty = data.active.length === 0 && data.history.length === 0;

    const run = (type) => {
        setStarting(true);

        postJson(config.endpoints.run.replace('__TYPE__', encodeURIComponent(type)))
            .then(data.pull)
            .finally(() => setStarting(false));
    };

    return (
        <div className="tr-root">
            <header className="tr-header">
                <h1 className="tr-title">{config.title}</h1>
                <HorizonPill horizon={data.horizon} />
                {config.runnable.map((type) => (
                    <button
                        key={type}
                        type="button"
                        className="tr-run"
                        onClick={() => run(type)}
                        disabled={starting}
                    >
                        {typeLabel(type)}
                    </button>
                ))}
            </header>

            {empty ? (
                <p className="tr-empty">No background tasks.</p>
            ) : (
                <>
                    <Section
                        title="Active"
                        runs={data.active}
                        cancelUrl={config.endpoints.cancel}
                        onChanged={data.pull}
                    />
                    <Section
                        title="History"
                        runs={data.history}
                        cancelUrl={config.endpoints.cancel}
                        onChanged={data.pull}
                    />
                </>
            )}
        </div>
    );
}
