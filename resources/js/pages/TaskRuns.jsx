import { Head } from '@inertiajs/react';

import { TaskRunsPanel } from '@task-runs';

/**
 * The only file the package publishes into the host app — app.jsx's import.meta.glob never
 * leaves ./pages, so the page must live here while the module itself is reached through the
 * `@task-runs` Vite alias. Everything Laravel-shaped stops at this file: endpoint URLs arrive
 * as props, and the CSRF token rides the XSRF cookie. Wrap the panel in your own layout here
 * if you want the app chrome around it.
 */
export default function TaskRuns(props) {
    return (
        <>
            <Head title={props.config?.title ?? 'Background Tasks'} />
            <TaskRunsPanel {...props} />
        </>
    );
}
