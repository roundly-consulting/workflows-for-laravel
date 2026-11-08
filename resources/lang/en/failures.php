<?php

declare(strict_types=1);

return [
    'adapter_missing' => 'This step needs the :package package.',
    'approval_cancelled' => 'The approval was cancelled.',
    'approval_missing' => 'The approval round no longer exists.',
    'child_not_started' => 'The workflow could not be started.',
    'delay_field_empty' => 'The date field ":field" is empty.',
    'ended_by_graph' => 'The workflow ended as failed.',
    'exception' => 'The step failed unexpectedly.',
    'field_write_refused' => 'The field is managed by a lifecycle and cannot be written directly.',
    'invalid_output' => 'The step returned an output that is not plain JSON or is too large.',
    'invalid_value' => 'The value for ":field" has the wrong type.',
    'lease_expired' => 'The step did not finish in time and will be retried.',
    'missing_edge' => 'The node ":node" does not exist.',
    'missing_outcome_edge' => 'The node ":node" has no edge for the outcome ":outcome".',
    'missing_trigger' => 'The workflow has no trigger.',
    'nesting_depth_exceeded' => 'Workflows may start other workflows at most :limit levels deep.',
    'no_approvers' => 'Nobody can approve this: the approvers resolved to no one.',
    'no_result' => 'The step returned no result.',
    'owner_mismatch' => 'The subject no longer belongs to the workflow owner.',
    'resume_unsupported' => 'The step type ":type" cannot resume.',
    'roles_unavailable' => 'Role ":role" cannot be resolved.',
    'start_rate_exceeded' => 'The workflow was started more than :limit times in a minute.',
    'step_limit_exceeded' => 'The workflow ran more than :limit steps.',
    'subject_missing' => 'The subject no longer exists or is not visible to the owner.',
    'unknown_escalation_action' => 'The escalation action ":action" is not available.',
    'unknown_step_type' => 'The step type ":type" is not registered.',
    'version_missing' => 'The workflow version no longer exists.',
    'worker_timeout' => 'The worker timed out running the step.',
];
