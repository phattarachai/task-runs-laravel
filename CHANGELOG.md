# Changelog

All notable changes to `phattarachai/task-runs-laravel` are documented here.

Release notes are drafted automatically from merged pull requests and published on the
[Releases page](https://github.com/phattarachai/task-runs-laravel/releases) — that page is the authoritative log.

This file records anything released before that automation landed.

## v0.1.0 — unreleased

Initial extraction of the vault/music task-run lineage: `task_runs` schema + model, guarded dispatcher
(overlap + orphan), three opt-in reporting levels (trait / TrackedJob middleware / untracked),
Interruptible + RestartSignal, broadcast-or-poll HTTP contract, the bundled Inertia/React page, and pruning.
