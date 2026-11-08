<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\Workflows\Bridges\Approvals\ApprovalsBridge;
use RoundlyConsulting\Workflows\Bridges\Lifecycle\LifecycleBridge;
use RoundlyConsulting\Workflows\Bridges\Teams\TeamsBridge;
use RoundlyConsulting\Workflows\Calendars\CalendarRegistry;
use RoundlyConsulting\Workflows\Commands\CancelCommand;
use RoundlyConsulting\Workflows\Commands\CatalogueCommand;
use RoundlyConsulting\Workflows\Commands\DoctorCommand;
use RoundlyConsulting\Workflows\Commands\ExportCommand;
use RoundlyConsulting\Workflows\Commands\InstallCommand;
use RoundlyConsulting\Workflows\Commands\ListCommand;
use RoundlyConsulting\Workflows\Commands\MakeStepCommand;
use RoundlyConsulting\Workflows\Commands\PruneCommand;
use RoundlyConsulting\Workflows\Commands\RetryCommand;
use RoundlyConsulting\Workflows\Commands\ShowCommand;
use RoundlyConsulting\Workflows\Commands\SyncCommand;
use RoundlyConsulting\Workflows\Commands\ValidateCommand;
use RoundlyConsulting\Workflows\Commands\WakeCommand;
use RoundlyConsulting\Workflows\Contracts\AuthorizesSteps;
use RoundlyConsulting\Workflows\Contracts\ChecksOwnerMembership;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\Support\AllowMembership;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Support\Engine\ExceptionMap;
use RoundlyConsulting\Workflows\Support\Engine\SyncRunner;
use RoundlyConsulting\Workflows\Support\EscalationActions;
use RoundlyConsulting\Workflows\Support\EventMappings;
use RoundlyConsulting\Workflows\Support\GateStepAuthorizer;
use RoundlyConsulting\Workflows\Support\NullOwnerContext;
use RoundlyConsulting\Workflows\Support\Owners;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;
use RoundlyConsulting\Workflows\Support\TriggerForms;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

final class WorkflowsServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('workflows')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasTranslations()
            ->hasRoutes('webhooks.php', 'workflows.webhooks.enabled', enabledByDefault: false)
            ->hasCommands([
                WakeCommand::class,
                PruneCommand::class,
                CatalogueCommand::class,
                ValidateCommand::class,
                RetryCommand::class,
                CancelCommand::class,
                DoctorCommand::class,
                InstallCommand::class,
                ListCommand::class,
                ShowCommand::class,
                SyncCommand::class,
                ExportCommand::class,
                MakeStepCommand::class,
            ])
            ->contributesToAbout(static fn (): array => [
                'Step types' => (string) count(app(StepCatalogue::class)->all()),
                'Bridges' => implode(', ', Bridges::installed()) ?: 'none',
                'Queue' => WorkflowsConfig::queueEnabled()
                    ? sprintf('ON (connection %s, queue %s)', WorkflowsConfig::queueConnection() === null ? 'DEFAULT' : 'SET', WorkflowsConfig::queueName() === null ? 'DEFAULT' : 'SET')
                    : 'OFF (inline)',
                'Key type' => WorkflowsConfig::keyType()->value,
                'Owner key type' => WorkflowsConfig::ownerKeyType()->value,
                'Subject key type' => WorkflowsConfig::subjectKeyType()->value,
                'Primary key type' => WorkflowsConfig::primaryKeyType()->value,
                'Limits' => sprintf('%d steps, depth %d, %s starts/min', WorkflowsConfig::maxSteps(), WorkflowsConfig::maxDepth(), WorkflowsConfig::maxStartsPerMinute() ?? 'unlimited'),
                'Webhooks' => WorkflowsConfig::webhooksEnabled() ? 'ON' : 'OFF',
            ]);
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(WorkflowsManager::class);
        $this->app->singleton(StepCatalogue::class);
        $this->app->singleton(SubjectRegistry::class);
        $this->app->singleton(CalendarRegistry::class);
        $this->app->singleton(Owners::class);
        $this->app->singleton(EscalationActions::class);
        $this->app->singleton(ExceptionMap::class);
        $this->app->singleton(TriggerForms::class);
        $this->app->singleton(EventMappings::class);
        $this->app->singleton(SyncRunner::class);

        $this->app->bind(AuthorizesSteps::class, static function (Application $app): AuthorizesSteps {
            $class = WorkflowsConfig::authorizer();

            $authorizer = $app->make($class);

            return $authorizer instanceof AuthorizesSteps ? $authorizer : new GateStepAuthorizer($app->make(Gate::class));
        });

        $this->app->bind(RestoresOwnerContext::class, static function (Application $app): RestoresOwnerContext {
            $class = WorkflowsConfig::ownerContext();

            $context = $app->make($class ?? TeamsBridge::defaultContext());

            return $context instanceof RestoresOwnerContext ? $context : new NullOwnerContext;
        });

        $this->app->bind(ChecksOwnerMembership::class, static function (Application $app): ChecksOwnerMembership {
            $class = WorkflowsConfig::ownerMembership();

            $membership = $app->make($class ?? TeamsBridge::defaultMembership());

            return $membership instanceof ChecksOwnerMembership ? $membership : new AllowMembership;
        });
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerBlueprintMacros();

        // Host types, subjects, calendars and event mappings may be registered in the app's own
        // providers, which boot after this one: wire them once everything has booted.
        $this->app->booted(function (Application $app): void {
            ApprovalsBridge::register($app);

            if (Bridges::available('lifecycle')) {
                LifecycleBridge::listen($app->make(Dispatcher::class));
            }

            $mappings = $app->make(EventMappings::class);

            foreach (array_keys(WorkflowsConfig::events()) as $class) {
                $mappings->wire($class);
            }
        });
    }
}
