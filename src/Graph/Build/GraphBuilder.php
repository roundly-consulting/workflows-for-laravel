<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph\Build;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Macroable;
use JsonSerializable;
use LogicException;
use RoundlyConsulting\Workflows\DataTransferObjects\RetryPolicy;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationReport;
use RoundlyConsulting\Workflows\Graph\Graph;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\WorkflowsManager;
use Stringable;

/**
 * Writes a `roundly.workflows.graph/v1` document in PHP: nodes in insertion order, edges, and the
 * modifiers `retry()`, `escalate()` and `ui()` on the node added last. It only builds the
 * document — validation stays with `validate()` / publish — so a duplicate id or a missing edge
 * is reported there, never hidden here. Anything that takes a graph array takes a builder.
 *
 * @implements Arrayable<string, mixed>
 */
final class GraphBuilder implements Arrayable, JsonSerializable, Stringable
{
    use Macroable;

    /** @var array<string, mixed> */
    private array $settings = [];

    private bool $keepSettings = false;

    /** @var list<array<string, mixed>> */
    private array $nodes = [];

    /** @var list<array<string, mixed>> */
    private array $edges = [];

    private ?int $last = null;

    /**
     * Load a document (or a version's graph) to change it.
     *
     * @param  array<array-key, mixed>|WorkflowVersion  $graph
     */
    public static function from(array|WorkflowVersion $graph): self
    {
        $graph = $graph instanceof WorkflowVersion ? $graph->graph : $graph;
        $builder = new self;

        if (is_array($graph['settings'] ?? null)) {
            $builder->settings = GraphParser::stringKeyed($graph['settings']);
            $builder->keepSettings = true;
        }

        foreach (is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [] as $node) {
            if (is_array($node)) {
                $builder->nodes[] = GraphParser::stringKeyed($node);
            }
        }

        foreach (is_array($graph['edges'] ?? null) ? $graph['edges'] : [] as $edge) {
            if (is_array($edge)) {
                $builder->edges[] = GraphParser::stringKeyed($edge);
            }
        }

        $builder->last = $builder->nodes === [] ? null : count($builder->nodes) - 1;

        return $builder;
    }

    // Document ---------------------------------------------------------------------------

    public function settings(?string $actor = null, ?string $timezone = null, ?string $calendar = null, ?int $maxSteps = null, ?RetryPolicy $retry = null): static
    {
        $settings = array_filter(['actor' => $actor, 'timezone' => $timezone, 'calendar' => $calendar], static fn (?string $value): bool => $value !== null);

        if ($maxSteps !== null) {
            $settings['limits'] = ['max_steps' => $maxSteps];
        }

        if ($retry !== null) {
            $settings['retry'] = ['max_attempts' => $retry->maxAttempts, 'backoff' => $retry->backoff];
        }

        $this->settings = [...$this->settings, ...$settings];

        return $this;
    }

    /**
     * Any node, for host step types: `->node('order', 'acme.create_task', ['title' => 'Order'])`.
     *
     * @param  array<string, mixed>  $config
     */
    public function node(string $id, string $type, array $config = []): static
    {
        $node = ['id' => $id, 'type' => $type];

        if ($config !== []) {
            $node['config'] = self::value($config);
        }

        $this->nodes[] = $node;
        $this->last = count($this->nodes) - 1;

        return $this;
    }

    /**
     * Remove a node and every edge touching it.
     */
    public function remove(string $id): static
    {
        $this->nodes = array_values(array_filter($this->nodes, static fn (array $node): bool => ($node['id'] ?? null) !== $id));
        $this->edges = array_values(array_filter($this->edges, static fn (array $edge): bool => ($edge['from'] ?? null) !== $id && ($edge['to'] ?? null) !== $id));
        $this->last = $this->nodes === [] ? null : count($this->nodes) - 1;

        return $this;
    }

    // Built-in steps ---------------------------------------------------------------------

    public function trigger(string $id, Trigger $trigger): static
    {
        return $this->node($id, 'trigger', $trigger->toArray());
    }

    /**
     * Outcomes `true` / `false`.
     */
    public function condition(string $id, When $when): static
    {
        return $this->node($id, 'condition', ['when' => $when->toArray()]);
    }

    /**
     * The first case that holds names the outcome, else `$default`.
     *
     * @param  array<string, When>  $cases  outcome => condition
     */
    public function branch(string $id, array $cases, string $default = 'default'): static
    {
        $list = [];

        foreach ($cases as $outcome => $when) {
            $list[] = ['outcome' => $outcome, 'when' => $when->toArray()];
        }

        return $this->node($id, 'branch', ['cases' => $list, 'default' => $default]);
    }

    /**
     * Write a writable registered subject field (`subject.<field>`): a literal or a Ref.
     */
    public function setField(string $id, string $field, mixed $value): static
    {
        return $this->node($id, 'set_field', ['field' => $field, 'value' => $value]);
    }

    /**
     * Ask the subject's lifecycle for a transition; its guard decides (lifecycle bridge).
     */
    public function transition(string $id, string $transition, ?string $lifecycle = null, ?string $reason = null): static
    {
        return $this->node($id, 'transition', array_filter(['transition' => $transition, 'lifecycle' => $lifecycle, 'reason' => $reason], static fn (?string $value): bool => $value !== null));
    }

    /**
     * Wait a duration (After or an ISO-8601 string such as `PT2H`) or until an instant.
     */
    public function delay(string $id, After|CarbonInterface|string $for): static
    {
        return $this->node($id, 'delay', match (true) {
            $for instanceof CarbonInterface => ['until' => $for->toIso8601String()],
            $for instanceof After => ['for' => $for->toValue()],
            default => ['for' => $for],
        });
    }

    /**
     * Wait until a date field of the subject, optionally shifted.
     */
    public function delayUntilField(string $id, string $field, ?After $offset = null): static
    {
        return $this->node($id, 'delay', $offset === null ? ['until_field' => $field] : ['until_field' => $field, 'offset' => $offset->toValue()]);
    }

    /**
     * Wait for an app event about the subject (`match: any` — any subject of the owner).
     * Outcomes `received` / `timed_out`.
     */
    public function waitForEvent(string $id, string $event, string $match = 'subject', ?After $timeout = null): static
    {
        $config = ['event' => $event];

        if ($match !== 'subject') {
            $config['match'] = $match;
        }

        if ($timeout !== null) {
            $config['timeout'] = $timeout->toValue();
        }

        return $this->node($id, 'wait', $config);
    }

    /**
     * Wait until a condition over the subject holds (re-checked when it changes, and on `poll`).
     */
    public function waitUntil(string $id, When $when, ?After $poll = null, ?After $timeout = null): static
    {
        $config = ['condition' => $when->toArray()];

        if ($poll !== null) {
            $config['poll'] = $poll->toValue();
        }

        if ($timeout !== null) {
            $config['timeout'] = $timeout->toValue();
        }

        return $this->node($id, 'wait', $config);
    }

    /**
     * Outcomes `again` / `done`: a bounded loop back.
     */
    public function loop(string $id, int $max): static
    {
        return $this->node($id, 'loop', ['max' => $max]);
    }

    /**
     * Start another definition of the same owner: on the same subject (`same`), none (null) or a
     * party field (`['field' => 'subject.x']`); `wait` waits for it to finish.
     *
     * @param  array<string, mixed>|string|null  $subject
     * @param  array<string, mixed>  $input
     */
    public function startWorkflow(string $id, string $definition, array|string|null $subject = 'same', array $input = [], bool $wait = false): static
    {
        $config = ['definition' => $definition];

        if ($subject !== 'same') {
            $config['subject'] = $subject;
        }

        if ($input !== []) {
            $config['input'] = $input;
        }

        if ($wait) {
            $config['wait'] = true;
        }

        return $this->node($id, 'start_workflow', $config);
    }

    /**
     * End the run (`status: failed` fails it, with an optional reason).
     */
    public function end(string $id, ?string $status = null, ?string $reason = null): static
    {
        return $this->node($id, 'end', array_filter(['status' => $status, 'reason' => $reason], static fn (?string $value): bool => $value !== null));
    }

    // Bridged steps (plain config; the step is available once its package is installed) -----

    /**
     * An approval (approvals bridge): single-stage `approvers` + `rule`, or `stages`, or a
     * `preset`. Outcomes `approved` / `rejected` / `expired`.
     *
     * @param  list<Party>  $approvers
     * @param  list<Stage>  $stages
     */
    public function approval(string $id, array $approvers = [], string $rule = 'any', array $stages = [], ?After $expiresIn = null, ?string $preset = null): static
    {
        $config = [];

        if ($approvers !== []) {
            $config['approvers'] = Party::list($approvers);
            $config['rule'] = $rule;
        }

        if ($stages !== []) {
            $config['stages'] = array_map(static fn (Stage $stage): array => $stage->toArray(), $stages);
        }

        if ($preset !== null) {
            $config['preset'] = $preset;
        }

        if ($expiresIn !== null) {
            $config['expires_in'] = $expiresIn->toValue();
        }

        return $this->node($id, 'approval', $config);
    }

    /**
     * A notification (notifications bridge) to one or more parties.
     *
     * @param  Party|list<Party>  $to
     * @param  array<string, mixed>  $data
     */
    public function notify(string $id, string $notification, Party|array $to, array $data = []): static
    {
        $config = ['notification' => $notification, 'recipients' => Party::list($to instanceof Party ? [$to] : $to)];

        if ($data !== []) {
            $config['data'] = $data;
        }

        return $this->node($id, 'notify', $config);
    }

    // Modifiers of the node added last -----------------------------------------------------

    /**
     * @param  list<int>|null  $backoff  seconds before each retry
     */
    public function retry(int $maxAttempts, ?array $backoff = null): static
    {
        return $this->modify('retry', $backoff === null ? ['max_attempts' => $maxAttempts] : ['max_attempts' => $maxAttempts, 'backoff' => $backoff]);
    }

    /**
     * One escalation level of the waiting step added last (call again for the next level).
     */
    public function escalate(After $after, Escalate ...$actions): static
    {
        $index = $this->lastIndex('escalate');
        $levels = is_array($this->nodes[$index]['escalations'] ?? null) ? array_values($this->nodes[$index]['escalations']) : [];
        $levels[] = ['after' => $after->toValue(), 'actions' => array_map(static fn (Escalate $action): array => $action->toArray(), array_values($actions))];
        $this->nodes[$index]['escalations'] = $levels;

        return $this;
    }

    /**
     * Builder metadata (position, label); the engine never reads it.
     */
    public function ui(int $x, int $y, ?string $label = null): static
    {
        return $this->modify('ui', $label === null ? ['x' => $x, 'y' => $y] : ['x' => $x, 'y' => $y, 'label' => $label]);
    }

    // Edges ------------------------------------------------------------------------------

    /**
     * An edge, or one edge per outcome for a list.
     *
     * @param  string|list<string>|null  $on
     */
    public function connect(string $from, string $to, string|array|null $on = null): static
    {
        foreach (is_array($on) ? $on : [$on] as $outcome) {
            $this->edges[] = $outcome === null ? ['from' => $from, 'to' => $to] : ['from' => $from, 'to' => $to, 'outcome' => $outcome];
        }

        return $this;
    }

    /**
     * Outcome-less edges along the list: `chain('a', 'b', 'c')` is a → b → c.
     */
    public function chain(string ...$ids): static
    {
        $ids = array_values($ids);

        for ($i = 1, $count = count($ids); $i < $count; $i++) {
            $this->connect($ids[$i - 1], $ids[$i]);
        }

        return $this;
    }

    // Output -----------------------------------------------------------------------------

    /**
     * The same report as `Workflows::versions()->validate()`.
     */
    public function validate(?string $subjectType = null, ?Model $owner = null): ValidationReport
    {
        return app(WorkflowsManager::class)->versions()->validate($this, $subjectType, $owner);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $graph = ['schema' => Graph::SCHEMA];

        if ($this->settings !== [] || $this->keepSettings) {
            $graph['settings'] = $this->settings;
        }

        $graph['nodes'] = $this->nodes;
        $graph['edges'] = $this->edges;

        return $graph;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * A config value as plain JSON: builder values become their arrays, dates ISO-8601 strings,
     * enums their value, models their key.
     *
     * @internal
     */
    public static function value(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Ref, $value instanceof When, $value instanceof Party, $value instanceof Trigger, $value instanceof Stage, $value instanceof Escalate, $value instanceof Input => $value->toArray(),
            $value instanceof After => $value->toValue(),
            $value instanceof CarbonInterface => $value->toIso8601String(),
            $value instanceof BackedEnum => $value->value,
            $value instanceof Model => $value->getKey(),
            is_array($value) => array_map(self::value(...), $value),
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function modify(string $key, array $value): static
    {
        $this->nodes[$this->lastIndex($key)][$key] = $value;

        return $this;
    }

    private function lastIndex(string $modifier): int
    {
        return $this->last ?? throw new LogicException(sprintf('%s() needs a node: add one before calling it.', $modifier));
    }
}
