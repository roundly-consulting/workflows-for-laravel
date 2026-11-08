<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Workflows\Handles\SubjectHandle;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\ConditionWaits;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * A workflow subject: `$model->workflows()` (the SubjectHandle) and `workflowInstances()`.
 * After an update commits, its pending condition waits are re-evaluated (only when it has any);
 * when it is deleted, its running instances are cancelled (`workflows.subjects_on_deleted`).
 * Everything goes through the manager, so `Workflows::fake()` sees it.
 *
 * @phpstan-require-extends Model
 */
trait HasWorkflows
{
    public static function bootHasWorkflows(): void
    {
        static::updated(static function (Model $subject): void {
            $subject->getConnection()->afterCommit(static function () use ($subject): void {
                if (ConditionWaits::exist($subject)) {
                    app(WorkflowsManager::class)->for($subject)->touch();
                }
            });
        });

        static::deleted(static function (Model $subject): void {
            if (WorkflowsConfig::subjectsOnDeleted() === 'cancel') {
                app(WorkflowsManager::class)->for($subject)->cancelAll('subject_deleted');
            }
        });
    }

    /**
     * @return MorphMany<WorkflowInstance, $this>
     */
    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowModels::instance(), 'subject');
    }

    public function workflows(): SubjectHandle
    {
        return app(WorkflowsManager::class)->for($this);
    }
}
