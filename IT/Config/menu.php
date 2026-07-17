<?php

return [
    'items' => [
        [
            'id' => 'operations.it',
            'label' => 'IT',
            'icon' => 'heroicon-o-computer-desktop',
            'parent' => 'operations',
        ],
        [
            'id' => 'operations.it.ticket',
            'label' => 'IT Tickets',
            'icon' => 'heroicon-o-ticket',
            'route' => 'it.tickets.index',
            'permission' => 'operations.it.ticket.list',
            'parent' => 'operations.it',
        ],
        [
            'id' => 'operations.it.board',
            'label' => 'IT Board',
            'icon' => 'heroicon-o-view-columns',
            'route' => 'it.tickets.board',
            'permission' => 'operations.it.ticket.list',
            'parent' => 'operations.it',
        ],
    ],
];
