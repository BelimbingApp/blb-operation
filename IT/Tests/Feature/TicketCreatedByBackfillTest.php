<?php

use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Exercises the 0300_01_01_000001 migration's reporter-fallback backfill
 * directly, restoring the pre-migration schema, seeding data, and re-running
 * it — the schema is already migrated by the time RefreshDatabase hands
 * control to a test.
 *
 * Steward review (#453, the identical fallback shape in
 * ai_providers.created_by's backfill): `users.employee_id` is nullable and
 * foreign-keyed but not unique, so a reporter's employee can have more than
 * one linked user. The original fallback join+pluck kept whichever row the
 * database happened to return last for that ticket.
 *
 * Every test here that asserts on a resolved id first pushes the ticket id
 * space and the user id space apart, via `burnUserIds()`. Both tables count
 * up from 1 on a fresh database, so a test that creates one user and one
 * ticket gets id 1 for each — and at that point "the reporter's user" and
 * "the ticket itself" are the same number, which is exactly how
 * belimbing#487 (a backfill that mapped every ticket to its own primary key)
 * survived a green suite. Divergent id spaces make that failure visible on
 * SQLite as well as on PostgreSQL, so the default CI lane catches it.
 */
function ticketCreatedByMigration(): object
{
    return require app_path('Domains/Operation/IT/Database/Migrations/0300_01_01_000001_add_created_by_user_id_to_operation_it_tickets_table.php');
}

/**
 * Consume user ids so that the next user created does not share a number
 * with the first ticket created. The users are deliberately unlinked, so
 * they can never be resolved as anybody's filer.
 */
function burnUserIds(Company $company, int $count = 3): void
{
    User::factory()->count($count)->create([
        'company_id' => $company->id,
        'employee_id' => null,
    ]);
}

it('leaves created_by_user_id unresolved (throws) when the reporter employee has more than one linked user', function (): void {
    $migration = ticketCreatedByMigration();
    $migration->down();

    $company = Company::factory()->create();

    $ambiguousEmployee = Employee::factory()->create(['company_id' => $company->id]);
    User::factory()->create(['company_id' => $company->id, 'employee_id' => $ambiguousEmployee->id]);
    User::factory()->create(['company_id' => $company->id, 'employee_id' => $ambiguousEmployee->id]);

    DB::table('operation_it_tickets')->insert([
        'company_id' => $company->id,
        'reporter_id' => $ambiguousEmployee->id,
        'status' => 'open',
        'priority' => 'medium',
        'title' => 'Ambiguous reporter ticket',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // preflightAssignments() throws before the schema change, so down() has
    // nothing to undo — this is the "cannot backfill, refuse loudly" path.
    // The message must say "more than one", not "no ... linked user" — a
    // reporter with several linked users is the opposite problem, and an
    // operator reading the wrong reason would look for the wrong fix
    // (kiat-fc's review on this PR: the two failure classes need distinct,
    // accurate wording, not one message reused for both).
    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'a reporter linked to more than one user');
});

it('names an orphaned ticket with its own accurate reason, distinct from an ambiguous one', function (): void {
    $migration = ticketCreatedByMigration();
    $migration->down();

    $company = Company::factory()->create();
    $orphanedEmployee = Employee::factory()->create(['company_id' => $company->id]);

    DB::table('operation_it_tickets')->insert([
        'company_id' => $company->id,
        'reporter_id' => $orphanedEmployee->id,
        'status' => 'open',
        'priority' => 'medium',
        'title' => 'Orphaned reporter ticket',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'no reporter with a linked user');
});

it('resolves created_by_user_id from the reporter when exactly one user is linked', function (): void {
    $migration = ticketCreatedByMigration();
    $migration->down();

    $company = Company::factory()->create();

    burnUserIds($company);

    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $onlyUser = User::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);

    $ticketId = (int) DB::table('operation_it_tickets')->insertGetId([
        'company_id' => $company->id,
        'reporter_id' => $employee->id,
        'status' => 'open',
        'priority' => 'medium',
        'title' => 'Unambiguous reporter ticket',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Guard the guard: if these two ever coincide again the assertion below
    // stops being able to fail, and the test would go quietly useless.
    expect($ticketId)->not->toBe((int) $onlyUser->id);

    $migration->up();

    expect((int) DB::table('operation_it_tickets')->where('id', $ticketId)->value('created_by_user_id'))
        ->toBe((int) $onlyUser->id);
});

it('resolves created_by_user_id from the opening status-history actor, in preference to the reporter', function (): void {
    $migration = ticketCreatedByMigration();
    $migration->down();

    $company = Company::factory()->create();

    burnUserIds($company);

    // The person the ticket is about, and the (different) person who filed it.
    $reporter = Employee::factory()->create(['company_id' => $company->id]);
    User::factory()->create(['company_id' => $company->id, 'employee_id' => $reporter->id]);

    $filerEmployee = Employee::factory()->create(['company_id' => $company->id]);
    $filer = User::factory()->create(['company_id' => $company->id, 'employee_id' => $filerEmployee->id]);

    $ticketId = (int) DB::table('operation_it_tickets')->insertGetId([
        'company_id' => $company->id,
        'reporter_id' => $reporter->id,
        'status' => 'open',
        'priority' => 'medium',
        'title' => 'Filed on behalf of somebody else',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('base_workflow_status_history')->insert([
        'flow' => 'it_ticket',
        'flow_id' => $ticketId,
        'status' => 'open',
        'actor_id' => $filer->id,
        'actor_type' => 'user',
        'transitioned_at' => now(),
        'created_at' => now(),
    ]);

    expect($ticketId)->not->toBe((int) $filer->id);

    $migration->up();

    expect((int) DB::table('operation_it_tickets')->where('id', $ticketId)->value('created_by_user_id'))
        ->toBe((int) $filer->id);
});
