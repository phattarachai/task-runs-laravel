# Task Runs for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phattarachai/task-runs-laravel.svg?style=flat-square)](https://packagist.org/packages/phattarachai/task-runs-laravel)
[![Tests](https://img.shields.io/github/actions/workflow/status/phattarachai/task-runs-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/phattarachai/task-runs-laravel/actions/workflows/run-tests.yml?query=branch%3Amain)
[![Code Style](https://img.shields.io/github/actions/workflow/status/phattarachai/task-runs-laravel/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/phattarachai/task-runs-laravel/actions/workflows/fix-php-code-style-issues.yml?query=branch%3Amain)
[![PHP Version](https://img.shields.io/packagist/dependency-v/phattarachai/task-runs-laravel/php?style=flat-square&label=php&logo=php&logoColor=white)](https://packagist.org/packages/phattarachai/task-runs-laravel)
![Laravel Version](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)
[![Total Downloads](https://img.shields.io/packagist/dt/phattarachai/task-runs-laravel.svg?style=flat-square)](https://packagist.org/packages/phattarachai/task-runs-laravel)

In-app background-task status tracking. Every opt-in job records a row in `task_runs`, so you can watch live progress
and review run history **inside your app** — Horizon tells you a job was *delivered*; this tells you what it *did*:
7,412 of 13,798 rows processed, cancel requested, resumed after a deploy, failed with this message, three days ago.

- **A `TaskRun` row per execution** — status (`queued / running / success / failed / cancelled`), progress
  (`processed / total`), attempts, a message line, an append-only narration trail, options, an optional subject
  morph, an optional request manifest, timestamps.
- **A dispatcher with guard rails** — type → job map in config, an overlap guard (a double-click is a no-op, not a
  second run), and an orphan guard that fails + supersedes a run whose job vanished (killed worker, flushed Redis).
- **Three opt-in reporting levels** — a trait for jobs that report progress, a job middleware for start/finish-only
  jobs, and untracked jobs stay untouched.
- **Cooperative cancel** — a flag the job checks between chunks, never `pkill`.
- **`Interruptible`** — an add-on trait for multi-hour jobs: yield to a queue restart or a run budget, re-queue, and
  resume instead of dying at hour six.
- **A JSON contract + a ready-made Inertia/React page** — active runs with progress bars and cancel buttons, history,
  and a Horizon health pill; broadcast-primary with a poll fallback, plain polling when there is no socket at all.

## Install

```bash
composer require phattarachai/task-runs-laravel
php artisan vendor:publish --tag=task-runs-config
php artisan migrate
```

The migration loads from the package; publish it instead with `--tag=task-runs-migrations` if you need to edit it.

## Register your tasks

```php
// config/task-runs.php
'jobs' => [
    'scan' => \App\Jobs\ScanJob::class,
    'report' => \App\Jobs\BuildReportJob::class,
],
```

Dispatch through the dispatcher — UI, CLI, and scheduler all use the same entry point:

```php
use Phattarachai\TaskRunsLaravel\TaskDispatcher;

app(TaskDispatcher::class)->dispatch('scan');                          // global
app(TaskDispatcher::class)->dispatch('scan', $library);                // scoped to a subject (morph)
app(TaskDispatcher::class)->dispatch('scan', options: ['all' => true], dispatchedBy: 'schedule');
```

If a run of the same type + subject is already queued or running, you get **that run back** instead of a duplicate —
unless it sat idle past `orphan_grace_seconds` on an empty queue, in which case it is failed and superseded.

## Report from the job — pick a level

**Level 1 — the trait, for jobs with real progress.** It provides the `public TaskRun $taskRun` constructor the
dispatcher relies on, the `failed()` hook, and a queued-cancel guard. Progress stays manual, because only the job
knows what a chunk is:

```php
use Phattarachai\TaskRunsLaravel\Concerns\InteractsWithTaskRun;

class ScanJob implements ShouldQueue
{
    use InteractsWithTaskRun, Queueable;

    public function handle(): void
    {
        if ($this->taskRunCancelledBeforeStart()) {
            return;
        }

        $this->taskRun->markRunning($files->count());

        foreach ($files as $file) {
            // ... work ...
            $this->taskRun->advance();

            if ($this->taskRun->isCancelled()) {
                $this->taskRun->markCancelled('Cancelled after '.$this->taskRun->processed.'.');

                return;
            }
        }

        $this->taskRun->markSuccess('Scanned everything.');
    }
}
```

### Narrate the run: `reportProgress()`

`message` holds one headline; `reportProgress()` appends to a trail of `{at, line}` entries — the *story* of the run,
for a feed the user can open and read:

```php
$this->reportProgress('เจอ Tax ID 0105558… → ค้นคู่ค้าใน DB');   // the trait's shorthand
$this->taskRun->reportProgress('ยอด 10,000 = ฐาน 9,345.79 + VAT 654.21 ✓');
```

Each append re-reads the row under a row lock, so two writers can't lose each other's line, and it moves no counter —
narration and `advance()` are independent. `progress_limit` (default 200) caps the trail; the oldest lines fall off,
and the whole thing goes away with the row when the run is pruned. It rides in `snapshot()`, so the poll payload,
the resource, and the status broadcast all carry it already:

```json
{"id": 41, "status": "running", "processed": 2, "progress": [{"at": "2026-08-29T10:00:04+07:00", "line": "…"}]}
```

### Log what the run asked: `recordRequest()`

`recordRequest(array $manifest)` stores a JSON payload on the row's nullable `request` column — the prompt and call
parameters a producer wants to keep for reproducibility (claude-tasks writes its `RunManifest` here). Unlike
`progress`, it is deliberately held **out of `snapshot()`**: it can be large and only a detail view needs it, so read
it straight off the row when you open one, never on every poll frame.

**Level 2 — the middleware, for start/finish-only jobs.** No lifecycle calls in `handle()` at all:

```php
use Phattarachai\TaskRunsLaravel\Jobs\Middleware\TrackedJob;

class BuildReportJob implements ShouldQueue
{
    use InteractsWithTaskRun, Queueable;

    public function middleware(): array
    {
        return [new TrackedJob];
    }

    public function handle(): void
    {
        // just the work — running/success/failed and the queued-cancel skip are handled around you
    }
}
```

**Level 3 — nothing.** Jobs that don't opt in are completely untouched.

## Multi-hour jobs: `Interruptible`

A job that runs for hours never returns to the worker's between-jobs restart check, so a deploy's
`queue:restart` / `horizon:terminate` would kill it mid-flight — and a backlog bigger than the worker timeout dies at
the ceiling. `Interruptible` lets the job notice both and hand the worker back, keeping its progress:

```php
use Phattarachai\TaskRunsLaravel\Concerns\Interruptible;

class CompatJob implements ShouldQueue
{
    use InteractsWithTaskRun, Interruptible, Queueable;

    public int $tries = 1;

    public int $timeout = 21540; // required by the trait — the run budget is a fraction of it

    public function handle(): void
    {
        $this->taskRun->markRunning($rows->count());
        $this->watchForInterruption();

        foreach ($rows as $row) {
            if ($this->shouldYield()) {
                $this->requeueForYield($this->taskRun);   // re-arm as queued + re-dispatch; a fresh worker resumes

                return;
            }

            // ... work ...
            $this->taskRun->advance();
        }

        $this->taskRun->markSuccess();
    }
}
```

## Queues and connections

```php
'queues' => ['compat' => 'heavy'],          // type => queue; everything else rides `default_queue`
'default_queue' => null,                    // null = the connection's default queue
'connections' => [
    'heavy' => 'redis-long',                // queue => connection
    '*' => 'redis',                         // fallback
],
```

When a map isn't expressive enough, register a resolver in a service provider:

```php
TaskDispatcher::resolveConnectionUsing(fn (?string $queue) => $queue === 'heavy' ? 'redis-long' : 'redis');
```

## The HTTP contract

Routes mount under `task-runs.routes.prefix` (default `tasks`) behind `['web', 'auth']`:

| Route | Returns |
|---|---|
| `GET /tasks/poll` | `{active: [...], history: [...], horizon: {status, masters}}` |
| `POST /tasks/run/{type}` | dispatches a whitelisted type, returns the run snapshot |
| `POST /tasks/{id}/cancel` | requests cooperative cancel (a queued run is cancelled outright) |
| `GET /tasks` | the Inertia page — registered only when `inertia-laravel` is installed |

`runnable` whitelists what the run endpoint may fire (`null` = every configured type). `horizon.status` degrades to
`unavailable` when Horizon isn't installed and `unknown` when Redis is unreachable — the package never requires
Horizon.

## The bundled page (optional)

The package ships a neutral Inertia/React tasks page: active runs with progress bars and cancel buttons, history, a
worker-health pill, and a run button per whitelisted type. Styling is plain CSS scoped under `.tr-root` with `--tr-*`
tokens (dark under a `.dark` ancestor) — no Tailwind coupling, re-skin by overriding tokens.

```bash
php artisan vendor:publish --tag=task-runs-inertia   # publishes resources/js/pages/TaskRuns.jsx
```

Only the page stub lands in your tree (`import.meta.glob` never leaves `./pages`); the module itself is reached
through a Vite alias, so there is no second copy to drift. In `vite.config.js`:

```js
import path from 'node:path'

export default defineConfig({
    resolve: {
        alias: {
            '@task-runs': path.resolve(
                __dirname,
                'vendor/phattarachai/task-runs-laravel/resources/js/task-runs',
            ),
        },
    },
})
```

Then `npm run build` and open `/tasks`. If your pages live in a capitalised `resources/js/Pages/`, move the published
stub there. Skip all of this to stay headless — `useTaskPoll` and the JSON contract work with any UI:

```js
import { useTaskPoll } from '@task-runs';
```

## Live updates (optional)

With broadcasting off (the default) the page polls every 2s while anything is active and stops when the queue drains.
Turn on `task-runs.broadcast.enabled` and every lifecycle write broadcasts `TaskRunStatusChanged` on the configured
channel (default: private `task-runs`) — the page then refreshes per frame and only falls back to polling while the
socket is down. Authorize the channel in your `routes/channels.php`:

```php
Broadcast::channel('task-runs', fn ($user) => $user !== null);
```

`reportProgress()` broadcasts too, as **`TaskRunProgress`** — but on a channel **per run**, `task-runs.{id}`, because a
feed drawer open on one run has no business receiving another run's narration. The frame carries
`{id, status, entry: {at, line}}`, so a listener can append without refetching:

```php
Broadcast::channel('task-runs.{taskRun}', fn ($user) => $user !== null);
```

```js
Echo.private(`task-runs.${id}`).listen('.TaskRunProgress', ({ entry }) => append(entry));
```

The bundled page's client config hands you the pattern as `broadcast.run_channel` (`task-runs.__ID__`), so the channel
name is never hardcoded twice.

## Pruning

Neither of this package's ancestors pruned, and their tables grew forever. Set `retention_days` and finished runs
older than that are removed daily via `model:prune` (the scheduler entry is registered for you). Active runs are
never pruned; `null` keeps everything.

## Extending the model

Point `task-runs.model` at your own subclass to add relations, labels, or columns:

```php
'model' => \App\Models\TaskRun::class,   // extends Phattarachai\TaskRunsLaravel\Models\TaskRun
```

## Config reference

See [`config/task-runs.php`](config/task-runs.php) — every key is documented inline. The essentials:

| Key | Default | |
|---|---|---|
| `jobs` | `[]` | type → job class map |
| `queues` / `default_queue` | `[]` / `null` | per-type queue routing |
| `connections` | `[]` | queue → connection map, `'*'` fallback |
| `orphan_grace_seconds` | `60` | idle window before an empty queue means the job is gone (`null` disables) |
| `retention_days` | `null` | prune finished runs after N days |
| `history_limit` | `30` | rows in the history section |
| `progress_limit` | `200` | ceiling on the narration trail per run (`null` = unbounded) |
| `runnable` | `null` | whitelist for `POST /tasks/run/{type}` |
| `broadcast.enabled` | `false` | fire `TaskRunStatusChanged` on lifecycle writes and `TaskRunProgress` on narration |
| `routes.prefix` / `routes.middleware` | `tasks` / `['web', 'auth']` | where and behind what it mounts |
| `ui.enabled` / `ui.page` | `true` / `TaskRuns` | the Inertia index route and component name |

## Testing

```bash
composer test      # Pest via Testbench (in-memory sqlite)
composer analyse   # PHPStan level 5
composer lint      # Pint
```

## Credits

Extracted from the task systems running in two production apps — the vault media library (which contributed the
orphan guard and `Interruptible`) and the music library (which contributed the broadcast path and the single
`snapshot()` wire shape).

## License

MIT — see [LICENSE.md](LICENSE.md).
