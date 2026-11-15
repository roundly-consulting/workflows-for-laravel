<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Contracts\ChecksOwnerMembership;
use RoundlyConsulting\Workflows\DataTransferObjects\PartyReference;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Steps\StepFailedException;

/**
 * Resolves party references to models: users by id, registered party fields, owner roles and the
 * triggering user. Duplicates are removed by key, soft-deleted users are skipped, and anyone who
 * is not a member of the owner is dropped. An empty result is never "anyone".
 *
 * @internal
 */
final readonly class PartyResolver
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * @param  list<PartyReference>  $parties
     * @param  bool  $members  false: keep non-members too (who to exclude, not who to ask)
     * @return list<Model>
     */
    public function resolve(array $parties, ValueScope $scope, bool $members = true): array
    {
        $resolved = [];

        foreach ($parties as $party) {
            foreach ($this->one($party, $scope, $members) as $model) {
                $resolved[self::identity($model)] = $model;
            }
        }

        return array_values(array_filter($resolved, static fn (Model $model): bool => ! (method_exists($model, 'trashed') && $model->trashed())));
    }

    /**
     * A model's identity across morph types: `morph-class:key`.
     */
    public static function identity(Model $model): string
    {
        return $model->getMorphClass().':'.Keys::of($model);
    }

    /**
     * @return list<Model>
     */
    private function one(PartyReference $party, ValueScope $scope, bool $members): array
    {
        $only = fn (?Model $user): array => $members ? $this->members($scope, $user) : ($user === null ? [] : [$user]);

        return match ($party->kind) {
            'user' => $only($this->user($party->id)),
            'field' => $only($this->field((string) $party->field, $scope)),
            'role' => $this->role((string) $party->role, $scope),
            'triggered_by' => $only($scope->starter),
            default => [],
        };
    }

    private function user(int|string|null $id): ?Model
    {
        return self::userById($id);
    }

    /**
     * The configured user model with that key (`parties.user_model`, else the auth provider's).
     */
    public static function userById(mixed $id): ?Model
    {
        $class = WorkflowsConfig::partiesUserModel();

        if ((! is_int($id) && ! is_string($id)) || $id === '' || $class === null || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $model = $class::query()->find($id);

        return $model instanceof Model ? $model : null;
    }

    private function field(string $path, ValueScope $scope): ?Model
    {
        $type = Values::typeOf($path, $scope);
        $value = Values::path($path, $scope);

        return $type === FieldType::Party && $value instanceof Model ? $value : null;
    }

    /**
     * @return list<Model>
     */
    private function role(string $role, ValueScope $scope): array
    {
        $resolver = Adapters::roles();

        if ($resolver === null) {
            throw StepFailedException::because(FailureCode::RolesUnavailable, Messages::get('failures.roles_unavailable', ['role' => $role]));
        }

        return $resolver->members($scope->owner, $role);
    }

    /**
     * @return list<Model>
     */
    private function members(ValueScope $scope, ?Model $user): array
    {
        if ($user === null) {
            return [];
        }

        $membership = $this->container->make(ChecksOwnerMembership::class);

        return $membership->isMember($scope->owner, $user) ? [$user] : [];
    }
}
