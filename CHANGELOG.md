# Changelog

All notable changes to `workflows-for-laravel` are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- Workflow definitions stored as versioned JSON graphs: drafts with optimistic checksums, publish, retire, activate, restore, version diffs and portable templates with id remapping and owner-specific review flags.
- A fluent graph builder (`Workflows::graph()` with `When`, `Party`, `After`, `Ref`, `Trigger`, `Input`, `Stage` and `Escalate`) that writes the same JSON document; every method taking a graph also takes the builder.
- A published JSON Schema for the graph and the template, a step catalogue with per-type config schemas, and registered subject types with typed fields.
- Graph validation with machine-readable codes: reachability, cycles, limits, config schemas, field and party references, calendars and missing adapters.
- Triggers: manual (with validated input or a form), app events (`Workflows::emit()` and Laravel events mapped in config or in code with `Workflows::triggers()->map()` / `->listen()`, with a payload and an idempotency key), schedules (cron) and an opt-in signed inbound webhook route.
- Built-in steps: condition, branch, set_field, transition, delay, wait (event, condition, timeout), loop, start_workflow and end; host step types through one `StepType` class, with `ConfigSchema` to write their config schema from the supported keywords only.
- A durable engine: step runs per attempt, waits as rows, leases, retries with backoff and a stable idempotency key, `once()` effect records, queue jobs or an inline runner, and `workflows:wake` for timers, retries, timeouts, polls, escalations and schedules.
- Business time: weekday and opening-hours calendars, business-day delays and expiries, DST-safe.
- Escalations per waiting step: notify, reassign and start_workflow, timed in business time.
- Errors a developer can act on: an invalid-graph message lists its first problems, an unknown definition says which scope it was looked up in, an unknown step type, subject type or calendar suggests the closest registered key (or names the package a bridged step needs), and every exception adds its code and key to the log context.
- Typed failure codes: the `FailureCode` enum (every code the engine, built-ins and bridges write, each with an English and Slovak message), `WorkflowInstance::failure()` and `StepRun::error()`; host step codes stay plain strings.
- Dry runs that write nothing (with an opt-in log) and show each step's resolved input, a per-node run overlay, and the step log, where every attempt records its input: the node config with each value reference resolved, models as `{id, type}`, capped by `workflows.steps_input.max_bytes`.
- Owners and teams: per-owner definitions, owner-scoped instances run in the owner's context, role and membership checks.
- Bridges, each optional: approvals, lifecycle, notifications, opening hours, forms and teams.
- HTTP resources with swappable owner, subject and user reference resources — no class names over the wire; `Workflows::instances()->detail()` loads an instance's step log, pending waits and pending escalations in a fixed number of queries for `InstanceResource` (`WaitResource`, `EscalationResource`).
- `Workflows` facade, injectable `WorkflowsManager`, actions, and a record-only `Workflows::fake()`: non-null return types throughout, faked mutations return unsaved stand-ins, and every recorded operation has an assertion and its negative (`assertCreated`, `assertEdited`, `assertPublished`, `assertWebhookRotated`, `assertTouched($subject)`, `assertNothingRecorded`, …), plus Pest expectations and the `InteractsWithWorkflows` test helpers: drain the queue, travel and wake, and `fakeStep()` to script a step type's result (the engine, waits, escalations and authorization still run) with `assertStepRan()` / `assertStepNotRan()`.
- Commands: `workflows:install`, `wake`, `prune`, `catalogue`, `validate`, `retry`, `cancel`, `list` (filter by status, definition and owner), `show` (status, path, step log, pending waits, next escalation; `--json`) and `doctor` (`--json` for CI and health checks, `--strict` to fail on warnings; it checks the installation, every host config schema, active versions — unregistered step types, missing adapters and calendars, opening-hours owners, condition waits on subjects without `HasWorkflows` — event mappings, stuck instances, the wake backlog and the last day's failures).
- English and Slovak translations.
