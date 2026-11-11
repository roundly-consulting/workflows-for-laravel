# Changelog

All notable changes to `workflows-for-laravel` are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Changed

- Maintenance: the PostgreSQL and MySQL CI legs now run the suite under every `primary_key_type` (`bigint`, `uuid`, `ulid`), and the migrations are applied on both engines under each.

### Fixed

- A fresh `php artisan migrate` with `workflows.primary_key_type` set to `uuid` or `ulid` on PostgreSQL no longer fails in `create_workflow_instances_table` with `SQLSTATE[42830]: there is no unique constraint matching given keys for referenced table "workflow_instances"`. The migration now creates the primary key before the `parent_instance_id` foreign key that points back at the table. `bigint` ids and MySQL were not affected.
  **Already published the migrations?** Your copy in `database/migrations` is the broken one. Copy the fixed file over it and keep your file's name: `cp vendor/roundly-consulting/workflows-for-laravel/database/migrations/0003_create_workflow_instances_table.php database/migrations/<timestamp>_create_workflow_instances_table.php`. Then run `php artisan migrate`. Don't fix it with `vendor:publish --tag=workflows-migrations`: your copy no longer matches the package file, so it would publish a second copy with a new timestamp. If you already moved the self-reference into its own `Schema::table()` call, keep it; the schema is the same. Details are in the upgrade notes of the [documentation](https://roundly-consulting.com/open-source/docs/workflows-for-laravel).

## 1.2.0 - 2026-11-08

### Added

- A webhooks-for-laravel bridge, loaded when that package is installed:
  - **Inbound.** A webhook trigger can name an inbound endpoint (`{trigger: "webhook", endpoint: <endpoint id>}`). Point that endpoint at the `workflows` handler (`Webhooks::for($team)->inbound()->create(handler: 'workflows')`) and every verified delivery starts the workflow, with the delivery id as its idempotency key — a sender retry or a webhooks replay returns the first instance and never runs the workflow again. The endpoint's owner must be the workflow's owner (both app-level counts too), else the delivery is refused as `owner_mismatch`. The trigger needs no `idempotency` setting, its `subject` lookup works as before, and one active workflow per owner may name an endpoint (`webhook_endpoint_taken`).
  - The handler alias is registered at boot. Rename it, or switch it off with null, false or `off`, in the new `workflows.bridges.webhooks.handler` key (`WORKFLOWS_WEBHOOKS_HANDLER`). A host's own `webhooks.handlers.workflows` mapping wins.
  - **Outbound.** The `webhook` step now delivers through webhooks-for-laravel when no `DeliversWebhooks` adapter is bound. It finds the endpoint in the workflow owner's scope (an app-level workflow finds owner-less endpoints only) and queues one delivery per visit, idempotent on the step key, so a retry never queues a second one. The step completes `queued` with the delivery's `msg_` id as output `delivery`. Publish checks the event against the webhooks event type rule (`^[a-z0-9_]+(\.[a-z0-9_]+)*$`, at most 100 characters, no trailing newline), else `invalid_config` at `/event`, and against its `outbound.event_types` allow-list when one is set, else `unknown_webhook_event`.
  - Failure codes `endpoint_not_found` (missing, malformed or another owner's endpoint), `endpoint_not_accepting:disabled|not_subscribed`, `invalid_event_type` and `payload_too_large`, none retried automatically. An operator can retry `endpoint_not_accepting` after re-enabling the endpoint.
  - The graph builder writes both: `Trigger::webhook(endpoint: $id)` and `->webhook($id, $endpoint, $event, $payload)`.
  - `Workflows::triggers()->delivery(WebhookDelivery)` is the start call the handler makes. The fake records it, with `assertWebhookDeliveryReceived()` / `assertWebhookDeliveryNotReceived()`.
  - `workflows:doctor` fails an endpoint trigger once webhooks-for-laravel is gone, and warns when the handler alias is off.

## 1.1.0 - 2026-11-08

### Changed

- A manual retry (`Workflows::instances()->retry()`, `workflows:retry`) of a step whose `resume()` failed now resumes it again with the signal it received and the state it waited with, like the automatic retry already did — the event that already arrived is not waited for again. A step whose `handle()` failed still runs `handle()` again.

### Fixed

- `Workflows::emit()` no longer stops at the first matching definition that refuses to start (an `on_behalf_of` definition emitted without a user, one retired meanwhile): the other definitions still start, the waits on the event are still resumed, and the refusal is returned in the new `EmitResult::$failed` list (`EmitFailure` with the definition key and the exception). A mapped Laravel event reports the refusal to the exception handler instead of failing the host's request.
- A `start_workflow` step (with or without `wait`) and a `start_workflow` escalation action no longer treat a child the start-rate limit refused as started: the step or escalation fails retryably with `child_not_started` and retries with backoff, starting the child once the window has room. Before, the step completed with `started` (or took `failed` with `wait: true`) and the escalation was marked fired, while the child never ran.
- A team-owned `for_each` schedule now reads its subjects and evaluates `where` inside the owner's context (`Teams::runAs()`), like the webhook subject lookup. `workflows:wake` runs with no team context, so a team-scoped subject model found no subjects (`teams.scoping.without_context = empty`) or aborted the wake pass (`throw`).
- Under `Workflows::fake()`, `emit()` now refuses the event keys the real emit refuses (an internal `@` key, a malformed key such as `Order Placed`) with `InvalidEventKeyException` and records nothing — the fake and the real code share one key check — so `assertEmitted()` no longer passes for an emit that would throw in production.
- `Workflows::wake($now)` now runs the whole pass at `$now`: it reclaims what has expired at `$now`, stamps its claims with `$now`, and a retry it fires runs in that pass. Before, a retry due before `$now` but after the real clock was marked fired while its attempt stayed queued until real time caught up. (`workflows:wake` passes no time and is unaffected.)
- The README example now runs on a fresh install: the Installation installs `roundly-consulting/approvals-for-laravel` (and publishes its migrations) for the example's approval step, says to raise the queue connection's `retry_after` above the 570-second step job timeout (`DB_QUEUE_RETRY_AFTER=600` for Laravel's default `database` queue, which `workflows:doctor` otherwise fails), and the example's status comment reads `Running` (a queue worker takes it on to the approval step) instead of `Waiting`.

## 1.0.0 - 2026-11-08

Initial public release.

### Added

- Workflow definitions stored as versioned JSON graphs: drafts with optimistic checksums, publish, retire, activate, restore, version diffs and portable templates with id remapping and owner-specific review flags.
- Definitions kept as files: `Workflows::definitions()->sync()` and `workflows:sync` create, draft and (with `--publish`) publish definitions from JSON files in the template envelope — unchanged files do nothing, nothing is retired, untracked definitions are reported, `--dry` writes nothing — and `workflows:export` writes them; `workflows:validate --file` checks one without a definition.
- A fluent graph builder (`Workflows::graph()` with `When`, `Party`, `After`, `Ref`, `Trigger`, `Input`, `Stage` and `Escalate`) that writes the same JSON document; every method taking a graph also takes the builder.
- A published JSON Schema for the graph and the template, a step catalogue with per-type config schemas, and registered subject types with typed fields.
- Graph validation with machine-readable codes: reachability, cycles, limits, config schemas, field and party references, calendars, trigger forms and missing adapters; an unlabelled edge stands for `next` only, so a node with any other outcome must name it (`outcome_label_required`); a cycle is legal only through a loop's `continue` edge or a delay of a positive `for` (`cycle_without_breaker`).
- Triggers: manual (with validated input or a form), app events (`Workflows::emit()` and Laravel events mapped in config or in code with `Workflows::triggers()->map()` / `->listen()`, with a payload and an idempotency key), schedules (cron or presets, also once per matching subject with `for_each`) and an opt-in signed inbound webhook route.
- Built-in steps: condition, branch, set_field, transition, delay, wait (event — optionally filtered by its payload with `where` — condition, timeout), loop, start_workflow and end; host step types through one `StepType` class, with `ConfigSchema` to write their config schema from the supported keywords only, `StepContext::once()` for exactly-once effects and `recall()` to read one back in a `cancel()` clean-up; cancelling an instance cleans up after its live step whatever its attempt is doing, and cancels a child a `start_workflow` step started.
- A durable engine: step runs per attempt, waits as rows, leases, retries with backoff and a stable idempotency key (a failed resume is retried as a resume with the same signal), `once()` effect records, queue jobs or an inline runner, and `workflows:wake` for timers, retries, timeouts, polls, escalations and schedules — it also delivers again a claimed event whose resume job was lost, re-pushes a lost probe, and holds a signal that lands while its step runs until the step waits again.
- Limits on steps per instance, nesting depth and starts per definition per minute; a start the rate limit refuses comes back `refused` (`StartResult::wasRefused()`, 429 with `Retry-After` on the webhook route) and leaves its idempotency key free, so the sender can send it again.
- Business time: weekday and opening-hours calendars (opening-hours does the arithmetic itself), business-day delays and expiries, DST-safe.
- Timezone-safe dates: a `date` field or input stored at midnight keeps its calendar day in any version timezone (conditions, `between`, `for_each.where`, dry runs and `delay {until_field}`); a datetime compares as an instant.
- Escalations per waiting step: notify, reassign and start_workflow, timed in business time. A reassign hands a named open stage of a staged approval round to the new approvers in place — decisions already made keep counting and the round keeps its expiry — and replaces a flat round or an unnamed stage with a new round.
- Errors a developer can act on: an invalid-graph message lists its first problems, an unknown definition says which scope it was looked up in, an unknown step type, subject type or calendar suggests the closest registered key (or names the package a bridged step needs), and every exception adds its code and key to the log context.
- Typed failure codes: the `FailureCode` enum (every code the engine, built-ins and bridges write, each with an English and Slovak message), `WorkflowInstance::failure()` and `StepRun::error()`; host step codes stay plain strings.
- Dry runs that write nothing (with an opt-in log) and show each step's resolved input: one path, or `->explore()` for every branch an assumed outcome could take.
- A per-node run overlay and the step log, where every attempt records its input: the node config with each value reference resolved, models as `{id, type}`, capped by `workflows.steps_input.max_bytes`.
- Condition waits re-check a subject after an update only when the update changed a registered field (or a belongs-to field's foreign key), so ordinary saves add no query.
- Owners and teams: per-owner definitions, owner-scoped instances run in the owner's context, role and membership checks.
- An authorization hook (`Contracts\AuthorizesSteps`, `workflows.authorization.authorizer`) called before every side-effect step and every escalation action — also when the type names no ability, which the default `GateStepAuthorizer` allows and a host's own hook decides.
- Bridges, each optional: approvals, lifecycle (opt-in: every lifecycle transition emits `lifecycle.<lifecycle>.<transition>`, `workflows.bridges.lifecycle.emit_transitions`), notifications, opening hours, forms and teams.
- HTTP resources with swappable owner, subject and user reference resources — no class names over the wire; `Workflows::instances()->detail()` loads an instance's step log, pending waits and pending escalations in a fixed number of queries for `InstanceResource` (`WaitResource`, `EscalationResource`).
- `Workflows` facade, injectable `WorkflowsManager`, actions, and a record-only `Workflows::fake()`: non-null return types throughout, faked mutations return unsaved stand-ins, and every recorded operation has an assertion and its negative (`assertCreated`, `assertEdited`, `assertPublished`, `assertWebhookRotated`, `assertTouched($subject)`, `assertNothingRecorded`, …), plus Pest expectations and the `InteractsWithWorkflows` test helpers: drain the queue, travel and wake, and `fakeStep()` to script a step type's result (the engine, waits, escalations and authorization still run) with `assertStepRan()` / `assertStepNotRan()`.
- Commands: `workflows:install`, `wake`, `prune`, `catalogue`, `validate`, `retry`, `cancel`, `sync`, `export`, `list` (filter by status, definition and owner), `show` (status, path, step log, pending waits, next escalation; `--json`), `make:workflow-step` (a host step type with its config schema) and `doctor` (`--json` for CI and health checks, `--strict` to fail on warnings; it checks the installation, every host config schema, active versions — unregistered step types, missing adapters and calendars, opening-hours owners, condition waits on subjects without `HasWorkflows` — event mappings, stuck instances, the wake backlog and the last day's failures).
- English and Slovak translations.
- The optional bridges need approvals-for-laravel and opening-hours-for-laravel 2.1 or newer (declared as conflicts with older versions).
