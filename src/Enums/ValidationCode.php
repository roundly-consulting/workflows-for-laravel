<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * The machine-readable code of a publish-validation finding. A visual builder keys its
 * highlighting on these, so they never change once released.
 */
#[TranslatedLabels('workflows::enums.validation_code')]
enum ValidationCode: string
{
    use Helpers;

    case InvalidSchemaVersion = 'invalid_schema_version';
    case InvalidStructure = 'invalid_structure';
    case InvalidNodeId = 'invalid_node_id';
    case DuplicateNode = 'duplicate_node';
    case DanglingEdge = 'dangling_edge';
    case GraphTooLarge = 'graph_too_large';
    case UnknownNodeType = 'unknown_node_type';
    case AdapterMissing = 'adapter_missing';
    case InvalidConfig = 'invalid_config';
    case TriggerCount = 'trigger_count';
    case NoEnd = 'no_end';
    case TriggerHasIncoming = 'trigger_has_incoming';
    case EndHasOutgoing = 'end_has_outgoing';
    case MissingOutcome = 'missing_outcome';
    case UnknownOutcome = 'unknown_outcome';
    case DuplicateOutcome = 'duplicate_outcome';
    case OutcomeLabelRequired = 'outcome_label_required';
    case UnreachableNode = 'unreachable_node';
    case CycleWithoutBreaker = 'cycle_without_breaker';
    case UnknownField = 'unknown_field';
    case OperatorNotAllowed = 'operator_not_allowed';
    case InvalidValue = 'invalid_value';
    case UnknownInput = 'unknown_input';
    case InvalidReference = 'invalid_reference';
    case FieldNotWritable = 'field_not_writable';
    case SubjectOwnerUnresolvable = 'subject_owner_unresolvable';
    case UnknownSubjectType = 'unknown_subject_type';
    case ActorRequiresUser = 'actor_requires_user';
    case UnresolvedImportReference = 'unresolved_import_reference';
    case UnknownDefinition = 'unknown_definition';
    case ChildTriggerNotManual = 'child_trigger_not_manual';
    case MissingChildInput = 'missing_child_input';
    case SelfStart = 'self_start';
    case UnknownCalendar = 'unknown_calendar';
    case RolesUnavailable = 'roles_unavailable';
    case InvalidCron = 'invalid_cron';
    case ConditionTooComplex = 'condition_too_complex';
    case UnknownTransition = 'unknown_transition';
    case UnknownNotificationType = 'unknown_notification_type';
    case UnknownForm = 'unknown_form';
    case UnknownWebhookEvent = 'unknown_webhook_event';
    case WebhookEndpointTaken = 'webhook_endpoint_taken';
}
