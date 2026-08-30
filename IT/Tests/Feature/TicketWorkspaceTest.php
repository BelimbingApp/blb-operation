<?php

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Dashboard\Services\DashboardLayout;
use App\Base\Workflow\DTO\TransitionContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\Operation\IT\Database\Seeders\TicketWorkflowSeeder;
use App\Domains\Operation\IT\Exceptions\TicketMutationDenied;
use App\Domains\Operation\IT\Livewire\Tickets\Board;
use App\Domains\Operation\IT\Livewire\Tickets\Create;
use App\Domains\Operation\IT\Livewire\Tickets\Index;
use App\Domains\Operation\IT\Livewire\Tickets\Show;
use App\Domains\Operation\IT\Livewire\Widgets\TicketQueue;
use App\Domains\Operation\IT\Models\Ticket;
use App\Domains\Operation\IT\Services\TicketService;
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

/** @return array<int, array<string, mixed>> */
function notificationsForUser(User $user): array
{
    return DB::table('notifications')
        ->where('notifiable_id', $user->id)
        ->orderBy('created_at')
        ->get()
        ->map(fn (object $row): array => json_decode($row->data, true))
        ->all();
}

// -- Creation -------------------------------------------------------------------

test('a user with no linked employee can file a ticket, attributed to them', function (): void {
    $fixture = ticketFixture();
    $filer = User::factory()->create(['company_id' => $fixture['company']->id, 'employee_id' => null]);

    Livewire::actingAs($filer)
        ->test(Create::class)
        ->set('title', 'Laptop will not boot')
        ->set('priority', 'high')
        ->set('reporterEmployeeId', '')
        ->call('store')
        ->assertHasNoErrors();

    $ticket = Ticket::query()->where('title', 'Laptop will not boot')->sole();

    expect($ticket->created_by_user_id)->toBe($filer->id)
        ->and($ticket->reporter_id)->toBeNull();
});

test('a user can file on behalf of another employee, and both filer and reporter are recorded', function (): void {
    $fixture = ticketFixture();
    $filer = User::factory()->create(['company_id' => $fixture['company']->id, 'employee_id' => null]);

    Livewire::actingAs($filer)
        ->test(Create::class)
        ->set('title', 'Printer jammed at reception')
        ->set('priority', 'medium')
        ->set('reporterEmployeeId', (string) $fixture['reporter']->id)
        ->call('store')
        ->assertHasNoErrors();

    $ticket = Ticket::query()->where('title', 'Printer jammed at reception')->sole();

    expect($ticket->created_by_user_id)->toBe($filer->id)
        ->and($ticket->reporter_id)->toBe($fixture['reporter']->id);
});

test('the filer receives comment notifications on a ticket they filed for someone else', function (): void {
    $fixture = ticketFixture();
    $filer = User::factory()->create(['company_id' => $fixture['company']->id, 'employee_id' => null]);
    $ticket = app(TicketService::class)->create(
        Actor::forUser($filer),
        $fixture['reporter'],
        ['title' => 'On behalf of ticket', 'priority' => 'medium'],
    );

    app(TicketService::class)->postComment($ticket, Actor::forUser($fixture['admin']), 'Looking into it.');

    $filerNotifications = notificationsForUser($filer);

    expect($filerNotifications)->toHaveCount(1)
        ->and($filerNotifications[0]['kind'])->toBe('comment')
        ->and($filerNotifications[0]['body'])->toContain('Looking into it.');
});

test('the Create form pre-selects the filer\'s own linked employee as reporter', function (): void {
    $fixture = ticketFixture();

    Livewire::actingAs($fixture['admin'])
        ->test(Create::class)
        ->assertSet('reporterEmployeeId', (string) $fixture['admin']->employee_id);
});

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

test('assignment rejects employees from another company and terminal tickets', function (): void {
    $fixture = ticketFixture();
    $ticket = makeTicket($fixture);
    $otherCompany = Company::factory()->create();
    $foreignEmployee = Employee::factory()->create([
        'company_id' => $otherCompany->id,
        'status' => 'active',
    ]);
    $service = app(TicketService::class);
    $actor = Actor::forUser($fixture['admin']);

    expect($service->assign($ticket, $actor, $foreignEmployee)->success)->toBeFalse()
        ->and($ticket->refresh()->status)->toBe('open')
        ->and($ticket->assignee_id)->toBeNull();

    $service->assign($ticket, $actor, $fixture['tech']);
    $service->transition($ticket->refresh(), $actor, 'in_progress');
    $service->transition($ticket->refresh(), $actor, 'resolved');

    expect($service->assign($ticket->refresh(), $actor, $fixture['reporter'])->success)->toBeFalse()
        ->and($ticket->refresh()->status)->toBe('resolved')
        ->and($ticket->assignee_id)->toBe($fixture['tech']->id);
});

test('a denied assignment leaves both status and assignee unchanged', function (): void {
    $fixture = ticketFixture();
    $ticket = makeTicket($fixture);
    $viewer = User::factory()->create(['company_id' => $fixture['company']->id]);

    $result = app(TicketService::class)->assign(
        $ticket,
        Actor::forUser($viewer),
        $fixture['tech'],
    );

    expect($result->success)->toBeFalse()
        ->and($ticket->refresh()->status)->toBe('open')
        ->and($ticket->assignee_id)->toBeNull();
});

test('service mutations reject an actor from another company', function (): void {
    $fixture = ticketFixture();
    $ticket = makeTicket($fixture);
    $otherCompany = Company::factory()->create();
    $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);

    expect(fn () => app(TicketService::class)->postComment(
        $ticket,
        Actor::forUser($otherUser),
        'Cross-company comment',
    ))->toThrow(TicketMutationDenied::class)
        ->and($ticket->statusTimeline())->toHaveCount(1);
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

test('the timeline resolves agent actors in the employee namespace', function (): void {
    $fixture = ticketFixture();
    $ticket = makeTicket($fixture);
    $agent = new Actor(
        type: PrincipalType::AGENT,
        id: $fixture['tech']->id,
        companyId: $fixture['company']->id,
        actingForUserId: $fixture['admin']->id,
    );

    app(TicketService::class)->postComment($ticket, $agent, 'Agent-authored update.');

    Livewire::actingAs($fixture['admin'])
        ->test(Show::class, ['ticket' => $ticket])
        ->assertSee($fixture['tech']->displayName())
        ->assertSee('Agent-authored update.');
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

test('view-only users cannot comment or transition from the workspace or board', function (): void {
    $fixture = ticketFixture();
    $ticket = makeTicket($fixture);
    $service = app(TicketService::class);
    $service->assign($ticket, Actor::forUser($fixture['admin']), $fixture['tech']);
    $ticket->refresh();

    $viewerEmployee = Employee::factory()->create(['company_id' => $fixture['company']->id]);
    $viewer = User::factory()->create([
        'company_id' => $fixture['company']->id,
        'employee_id' => $viewerEmployee->id,
    ]);
    $historyCount = $ticket->statusTimeline()->count();

    Livewire::actingAs($viewer)
        ->test(Show::class, ['ticket' => $ticket])
        ->assertDontSee('Add to the conversation')
        ->set('comment', 'I should not be able to post this.')
        ->call('postComment')
        ->call('transitionTo', 'in_progress');

    Livewire::actingAs($viewer)
        ->test(Board::class)
        ->call('moveTicket', $ticket->id, 'in_progress');

    expect($ticket->refresh()->status)->toBe('assigned')
        ->and($ticket->statusTimeline()->count())->toBe($historyCount);
});

test('the workspace 404s for tickets of another company', function (): void {
    $fixture = ticketFixture();
    $otherCompany = Company::factory()->create();
    $otherReporter = Employee::factory()->create(['company_id' => $otherCompany->id]);
    $otherFiler = User::factory()->create(['company_id' => $otherCompany->id]);
    $ticket = Ticket::query()->create([
        'company_id' => $otherCompany->id,
        'created_by_user_id' => $otherFiler->id,
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
    $otherFiler = User::factory()->create(['company_id' => $otherCompany->id]);
    Ticket::query()->create([
        'company_id' => $otherCompany->id,
        'created_by_user_id' => $otherFiler->id,
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

test('the done board window is based on resolution time rather than later edits', function (): void {
    $fixture = ticketFixture();
    $oldResolution = now()->subDays(15);
    Ticket::query()->create([
        'company_id' => $fixture['company']->id,
        'created_by_user_id' => $fixture['admin']->id,
        'reporter_id' => $fixture['reporter']->id,
        'title' => 'Old resolved ticket edited today',
        'status' => 'resolved',
        'priority' => 'low',
        'resolved_at' => $oldResolution,
    ]);

    Livewire::actingAs($fixture['admin'])
        ->test(Board::class)
        ->assertDontSee('Old resolved ticket edited today');
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
        ->assertSee('Open');
});

test('the ticket queue widget is discovered for users with ticket access', function (): void {
    $fixture = ticketFixture();

    $definitions = app(DashboardLayout::class)->visibleFor($fixture['admin']);

    expect($definitions->has('operations.it.ticket-queue'))->toBeTrue();
});

test('the ticket queue mine count includes assigned work that is waiting', function (): void {
    $fixture = ticketFixture();
    $ticket = makeTicket($fixture);
    $service = app(TicketService::class);
    $actor = Actor::forUser($fixture['admin']);
    $adminEmployee = Employee::query()->findOrFail($fixture['admin']->employee_id);

    $service->assign($ticket, $actor, $adminEmployee);
    $service->transition($ticket->refresh(), $actor, 'in_progress');
    $service->transition($ticket->refresh(), $actor, 'blocked');

    Livewire::actingAs($fixture['admin'])
        ->test(TicketQueue::class)
        ->assertViewHas('stats', fn (array $stats): bool => $stats['mine'] === 1);
});

test('ticket factories keep reporters and assignees inside the ticket company', function (): void {
    $ticket = Ticket::factory()->assigned()->create();

    expect($ticket->reporter->company_id)->toBe($ticket->company_id)
        ->and($ticket->assignee?->company_id)->toBe($ticket->company_id);
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
