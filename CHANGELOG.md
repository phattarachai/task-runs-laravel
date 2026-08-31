# Changelog

All notable changes to `phattarachai/task-runs-laravel` are documented here.

Release notes are drafted automatically from merged pull requests and published on the
[Releases page](https://github.com/phattarachai/task-runs-laravel/releases) — that page is the authoritative log.

This file records anything released before that automation landed.

## v0.3.0 — 2026-08-31

**A `request` column per run.** A nullable `request` jsonb column on `task_runs`, holding the prompt and
call parameters behind a run — written by a producer such as `phattarachai/claude-tasks-laravel`.

- New migration `0001_01_01_000003_add_request_to_task_runs_table`.
- `recordRequest(array $request)` on the `TaskRun` model persists the payload; it casts to `array`.
- Held out of `snapshot()`, so the polling list stays light — the payload is fetched only on detail open.

## v0.2.0 — 2026-08-29

**A narration trail per run.** `reportProgress(string $line)` — on the `TaskRun` model and as a shorthand on
`InteractsWithTaskRun` — appends a `{at, line}` entry to a new append-only `progress` jsonb column, for jobs whose
story is worth more than one `message` line.

- New migration `0001_01_01_000002_add_progress_to_task_runs_table`.
- Appends re-read the row under a row lock, so concurrent writers can't lose each other's line. The counter and the
  headline message are untouched by narration.
- `progress_limit` (default 200) caps the trail; the oldest entries fall off. `null` keeps everything. The trail is
  pruned with its row.
- `progress` rides in `snapshot()`, so the poll payload, `TaskRunResource`, and `TaskRunStatusChanged` all carry it.
- New `TaskRunProgress` broadcast event, gated on `broadcast.enabled` like the rest, on a channel **per run**
  (`{broadcast.channel}.{id}`) carrying `{id, status, entry}`. The bundled page's client config exposes the pattern as
  `broadcast.run_channel`.

## v0.1.0 — 2026-08-29

Initial extraction of the vault/music task-run lineage: `task_runs` schema + model, guarded dispatcher
(overlap + orphan), three opt-in reporting levels (trait / TrackedJob middleware / untracked),
Interruptible + RestartSignal, broadcast-or-poll HTTP contract, the bundled Inertia/React page, and pruning.
