import { useState } from 'react';

import { postJson } from './http';
import { timeAgo, typeLabel } from './lib';

const STATUS_LABELS = {
    queued: 'Queued',
    running: 'Running',
    success: 'Success',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

function stampOf(run) {
    return run.finished_at ?? run.started_at ?? run.created_at;
}

function Progress({ run }) {
    const determinate = run.total != null && run.total > 0;
    const percent = determinate ? Math.min(100, Math.round((run.processed / run.total) * 100)) : null;

    return (
        <div className="tr-progress">
            <div className="tr-bar">
                {determinate ? (
                    <div className="tr-bar-fill" style={{ width: `${percent}%` }} />
                ) : (
                    run.status === 'running' && <div className="tr-bar-fill indeterminate" />
                )}
            </div>
            <span className="tr-count">
                {determinate ? `${run.processed} / ${run.total}` : `${run.processed} processed`}
            </span>
        </div>
    );
}

export function TaskRow({ run, cancelUrl, onChanged }) {
    const [cancelling, setCancelling] = useState(false);
    const active = run.status === 'queued' || run.status === 'running';

    const cancel = () => {
        setCancelling(true);

        postJson(cancelUrl.replace('__ID__', run.id))
            .then(onChanged)
            .finally(() => setCancelling(false));
    };

    return (
        <li className="tr-row">
            <div className="tr-row-main">
                <div className="tr-row-head">
                    <span className="tr-type">{typeLabel(run.type)}</span>
                    <span className={`tr-badge ${run.status}`}>
                        <span className="tr-dot" />
                        {STATUS_LABELS[run.status] ?? run.status}
                    </span>
                </div>

                {active && <Progress run={run} />}

                <p className="tr-meta">
                    {run.message ? `${run.message} · ` : ''}
                    <span className="tr-time">{timeAgo(stampOf(run))}</span>
                </p>
            </div>

            {active &&
                (run.cancel_requested ? (
                    <span className="tr-cancelling">Cancelling…</span>
                ) : (
                    <button type="button" className="tr-cancel" onClick={cancel} disabled={cancelling}>
                        Cancel
                    </button>
                ))}
        </li>
    );
}
