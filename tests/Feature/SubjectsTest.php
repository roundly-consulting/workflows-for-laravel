<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Builders\SubjectTypeBuilder;
use RoundlyConsulting\Workflows\Contracts\DescribesWorkflowSubject;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Exceptions\InvalidStepTypeException;
use RoundlyConsulting\Workflows\Exceptions\InvalidWorkflowsConfigurationException;
use RoundlyConsulting\Workflows\Exceptions\UnknownSubjectTypeException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;

final class UlidTicket extends Model
{
    use HasUlids;
}

final class TicketSubject implements DescribesWorkflowSubject
{
    public function describe(): SubjectTypeBuilder
    {
        return Workflows::subject('ticket', Document::class)->label('Ticket')->field('title', FieldType::String, 'Title');
    }
}

it('exports a subject type field schema with registry keys only', function (): void {
    $export = Workflows::subjects()->export('document');

    expect($export->key)->toBe('document')
        ->and($export->toArray())->not->toHaveKey('model')
        ->and($export->toArray())->toExposeNoClassNames()
        ->and($export->field('department')?->options)->toBe(['legal', 'finance', 'sales'])
        ->and($export->field('approver_manager')?->path())->toBe('approver.manager')
        ->and(Workflows::subjects()->find('nope'))->toBeNull()
        ->and(Workflows::subjects()->all())->toHaveKeys(['document', 'company']);
});

it('refuses an unknown subject type and an invalid key', function (): void {
    expect(fn () => Workflows::subjects()->export('nope'))->toThrow(UnknownSubjectTypeException::class)
        ->and(fn () => Workflows::subject('Bad Key', Document::class))->toThrow(UnknownSubjectTypeException::class)
        ->and(fn () => Workflows::subject('ok', Document::class)->field('Bad Field', 'string'))->toThrow(InvalidStepTypeException::class);
});

// C-90 / OQ-56: one key type for every subject type, checked when the type is registered.
it('fails fast on a subject model whose key type does not match, naming the config key', function (): void {
    Workflows::subjects()->register(Workflows::subject('ulid_ticket', UlidTicket::class));
})->throws(InvalidWorkflowsConfigurationException::class, 'workflows.key_type');

it('names the subject key type config key when it is set', function (): void {
    config()->set('workflows.subject_key_type', 'uuid');

    Workflows::subjects()->register(Workflows::subject('doc_again', Document::class));
})->throws(InvalidWorkflowsConfigurationException::class, 'workflows.subject_key_type');

it('registers subject types from config lazily', function (): void {
    config()->set('workflows.subjects', [TicketSubject::class]);
    app()->forgetInstance(SubjectRegistry::class);

    expect(Workflows::subjects()->find('ticket')?->label)->toBe('Ticket');
});

it('refuses a configured subject describer that is not one', function (): void {
    config()->set('workflows.subjects', [stdClass::class]);
    app()->forgetInstance(SubjectRegistry::class);

    Workflows::subjects()->all();
})->throws(InvalidWorkflowsConfigurationException::class, 'workflows.subjects');
