<?php

declare(strict_types=1);

return [
    'types' => [
        'trigger' => [
            'label' => 'Trigger',
            'description' => 'Starts the workflow: manually, on an app event, on a schedule or by a webhook.',
        ],
        'condition' => [
            'label' => 'Condition',
            'description' => 'Branches on a condition: true or false.',
        ],
        'branch' => [
            'label' => 'Branch',
            'description' => 'Takes the first case whose condition holds, else the default.',
        ],
        'set_field' => [
            'label' => 'Set field',
            'description' => 'Writes a writable field of the subject.',
        ],
        'transition' => [
            'label' => 'Lifecycle transition',
            'description' => 'Requests a lifecycle transition; the lifecycle guard decides.',
        ],
        'delay' => [
            'label' => 'Delay',
            'description' => 'Waits for a duration, until a date, or until a date field.',
        ],
        'wait' => [
            'label' => 'Wait',
            'description' => 'Waits for an event or until a condition holds.',
        ],
        'loop' => [
            'label' => 'Loop counter',
            'description' => 'Continues a loop at most a set number of times.',
        ],
        'start_workflow' => [
            'label' => 'Start workflow',
            'description' => 'Starts another workflow, optionally waiting for it.',
        ],
        'end' => [
            'label' => 'End',
            'description' => 'Ends the workflow.',
        ],
        'approval' => [
            'label' => 'Approval',
            'description' => 'Asks for approval: rules, stages, delegation and expiry.',
        ],
        'notify' => [
            'label' => 'Notify',
            'description' => 'Sends a notification to people.',
        ],
        'webhook' => [
            'label' => 'Webhook',
            'description' => 'Queues an outbound webhook.',
        ],
    ],
    'outcomes' => [
        'next' => 'Next',
        'true' => 'True',
        'false' => 'False',
        'transitioned' => 'Transitioned',
        'denied' => 'Denied',
        'received' => 'Received',
        'met' => 'Met',
        'timed_out' => 'Timed out',
        'continue' => 'Continue',
        'exhausted' => 'Exhausted',
        'started' => 'Started',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
        'queued' => 'Queued',
    ],
    'instances' => '{1} :count instance|[0,*] :count instances',
];
