<?php

return [
    'domains' => [
        'operations' => 'Operational modules, including IT support tickets.',
    ],

    'capabilities' => [
        'operations.it.ticket.assign',
        'operations.it.ticket.create',
        'operations.it.ticket.view',
        'operations.it.ticket.list',
        'operations.it.ticket.update',
    ],

    'roles' => [
        'it_agent' => [
            'name' => 'IT Agent',
            'description' => 'IT support crew: works the ticket queue — list, view, create, update, and assign tickets.',
            'capabilities' => [
                'operations.it.ticket.list',
                'operations.it.ticket.view',
                'operations.it.ticket.create',
                'operations.it.ticket.update',
                'operations.it.ticket.assign',
            ],
        ],
    ],
];
