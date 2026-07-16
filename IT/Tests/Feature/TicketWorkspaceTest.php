<?php

use App\Base\Authz\DTO\Actor;
use App\Base\Dashboard\Services\DashboardLayout;
use App\Base\Workflow\DTO\TransitionContext;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use App\Modules\Operation\IT\Database\Seeders\TicketWorkflowSeeder;
use App\Modules\Operation\IT\Livewire\Tickets\Board;
use App\Modules\Operation\IT\Livewire\Tickets\Index;
use App\Modules\Operation\IT\Livewire\Tickets\Show;
use App\Modules\Operation\IT\Livewire\Widgets\TicketQueue;
use App\Modules\Operation\IT\Models\Ticket;
use App\Modules\Operation\IT\Services\TicketService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Build a company with an admin (core_admin), a reporter, and a technician —
 * both employees carry linked login users so notifications have somewhere to go.
 *
 * @return array{admin: User, company: Company, reporter: Employee, tech: Employee}
 */
function ticketFixture(): array
{
    (new TicketWorkflowSeeder)->run();

    $admin = createAdminUser();
    $company = Company::query()->findOrFail($admin->company_id);

    $adminEmployee = Employee::factory()->create(['company_id' => $company->id, 'full_name' => 'Admin Person']);
    $admin->update(['employee_id' => $adminEmployee->id]);

    $reporter = Employee::factory()->create(['company_id' => $company->id, 'full_name' => 'Rita Reporter']);
    User::factory()->create(['company_id' => $company->id, 'employee_id' => $reporter->id, 'name' => 'Rita Reporter']);

    $tech = Employee::factory()->create(['company_id' => $company->id, 'full_name' => 'Terry Tech']);
    User::factory()->create(['company_id' => $company->id, 'employee_id' => $tech->id, 'name' => 'Terry Tech']);

    return ['admin' => $admin, 'company' => $company, 'reporter' => $reporter, 'tech' => $tech];
}

function makeTicket(array $fixture, array $overrides = []): Ticket
{
    return app(TicketService::class)->create(
        Actor::forUser($fixture['admin']),
        $fixture['reporter'],
        array_merge([
            'title' => 'Printer on fire (figuratively)',
            'priority' => 'medium',
            'category' => 'hardware',
        ], $overrides),
    );
}

function notificationsFor(Employee $employee): array
{
    $userId = User::query()->where('employee_id', $employee->id)->value('id');

    return DB::table('notifications')
        ->where('notifiable_id', $userId)
        ->orderBy('created_at')
        ->get()
        ->map(fn (object $row): array => json_decode($row->data, true))
        ->all();
}

// -- Assignment ---------------------------------------------------------------

test('assigning an open ticket transitions it and notifies the stakeholders', function (): void {
    $fixture = ticketFixture();
    $ticket = makeTicket($fixture);

    $result = app(TicketService::class)->assign($ticket, Actor::forUser($fixture['admin']), $fixture['tech']);

    expect($result->success)->toBeTrue();
    $ticket->refresh();
    expect($ticket->status)->toBe('assigned');
    expect($ticket->assignee_id)->toBe($fixture['tech']->id);

    $timeline = $ticket->statusTimeline();
    expect($timeline->last()->comment_tag)->toBe('assignment');
    expect($timeline->last()->comment)->toContain($fixture['tech']->displayName());

    // Reporter and assignee users were notified via the workflow listener.
    expect(notificationsFor($fixture['reporter']))->toHaveCount(1);
    expect(notificationsFor($fixture['tech']))->toHaveCount(1);
    expect(notificationsFor($fixture['reporter'])[0]['to_status'])->toBe('assigned');
    expect(notificationsFor($fixture['reporter'])[0]['url'])->toContain('/it/tickets/'.$ticket->id);
});

test('reassigning a running ticket swaps the assignee and records a timeline comment', function (): void {
    $fixture = ticketFixture();
    $ticket = makeTicket($fixture);
    $service = app(TicketService::class);
    $actor = Actor::forUser($fixture['admin']);

    $service->assign($ticket, $actor, $fixture['tech']);
    $ticket->refresh();

    $other = Employee::factory()->create(['company_id' => $fixture['company']->id, 'full_name' => 'Olive Other']);
    $result = $service->assign($ticket, $actor, $other);

    expect($result->success)->toBeTrue();
    $ticket->refresh();
    expect($ticket->status)->toBe('assigned');
    expect($ticket->assignee_id)->toBe($other->id);
    expect($ticket->statusTimeline()->last()->comment)->toContain($other->displayName());
});

test('a ticket cannot enter assigned without an assignee', function (): void {
    $fixture = ticketFixture();
    $ticket = makeTicket($fixture);

    $result = $ticket->transitionTo('assigned', new TransitionContext(
        actor: Actor::forUser($fixture['admin']),
    ));

    expect($result->success)->toBeFalse();
    expect($ticket->refresh()->status)->toBe('open');
});

// -- Comments -----------------------------------------------------------------

test('posting a comment notifies reporter and assignee but not the author', function (): void {
    $fixture = ticketFixture();
    $ticket = makeTicket($fixture);
    $service = app(TicketService::class);
    $actor = Actor::forUser($fixture['admin']);

    $service->assign($ticket, $actor, $fixture['tech']);
    $ticket->refresh();

    $service->postComment($ticket, $actor, 'Swapped the toner, still smoking.');

    $reporterNotifications = notificationsFor($fixture['reporter']);
    $latest = end($reporterNotifications);

    expect($latest['kind'])->toBe('comment');
    expect($latest['body'])->toContain('Swapped the toner');
    expect($latest['url'])->toContain('/it/tickets/'.$ticket->id);

    // The author (admin) got nothing.
    $adminNotifications = DB::table('notifications')->where('notifiable_id', $fixture['admin']->id)->count();
    expect($adminNotifications)->toBe(0);
});

// -- Lifecycle actions ---------------------------------------------------------

test('resolving stamps resolved_at and reopening returns the ticket to the queue', function (): void {
    $fixture = ticketFixture();
    $ticket = makeTicket($fixture);
    $service = app(TicketService::class);
    $actor = Actor::forUser($fixture['admin']);

    $service->assign($ticket, $actor, $fixture['tech']);
    $service->transition($ticket->refresh(), $actor, 'in_progress');
    $service->transition($ticket->refresh(), $actor, 'resolved');

    $ticket->refresh();
    expect($ticket->status)->toBe('resolved');
    expect($ticket->resolved_at)->not->toBeNull();

    $service->transition($ticket, $actor, 'open');

    $ticket->refresh();
    expect($ticket->status)->toBe('open');
    expect($ticket->resolved_at)->toBeNull();
    expect($ticket->assignee_id)->toBeNull();
});

// -- Show workspace -------------------------------------------------------------

test('the workspace posts comments and carries the composer into transitions', function (): void {
    $fixture = ticketFixture();
    $ticket = makeTicket($fixture);
    $service = app(TicketService::class);

    $service->assign($ticket, Actor::forUser($fixture['admin']), $fixture['tech']);
    $ticket->refresh();

    Livewire::actingAs($fixture['admin'])
        ->test(Show::class, ['ticket' => $ticket])
        ->set('comment', 'Taking a look now.')
        ->call('postComment')
        ->assertSet('comment', '')
        ->set('comment', 'Starting the fix.')
        ->call('transitionTo', 'in_progress');

    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress');

    $timeline = $ticket->statusTimeline();
    expect($timeline->last()->comment)->toBe('Starting the fix.');
    expect($timeline->slice(-2, 1)->first()->comment)->toBe('Taking a look now.');
});

test('the workspace edits facts in place and assigns via the combobox', function (): void {
    $fixture = ticketFixture();
    $ticket = makeTicket($fixture);

    Livewire::actingAs($fixture['admin'])
        ->test(Show::class, ['ticket' => $ticket])
        ->call('saveField', 'priority', 'critical')
        ->set('assigneeSelection', (string) $fixture['tech']->id);

    $ticket->refresh();
    expect($ticket->priority)->toBe('critical');
    expect($ticket->status)->toBe('assigned');
    expect($ticket->assignee_id)->toBe($fixture['tech']->id);
});

test('the workspace 404s for tickets of another company', function (): void {
    $fixture = ticketFixture();
    $otherCompany = Company::factory()->create();
    $otherReporter = Employee::factory()->create(['company_id' => $otherCompany->id]);
    $ticket = Ticket::query()->create([
        'company_id' => $otherCompany->id,
        'reporter_id' => $otherReporter->id,
        'title' => 'Foreign ticket',
        'status' => 'open',
        'priority' => 'low',
    ]);

    $this->actingAs($fixture['admin'])
        ->get(route('it.tickets.show', $ticket))
        ->assertNotFound();
});

// -- Index lenses and filters ----------------------------------------------------

test('the index scopes the queue by lens and filters', function (): void {
    $fixture = ticketFixture();
    $service = app(TicketService::class);
    $actor = Actor::forUser($fixture['admin']);

    $unassigned = makeTicket($fixture, ['title' => 'Unassigned toner alarm']);
    $mine = makeTicket($fixture, ['title' => 'Mine keyboard sticky']);
    $service->assign($mine, $actor, Employee::query()->findOrFail($fixture['admin']->employee_id));
    $done = makeTicket($fixture, ['title' => 'Done monitor flicker']);
    $service->assign($done, $actor, $fixture['tech']);
    $service->transition($done->refresh(), $actor, 'in_progress');
    $service->transition($done->refresh(), $actor, 'resolved');

    Livewire::actingAs($fixture['admin'])
        ->test(Index::class)
        ->assertSee('Unassigned toner alarm')
        ->assertSee('Mine keyboard sticky')
        ->assertDontSee('Done monitor flicker')
        ->call('setScope', 'unassigned')
        ->assertSee('Unassigned toner alarm')
        ->assertDontSee('Mine keyboard sticky')
        ->call('setScope', 'mine')
        ->assertSee('Mine keyboard sticky')
        ->assertDontSee('Unassigned toner alarm')
        ->call('setScope', 'done')
        ->assertSee('Done monitor flicker')
        ->assertDontSee('Mine keyboard sticky')
        ->call('setScope', 'all')
        ->set('priorityFilter', 'medium')
        ->assertSee('Unassigned toner alarm')
        ->set('priorityFilter', 'critical')
        ->assertDontSee('Unassigned toner alarm');
});

test('the index never shows tickets from other companies', function (): void {
    $fixture = ticketFixture();
    makeTicket($fixture, ['title' => 'Local ticket']);

    $otherCompany = Company::factory()->create();
    $otherReporter = Employee::factory()->create(['company_id' => $otherCompany->id]);
    Ticket::query()->create([
        'company_id' => $otherCompany->id,
        'reporter_id' => $otherReporter->id,
        'title' => 'Foreign secret ticket',
        'status' => 'open',
        'priority' => 'low',
    ]);

    Livewire::actingAs($fixture['admin'])
        ->test(Index::class)
        ->call('setScope', 'all')
        ->assertSee('Local ticket')
        ->assertDontSee('Foreign secret ticket');
});

// -- Board ----------------------------------------------------------------------

test('the board moves tickets along real workflow edges and rejects the rest', function (): void {
    $fixture = ticketFixture();
    $ticket = makeTicket($fixture);
    $service = app(TicketService::class);
    $service->assign($ticket, Actor::forUser($fixture['admin']), $fixture['tech']);
    $ticket->refresh();

    Livewire::actingAs($fixture['admin'])
        ->test(Board::class)
        ->call('moveTicket', $ticket->id, 'in_progress');

    expect($ticket->refresh()->status)->toBe('in_progress');

    // No edge from in_progress to closed: state must not change.
    Livewire::actingAs($fixture['admin'])
        ->test(Board::class)
        ->call('moveTicket', $ticket->id, 'closed');

    expect($ticket->refresh()->status)->toBe('in_progress');
});

test('the board assigns open tickets dropped on Up Next', function (): void {
    $fixture = ticketFixture();
    $ticket = makeTicket($fixture);

    Livewire::actingAs($fixture['admin'])
        ->test(Board::class)
        ->call('assignTicket', $ticket->id, $fixture['tech']->id);

    $ticket->refresh();
    expect($ticket->status)->toBe('assigned');
    expect($ticket->assignee_id)->toBe($fixture['tech']->id);
});

// -- Dashboard widget -------------------------------------------------------------

test('the ticket queue widget shows queue numbers and attention tickets', function (): void {
    $fixture = ticketFixture();
    $service = app(TicketService::class);
    $actor = Actor::forUser($fixture['admin']);

    makeTicket($fixture, ['title' => 'Unassigned critical outage', 'priority' => 'critical']);
    $blocked = makeTicket($fixture, ['title' => 'Blocked on serial number']);
    $service->assign($blocked, $actor, $fixture['tech']);
    $service->transition($blocked->refresh(), $actor, 'in_progress');
    $service->transition($blocked->refresh(), $actor, 'blocked');

    Livewire::actingAs($fixture['admin'])
        ->test(TicketQueue::class)
        ->assertSee('Unassigned critical outage')
        ->assertSee('Blocked on serial number')
        ->assertSee('open');
});

test('the ticket queue widget is discovered for users with ticket access', function (): void {
    $fixture = ticketFixture();

    $definitions = app(DashboardLayout::class)->visibleFor($fixture['admin']);

    expect($definitions->has('operations.it.ticket-queue'))->toBeTrue();
});

test('the board renders kanban columns from the workflow config', function (): void {
    $fixture = ticketFixture();
    makeTicket($fixture, ['title' => 'Column smoke test']);

    Livewire::actingAs($fixture['admin'])
        ->test(Board::class)
        ->assertSee('Up Next')
        ->assertSee('Waiting')
        ->assertSee('Column smoke test');
});
