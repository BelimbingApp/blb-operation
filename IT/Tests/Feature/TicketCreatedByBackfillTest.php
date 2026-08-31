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
 */
function ticketCreatedByMigration(): object
{
    return require app_path('Domains/Operation/IT/Database/Migrations/0300_01_01_000001_add_created_by_user_id_to_operation_it_tickets_table.php');
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
    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'no user actor on their opening status-history row and no reporter with a linked user');
});

it('resolves created_by_user_id from the reporter when exactly one user is linked', function (): void {
    $migration = ticketCreatedByMigration();
    $migration->down();

    $company = Company::factory()->create();

    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $onlyUser = User::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);

    $ticketId = DB::table('operation_it_tickets')->insertGetId([
        'company_id' => $company->id,
        'reporter_id' => $employee->id,
        'status' => 'open',
        'priority' => 'medium',
        'title' => 'Unambiguous reporter ticket',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('operation_it_tickets')->where('id', $ticketId)->value('created_by_user_id'))
        ->toBe($onlyUser->id);
});
