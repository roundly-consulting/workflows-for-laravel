<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Http\Resources\WorkflowOwnerResource;
use RoundlyConsulting\Workflows\Http\Resources\WorkflowSubjectResource;
use RoundlyConsulting\Workflows\Http\Resources\WorkflowUserResource;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowDryRun;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;
use RoundlyConsulting\Workflows\Models\WorkflowIdempotencyKey;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\Support\GateStepAuthorizer;

/*
| Every key is read through the package's strict readers: a typo throws naming the key, and a
| blank value (a host's `KEY=`) takes the default. Pass env values through raw.
*/

return [

    /*
    | Key types of the polymorphic columns: `bigint`, `uuid` or `ulid`. `key_type` governs the
    | user morphs (started_by, published_by, requested_by); a blank `owner_key_type` or
    | `subject_key_type` falls back to it. Every registered subject type must use the subject
    | key type. `primary_key_type` is the type of the package's own ids.
    */

    'key_type' => env('WORKFLOWS_KEY_TYPE', 'bigint'),

    'owner_key_type' => env('WORKFLOWS_OWNER_KEY_TYPE'),

    'subject_key_type' => env('WORKFLOWS_SUBJECT_KEY_TYPE'),

    'primary_key_type' => env('WORKFLOWS_PRIMARY_KEY_TYPE', 'bigint'),

    /*
    | Swappable models: extend the packaged class and name the subclass here.
    */

    'models' => [
        'definition' => WorkflowDefinition::class,
        'version' => WorkflowVersion::class,
        'instance' => WorkflowInstance::class,
        'step_run' => StepRun::class,
        'wait' => WorkflowWait::class,
        'escalation' => WorkflowEscalation::class,
        'idempotency_key' => WorkflowIdempotencyKey::class,
        'dry_run' => WorkflowDryRun::class,
    ],

    'tables' => [
        'definitions' => 'workflow_definitions',
        'versions' => 'workflow_versions',
        'instances' => 'workflow_instances',
        'step_runs' => 'workflow_step_runs',
        'waits' => 'workflow_waits',
        'escalations' => 'workflow_escalations',
        'idempotency_keys' => 'workflow_idempotency_keys',
        'dry_runs' => 'workflow_dry_runs',
    ],

    /*
    | Every step runs as a queued job. With the queue off, the same steps run inline, one after
    | another (an iterative runner, never nested dispatches). Blank connection / queue = the
    | application defaults.
    */

    'queue' => [
        'enabled' => env('WORKFLOWS_QUEUE_ENABLED', true),
        'connection' => env('WORKFLOWS_QUEUE_CONNECTION'),
        'queue' => env('WORKFLOWS_QUEUE_NAME'),
    ],

    // Host step types: a list of classes extending RoundlyConsulting\Workflows\Steps\StepType.
    'steps' => [],

    // Subject types: a list of classes implementing Contracts\DescribesWorkflowSubject.
    'subjects' => [],

    // Laravel events mapped to trigger keys: [Event::class => 'request.submitted'], or
    // [Event::class => ['key' => 'request.submitted', 'subject' => 'request', 'user' => 'user',
    //     'payload' => ['amount', 'currency'], 'idempotency_key' => 'submissionId']], or a
    // self-describing WorkflowEvent as [Event::class => '*']. Workflows::triggers()->map() and
    // ->listen() do the same from a service provider.
    'events' => [],

    // The default timezone of a workflow version (blank = app.timezone).
    'timezone' => env('WORKFLOWS_TIMEZONE'),

    'engine' => [
        // How long a claimed step may run before wake reclaims it (the job timeout is 30 s less).
        'lease' => 'PT10M',
        // A queued step whose job was pushed longer ago than this is pushed again by wake.
        'requeue_after' => 'PT5M',
    ],

    'wake' => [
        'batch' => 500,
        'claim_ttl' => 'PT10M',
    ],

    'retry' => [
        'default' => [
            'max_attempts' => 3,
            'backoff' => [30, 120, 600],
            'jitter' => 0.1,
        ],
        'max_attempts_cap' => 10,
    ],

    'limits' => [
        'max_steps' => 200,
        'max_depth' => 3,
        // Instances a definition may start per minute; blank = 60, `off` = no limit.
        'max_starts_per_minute' => env('WORKFLOWS_MAX_STARTS_PER_MINUTE', 60),
        'max_loop_iterations' => 1000,
    ],

    'graph' => [
        'max_nodes' => 500,
        'max_edges' => 2000,
        'max_bytes' => 524288,
    ],

    'conditions' => [
        'max_depth' => 6,
        'max_clauses' => 100,
    ],

    'steps_output' => [
        'max_bytes' => 65536,
    ],

    // Each step run records its input: the node config with every value reference resolved,
    // as the handler saw it. A larger input is stored as {"truncated": true, "bytes": n}.
    'steps_input' => [
        'max_bytes' => 65536,
    ],

    'escalations' => [
        'max_levels' => 5,
    ],

    'idempotency' => [
        // Trigger keys expire after this; a key re-used later starts a new instance.
        'ttl' => 'P30D',
    ],

    'schedule' => [
        // Missed schedule slots within this window are coalesced into one start.
        'catch_up' => 'PT1H',
    ],

    'authorization' => [
        // class-string<Contracts\AuthorizesSteps>: called before every side-effect step.
        'authorizer' => GateStepAuthorizer::class,
        // Step type key => policy ability, overriding the type's own ability().
        'abilities' => [],
    ],

    'actor' => [
        // class-string of an invokable returning the service-account user the `system` actor
        // authorizes as (null: a null user, so policies deny unless they accept ?User).
        'system_user' => null,
    ],

    'owners' => [
        // class-string<Contracts\RestoresOwnerContext>; null = teams when installed, else none.
        'context' => null,
        // class-string<Contracts\ChecksOwnerMembership>; null = teams when installed, else allow.
        'membership' => null,
    ],

    'parties' => [
        // The user model `{"kind":"user"}` parties resolve to (null = auth.providers.users.model).
        'user_model' => null,
        // class-string<Contracts\ResolvesRoles>; null = teams when installed, else no roles.
        'roles' => null,
    ],

    'adapters' => [
        // class-string<Contracts\TransitionAdapter>; null = the lifecycle bridge when installed.
        'transition' => null,
        // class-string<Contracts\DeliversWebhooks>; null = no outbound webhook step.
        'webhooks' => null,
    ],

    'business_time' => [
        // ISO weekday numbers of the built-in `weekdays` calendar (1 = Monday).
        'weekdays' => [1, 2, 3, 4, 5],
        'max_search_days' => 366,
        // class-string of an invokable returning the owner model of app-wide definitions'
        // opening-hours calendars.
        'calendar_owner' => null,
    ],

    'approval' => [
        // Safety net after an approval round's expiry, in case its resolution event was lost.
        'expiry_grace' => 'PT5M',
    ],

    // What happens to running instances when their subject is deleted: cancel | keep.
    'subjects_on_deleted' => 'cancel',

    'bridges' => [
        'lifecycle' => [
            // Emit `lifecycle.<lifecycle>.<transition>` for every lifecycle state change.
            'emit_transitions' => env('WORKFLOWS_LIFECYCLE_EMIT_TRANSITIONS', 'off'),
        ],
    ],

    // Definition files (`workflows:sync` / `workflows:export`): absolute or relative to the base path.
    'sync' => [
        'path' => env('WORKFLOWS_SYNC_PATH', 'resources/workflows'),
    ],

    'dry_run' => [
        // Write a workflow_dry_runs row for every dry run (on | off).
        'log' => env('WORKFLOWS_DRY_RUN_LOG', 'off'),
    ],

    'webhooks' => [
        // The opt-in inbound start route: POST {prefix}/hooks/{hook}.
        'enabled' => env('WORKFLOWS_WEBHOOKS_ENABLED', 'off'),
        'prefix' => 'workflows',
        'middleware' => ['api'],
        // REQUIRED when enabled: the signature check (e.g. 'sentinel.signed:<profile>'). An
        // empty list keeps the route unregistered (fail closed).
        'verify_middleware' => [],
        'max_bytes' => 262144,
    ],

    'resources' => [
        // How a host model renders. Point a key at your own JsonResource to add a name or avatar.
        'owner' => WorkflowOwnerResource::class,     // receives the owner model
        'subject' => WorkflowSubjectResource::class, // receives the subject model
        'user' => WorkflowUserResource::class,       // receives a user model
    ],

    'prune' => [
        'instances_after' => 'P90D',
        'dry_runs_after' => 'P30D',
    ],

];
