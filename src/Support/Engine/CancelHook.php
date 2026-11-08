<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use Throwable;

/**
 * Runs a step type's `cancel()` clean-up for one attempt of a cancelled instance — from the
 * cancel itself, and from the record (or lease expiry) of an attempt that was still running then,
 * whatever it returned: its output, or a failure whose effects the hook reads through `recall()`.
 * A failing clean-up never blocks either.
 *
 * @internal
 */
final readonly class CancelHook
{
    public function __construct(
        private StepCatalogue $catalogue,
    ) {}

    public function run(WorkflowInstance $instance, StepRun $run): void
    {
        $version = $instance->version;
        $graph = $version?->parsedGraph();
        $node = $graph?->node($run->node_id);
        $type = $node === null ? null : $this->catalogue->find($node->type);

        if ($version === null || $graph === null || $node === null || $type === null) {
            return;
        }

        try {
            $subject = $instance->subject_type === null ? null : Runtime::subject($instance);
            $type->cancel(Runtime::context($instance, $run, $version, $graph, $node, $subject, Runtime::owner($instance)));
        } catch (Throwable) {
            // A failing clean-up never blocks the cancel.
        }
    }
}
