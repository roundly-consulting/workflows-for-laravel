<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Workflows\Testing\WorkflowsFake;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Durable, versioned workflows: JSON graphs run step by step on queues.
 *
 * @method static \RoundlyConsulting\Workflows\Builders\PendingStart start(string $key, \Illuminate\Database\Eloquent\Model|null $subject = null, \Illuminate\Database\Eloquent\Model|null $owner = null)
 * @method static \RoundlyConsulting\Workflows\DataTransferObjects\StartResult startWith(\RoundlyConsulting\Workflows\DataTransferObjects\StartData $data)
 * @method static \RoundlyConsulting\Workflows\DataTransferObjects\EmitResult emit(string $event, \Illuminate\Database\Eloquent\Model|null $subject = null, array<string, mixed> $payload = [], string|null $idempotencyKey = null, \Illuminate\Database\Eloquent\Model|null $user = null, \Illuminate\Database\Eloquent\Model|null $owner = null)
 * @method static \RoundlyConsulting\Workflows\DataTransferObjects\EmitResult emitWith(\RoundlyConsulting\Workflows\DataTransferObjects\EmitData $data)
 * @method static \RoundlyConsulting\Workflows\Handles\SubjectHandle for(\Illuminate\Database\Eloquent\Model $subject)
 * @method static \RoundlyConsulting\Workflows\Handles\DefinitionHandle definition(string $key, \Illuminate\Database\Eloquent\Model|null $owner = null)
 * @method static \RoundlyConsulting\Workflows\Handles\DefinitionsAccessor definitions()
 * @method static \RoundlyConsulting\Workflows\Handles\VersionsAccessor versions()
 * @method static \RoundlyConsulting\Workflows\Handles\InstancesAccessor instances()
 * @method static \RoundlyConsulting\Workflows\Handles\CatalogueAccessor catalogue()
 * @method static \RoundlyConsulting\Workflows\Handles\SubjectsAccessor subjects()
 * @method static \RoundlyConsulting\Workflows\Builders\SubjectTypeBuilder subject(string $key, string $model)
 * @method static \RoundlyConsulting\Workflows\Handles\CalendarsAccessor calendars()
 * @method static \RoundlyConsulting\Workflows\Handles\TriggersAccessor triggers()
 * @method static \RoundlyConsulting\Workflows\Handles\SchemaAccessor schema()
 * @method static \RoundlyConsulting\Workflows\Graph\Build\GraphBuilder graph()
 * @method static \RoundlyConsulting\Workflows\DataTransferObjects\WakeReport wake(\Carbon\CarbonInterface|null $now = null)
 * @method static \RoundlyConsulting\Workflows\DataTransferObjects\PruneReport prune()
 * @method static void assertStarted(string $key, \Closure|null $where = null, int|null $times = null)
 * @method static void assertNotStarted(string $key)
 * @method static void assertNothingStarted()
 * @method static void assertEmitted(string $event, \Closure|null $where = null)
 * @method static void assertNotEmitted(string $event)
 * @method static void assertNothingEmitted()
 * @method static void assertCreated(string $key)
 * @method static void assertNotCreated(string $key)
 * @method static void assertEdited(string $key, \Closure|null $where = null)
 * @method static void assertNotEdited(string $key)
 * @method static void assertPublished(string $key)
 * @method static void assertNotPublished(string $key)
 * @method static void assertRetired(string $key)
 * @method static void assertNotRetired(string $key)
 * @method static void assertActivated(string $key)
 * @method static void assertNotActivated(string $key)
 * @method static void assertRestored(string $key)
 * @method static void assertNotRestored(string $key)
 * @method static void assertDraftDiscarded(string $key)
 * @method static void assertDraftNotDiscarded(string $key)
 * @method static void assertFlagResolved(string $key, string|null $node = null, string|null $path = null)
 * @method static void assertNoFlagResolved(string $key)
 * @method static void assertWebhookRotated(string $key)
 * @method static void assertWebhookNotRotated(string $key)
 * @method static void assertImported(string|null $key = null)
 * @method static void assertNothingImported()
 * @method static void assertWebhookReceived(string $hookId)
 * @method static void assertWebhookNotReceived(string $hookId)
 * @method static void assertWebhookDeliveryReceived(int|string $endpoint)
 * @method static void assertWebhookDeliveryNotReceived(int|string $endpoint)
 * @method static void assertRetried(\RoundlyConsulting\Workflows\Models\WorkflowInstance $instance)
 * @method static void assertNotRetried(\RoundlyConsulting\Workflows\Models\WorkflowInstance $instance)
 * @method static void assertCancelled(\RoundlyConsulting\Workflows\Models\WorkflowInstance $instance)
 * @method static void assertNotCancelled(\RoundlyConsulting\Workflows\Models\WorkflowInstance $instance)
 * @method static void assertWoken(int|null $times = null)
 * @method static void assertNotWoken()
 * @method static void assertPruned()
 * @method static void assertNotPruned()
 * @method static void assertTouched(\Illuminate\Database\Eloquent\Model|null $subject = null)
 * @method static void assertNotTouched(\Illuminate\Database\Eloquent\Model|null $subject = null)
 * @method static void assertSynced(string|null $path = null, bool|null $publish = null)
 * @method static void assertNotSynced()
 * @method static void assertNothingRecorded()
 *
 * @see WorkflowsManager
 */
final class Workflows extends Facade
{
    /**
     * Swap the manager for a record-only fake — behind the facade and in the container, so an
     * injected WorkflowsManager is faked too.
     */
    public static function fake(): WorkflowsFake
    {
        $fake = app(WorkflowsFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return WorkflowsManager::class;
    }
}
