<?php

use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Exercises the 0300_01_01_000002 repair migration against the damaged state
 * belimbing#487 leaves behind: `created_by_user_id` holding the ticket's own
 * primary key instead of the filer's user id.
 *
 * The damage is staged directly rather than by re-running the old backfill —
 * the backfill is fixed now, so the only honest way to test the repair is to
 * put the database into the state an already-upgraded deployment is in.
 *
 * Every test pushes the ticket id space and the user id space apart first
 * (`stageDivergentUserIds()`). On a fresh database both tables count up from
 * 1, and while ticket id and user id are the same number the defect is
 * invisible — which is how it reached production.
 */
function ticketCreatedByRepairMigration(): object
{
    return require app_path('Domains/Operation/IT/Database/Migrations/0300_01_01_000002_repair_operation_it_tickets_created_by_user_id.php');
}

/**
 * Consume user ids so the next user created cannot share a number with the
 * first ticket created. These users link to no employee, so they are never
 * resolvable as anybody's filer — but they do make the ticket's own id a
 * *valid* user id, which is what turns the defect from a foreign-key error
 * into silent misattribution.
 */
function stageDivergentUserIds(Company $company, int $count = 3): void
{
    User::factory()->count($count)->create([
        'company_id' => $company->id,
        'employee_id' => null,
    ]);
}

/**
 * Guarantee a user exists carrying exactly this id.
 *
 * The defect can only corrupt data silently where the ticket's own id also
 * happens to be a live user id. The column is foreign-keyed and
 * `foreign_key_constraints` defaults to true, so on *both* drivers — SQLite
 * as much as PostgreSQL — anywhere else the buggy backfill raised a
 * foreign-key violation and the migration aborted instead. Only the odds of
 * the coincidence differ: a fresh SQLite database counts both tables up from
 * 1, so it hits it constantly, while PostgreSQL's sequences drift apart.
 * Staging the damaged state therefore means staging that coincidence
 * explicitly rather than waiting for a driver to hand it over.
 */
function ensureUserWithId(Company $company, int $id): void
{
    if (DB::table('users')->where('id', $id)->exists()) {
        return;
    }

    $attributes = User::factory()->make([
        'company_id' => $company->id,
        'employee_id' => null,
    ])->getAttributes();

    DB::table('users')->insert($attributes + [
        'id' => $id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Insert a ticket already carrying the defect: filed as its own id.
 */
function insertMisattributedTicket(Company $company, ?Employee $reporter, string $title): int
{
    $ticketId = (int) DB::table('operation_it_tickets')->insertGetId([
        'company_id' => $company->id,
        'created_by_user_id' => User::query()->orderBy('id')->value('id'),
        'reporter_id' => $reporter?->id,
        'status' => 'open',
        'priority' => 'medium',
        'title' => $title,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    ensureUserWithId($company, $ticketId);

    DB::table('operation_it_tickets')
        ->where('id', $ticketId)
        ->update(['created_by_user_id' => $ticketId]);

    return $ticketId;
}

function storedFiler(int $ticketId): int
{
    return (int) DB::table('operation_it_tickets')->where('id', $ticketId)->value('created_by_user_id');
}

it('restores the reporter\'s user on a ticket that was filed as its own id', function (): void {
    $company = Company::factory()->create();
    stageDivergentUserIds($company);

    $reporter = Employee::factory()->create(['company_id' => $company->id]);
    $trueFiler = User::factory()->create(['company_id' => $company->id, 'employee_id' => $reporter->id]);

    $ticketId = insertMisattributedTicket($company, $reporter, 'Misattributed ticket');

    expect($ticketId)->not->toBe((int) $trueFiler->id)
        ->and(storedFiler($ticketId))->toBe($ticketId);

    ticketCreatedByRepairMigration()->up();

    expect(storedFiler($ticketId))->toBe((int) $trueFiler->id);
});

it('leaves a ticket alone when its opening status-history actor confirms the stored filer', function (): void {
    $company = Company::factory()->create();
    stageDivergentUserIds($company);

    // The reporter resolves to somebody else entirely — a ticket filed on
    // behalf of another employee. If the repair went by the fallback alone it
    // would overwrite a perfectly good attribution here.
    $reporter = Employee::factory()->create(['company_id' => $company->id]);
    User::factory()->create(['company_id' => $company->id, 'employee_id' => $reporter->id]);

    $ticketId = insertMisattributedTicket($company, $reporter, 'Genuinely filed by the user whose id matches');

    DB::table('base_workflow_status_history')->insert([
        'flow' => 'it_ticket',
        'flow_id' => $ticketId,
        'status' => 'open',
        'actor_id' => $ticketId,
        'actor_type' => 'user',
        'transitioned_at' => now(),
        'created_at' => now(),
    ]);

    ticketCreatedByRepairMigration()->up();

    expect(storedFiler($ticketId))->toBe($ticketId);
});

it('leaves an unrecoverable ticket unchanged rather than guessing a filer', function (): void {
    $company = Company::factory()->create();
    stageDivergentUserIds($company);

    // Two users now link to the reporter's employee, so the fallback that
    // originally produced this row no longer resolves to one answer. There is
    // nothing to recover and nothing to null to: the column is NOT NULL with
    // a foreign key. The migration reports these ids and leaves them.
    $reporter = Employee::factory()->create(['company_id' => $company->id]);
    User::factory()->count(2)->create(['company_id' => $company->id, 'employee_id' => $reporter->id]);

    $ticketId = insertMisattributedTicket($company, $reporter, 'Unrecoverable ticket');

    ticketCreatedByRepairMigration()->up();

    expect(storedFiler($ticketId))->toBe($ticketId);
});
