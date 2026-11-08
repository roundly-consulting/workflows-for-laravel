<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Testing;

use Pest\Contracts\Plugins\Bootable;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * Pest expectations for host suites, registered automatically (composer `extra.pest.plugins`):
 *
 * ```php
 * expect($instance)->toBeWaitingAt('approve');
 * expect($instance)->toHaveCompletedVia(['start', 'approve', 'done']);
 * expect($instance)->toHaveFailedWith('transition_denied:guard_failed');
 * ```
 */
final class WorkflowExpectations implements Bootable
{
    private static bool $registered = false;

    public function boot(): void
    {
        self::register();
    }

    public static function register(): void
    {
        if (self::$registered || ! function_exists('expect')) {
            return;
        }

        self::$registered = true;

        expect()->extend('toBeWaitingAt', function (string $node): mixed {
            $instance = $this->value;
            Assert::assertInstanceOf(WorkflowInstance::class, $instance);
            $instance->refresh();

            Assert::assertSame(InstanceStatus::Waiting, $instance->status, sprintf('Expected the instance to be waiting, it is %s.', $instance->status->value));
            Assert::assertSame($node, $instance->current_node_id, sprintf('Expected the instance waiting at [%s], it is at [%s].', $node, (string) $instance->current_node_id));

            return $this;
        });

        expect()->extend('toHaveCompletedVia', function (array $path): mixed {
            $instance = $this->value;
            Assert::assertInstanceOf(WorkflowInstance::class, $instance);
            $instance->refresh();

            Assert::assertSame(InstanceStatus::Completed, $instance->status, sprintf('Expected the instance to be completed, it is %s.', $instance->status->value));
            Assert::assertSame($path, $instance->path());

            return $this;
        });

        expect()->extend('toHaveFailedWith', function (string $code): mixed {
            $instance = $this->value;
            Assert::assertInstanceOf(WorkflowInstance::class, $instance);
            $instance->refresh();

            Assert::assertSame(InstanceStatus::Failed, $instance->status, sprintf('Expected the instance to have failed, it is %s.', $instance->status->value));
            Assert::assertSame($code, $instance->failure_code);

            return $this;
        });
    }
}
