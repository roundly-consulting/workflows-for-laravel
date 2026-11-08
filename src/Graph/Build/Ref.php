<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph\Build;

/**
 * A value reference resolved at run time: `{"ref": "<path>"}`, or `{"relative": <duration>}`
 * (now plus a duration).
 */
final readonly class Ref
{
    /**
     * @param  array<string, mixed>  $value
     */
    private function __construct(
        private array $value,
    ) {}

    /**
     * Any value path: `subject.<field>`, `owner.<field>`, `input.<name>`, `trigger.<key>`,
     * `steps.<node>.output.<key>` or `now`.
     */
    public static function path(string $path): self
    {
        return new self(['ref' => $path]);
    }

    public static function subject(string $field): self
    {
        return self::path('subject.'.$field);
    }

    public static function owner(string $field): self
    {
        return self::path('owner.'.$field);
    }

    public static function input(string $name): self
    {
        return self::path('input.'.$name);
    }

    public static function trigger(string $key): self
    {
        return self::path('trigger.'.$key);
    }

    public static function step(string $node, string $key): self
    {
        return self::path('steps.'.$node.'.output.'.$key);
    }

    public static function now(): self
    {
        return self::path('now');
    }

    public static function relative(After $duration): self
    {
        return new self(['relative' => $duration->toValue()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->value;
    }
}
