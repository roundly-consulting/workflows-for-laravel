<?php

declare(strict_types=1);

return [
    'types' => [
        'string' => 'Text',
        'text' => 'Long text',
        'integer' => 'Integer',
        'decimal' => 'Decimal',
        'boolean' => 'Yes / no',
        'date' => 'Date',
        'datetime' => 'Date and time',
        'enum' => 'Choice',
        'party' => 'Person',
        'list' => 'List',
    ],
    'operators' => [
        'equals' => 'equals',
        'in' => 'is one of',
        'gt' => 'greater than',
        'lt' => 'less than',
        'between' => 'between',
        'empty' => 'is empty',
        'contains' => 'contains',
        'before' => 'before',
        'after' => 'after',
    ],
];
