<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\PackageToolkit\Concerns\HasConfigurableKey;
use RoundlyConsulting\PackageToolkit\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Workflows\Casts\UtcDateTime;
use RoundlyConsulting\Workflows\Database\Factories\WorkflowVersionFactory;
use RoundlyConsulting\Workflows\DataTransferObjects\ReviewFlag;
use RoundlyConsulting\Workflows\Enums\ActorMode;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Enums\VersionStatus;
use RoundlyConsulting\Workflows\Exceptions\PublishedVersionImmutableException;
use RoundlyConsulting\Workflows\Graph\Graph;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * One numbered snapshot of a graph. Once published it is immutable: only its status (published
 * → superseded), its schedule cursor and timestamps may change.
 *
 * @property int|string $id
 * @property int|string $definition_id
 * @property int $number
 * @property VersionStatus $status
 * @property string $schema_version
 * @property array<string, mixed> $graph
 * @property array<string, mixed> $trigger
 * @property TriggerType $trigger_type
 * @property string|null $trigger_key
 * @property ActorMode $actor_mode
 * @property array<string, mixed> $settings
 * @property string $checksum
 * @property list<array{node: string, path: string, reason: string}>|null $review_flags
 * @property CarbonImmutable|null $published_at
 * @property string|null $publisher_type
 * @property int|string|null $publisher_id
 * @property CarbonImmutable|null $schedule_cursor_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read WorkflowDefinition|null $definition
 * @property-read Model|null $publisher
 */
class WorkflowVersion extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<WorkflowVersionFactory> */
    use HasFactory;

    use SoftDeletes;
    use StoresUtcTimestamps;

    /**
     * The columns a published version may still change.
     */
    public const array MUTABLE_WHEN_PUBLISHED = ['status', 'schedule_cursor_at', 'updated_at', 'deleted_at'];

    protected $guarded = [];

    protected function primaryKeyTypeConfigKey(): string
    {
        return 'workflows.primary_key_type';
    }

    public function getTable(): string
    {
        return WorkflowsConfig::table('versions');
    }

    protected static function booted(): void
    {
        static::updating(static function (WorkflowVersion $version): void {
            if ($version->getOriginal('published_at') === null) {
                return;
            }

            foreach (array_keys($version->getDirty()) as $column) {
                if (! in_array($column, self::MUTABLE_WHEN_PUBLISHED, true)) {
                    throw PublishedVersionImmutableException::column($column);
                }
            }
        });
    }

    /**
     * @return BelongsTo<WorkflowDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowModels::definition(), 'definition_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function publisher(): MorphTo
    {
        return $this->morphTo();
    }

    public function parsedGraph(): Graph
    {
        return GraphParser::parse($this->graph);
    }

    /**
     * The import review flags left on this (draft) version.
     *
     * @return list<ReviewFlag>
     */
    public function reviewFlags(): array
    {
        $flags = [];

        foreach ($this->review_flags ?? [] as $stored) {
            $flag = ReviewFlag::fromArray($stored);

            if ($flag !== null) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    /**
     * @param  list<ReviewFlag>  $flags
     */
    public function setReviewFlags(array $flags): void
    {
        $this->review_flags = $flags === [] ? null : array_map(static fn (ReviewFlag $flag): array => $flag->toArray(), $flags);
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => VersionStatus::class,
            'graph' => 'array',
            'trigger' => 'array',
            'trigger_type' => TriggerType::class,
            'actor_mode' => ActorMode::class,
            'settings' => 'array',
            'review_flags' => 'array',
            'published_at' => UtcDateTime::class,
            'schedule_cursor_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): WorkflowVersionFactory
    {
        return WorkflowVersionFactory::new();
    }
}
