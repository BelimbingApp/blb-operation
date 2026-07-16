<?php

return [
    'widgets' => [
        [
            'id' => 'operations.it.ticket-queue',
            'label' => 'IT Tickets',
            'description' => 'Queue health and tickets that need attention.',
            'icon' => 'heroicon-o-ticket',
            'permission' => 'operations.it.ticket.list',
            'component' => 'it.tickets.widgets.queue',
            'size' => 1,
        ],
    ],
];
