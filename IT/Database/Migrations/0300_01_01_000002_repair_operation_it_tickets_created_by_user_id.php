<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Repairs rows that `0300_01_01_000001`'s backfill mis-attributed.
 *
 * That backfill's reporter fallback ended in
 * `pluck('users.id', 'operation_it_tickets.id')` over a join. Both sides of
 * the join have a column called `id`, so the driver handed back a single
 * `id` key and the map came out ticket => ticket. Every ticket that resolved
 * through the fallback was filed as its own primary key (belimbing#487).
 *
 * This migration changes no schema — it is a data repair, so it does not
 * fall under the "edit the original create_* migration in place" rule. A
 * fresh install runs the corrected `000001` and reaches this file with
 * nothing to do.
 *
 * Which rows it will touch, and why that set is safe:
 *
 * - `created_by_user_id = id` is the defect's exact fingerprint. Nothing
 *   else in the codebase writes a ticket's own primary key into that column.
 * - The buggy value only ever came from the *fallback* branch. The primary
 *   branch — the user actor on the ticket's opening `open` status-history
 *   row — was always read through distinct column names and was always
 *   correct. So any ticket that has such a row is skipped outright.
 * - Every ticket-creation path in the application (`TicketService::create()`,
 *   which the Livewire component, the dev seeder and the tools all go
 *   through) writes that opening row inside the same transaction as the
 *   ticket. Tickets created *after* the buggy migration therefore always
 *   have one, and this repair can never reach them, however their ids
 *   happen to line up.
 *
 * What remains is exactly the affected class: tickets that predate the
 * backfill, carry no user actor on an opening row, and were resolved from
 * their reporter's linked user. Recomputing that same fallback — correctly
 * this time — reproduces the value `000001` was supposed to write, so the
 * true filer is recoverable for every row the defect could have damaged.
 *
 * The one class that is not recoverable is a ticket whose reporter has since
 * lost its single linked user, or gained a second one. `created_by_user_id`
 * is NOT NULL with a foreign key, so there is nothing safe to write there
 * and nothing to null it to. Those ids are reported on stderr and left
 * alone rather than guessed at — the same rule `000001` applies when it
 * refuses to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->lockRepairInputs();

        $suspectIds = DB::table('operation_it_tickets')
            ->whereColumn('created_by_user_id', 'id')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);

        if ($suspectIds->isEmpty()) {
            return;
        }

        $ticketIds = $suspectIds->reject(
            fn (int $ticketId): bool => in_array($ticketId, $this->ticketIdsWithOpeningUserActor($suspectIds), true)
        )->values();

        if ($ticketIds->isEmpty()) {
            return;
        }

        $reporterUserCounts = DB::table('operation_it_tickets')
            ->join('employees', 'employees.id', '=', 'operation_it_tickets.reporter_id')
            ->join('users', 'users.employee_id', '=', 'employees.id')
            ->whereIn('operation_it_tickets.id', $ticketIds)
            ->selectRaw('operation_it_tickets.id as ticket_id, count(*) as user_count')
            ->groupBy('operation_it_tickets.id')
            ->pluck('user_count', 'ticket_id');

        $reporterUserIds = DB::table('operation_it_tickets')
            ->join('employees', 'employees.id', '=', 'operation_it_tickets.reporter_id')
            ->join('users', 'users.employee_id', '=', 'employees.id')
            ->whereIn('operation_it_tickets.id', $ticketIds)
            ->whereIn('operation_it_tickets.id', $reporterUserCounts->filter(fn (int $count): bool => $count === 1)->keys())
            ->select('operation_it_tickets.id as ticket_id', 'users.id as user_id')
            ->pluck('user_id', 'ticket_id');

        $repaired = 0;
        $unrecoverable = [];

        foreach ($ticketIds as $ticketId) {
            $userId = $reporterUserIds->get($ticketId);

            if ($userId === null) {
                $unrecoverable[] = $ticketId;

                continue;
            }

            // Where the two id spaces happened to coincide the stored value
            // is already the right one — SQLite installs filled both tables
            // in lockstep often enough that this is the common case there.
            if ((int) $userId === $ticketId) {
                continue;
            }

            DB::table('operation_it_tickets')
                ->where('id', $ticketId)
                ->update(['created_by_user_id' => (int) $userId]);

            $repaired++;
        }

        $this->report($repaired, $unrecoverable);
    }

    /**
     * Data repair only — there is nothing to reverse. Restoring the wrong
     * values would be vandalism, and the rows this touched are
     * indistinguishable afterwards from rows that were always correct.
     */
    public function down(): void {}

    /**
     * Ticket ids that carry a user actor on an `open` status-history row.
     * Those were resolved by `000001`'s primary branch, which never had the
     * ambiguous-column defect, so their stored value is the true filer even
     * when it happens to equal the ticket's own id.
     *
     * @param  Collection<int, int>  $ticketIds
     * @return array<int, int>
     */
    private function ticketIdsWithOpeningUserActor(Collection $ticketIds): array
    {
        return DB::table('base_workflow_status_history')
            ->where('flow', 'it_ticket')
            ->where('status', 'open')
            ->where('actor_type', 'user')
            ->whereIn('flow_id', $ticketIds)
            ->distinct()
            ->pluck('flow_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>  $unrecoverable
     */
    private function report(int $repaired, array $unrecoverable): void
    {
        if ($repaired > 0) {
            fwrite(STDERR, "operation_it_tickets.created_by_user_id repair: corrected {$repaired} ticket(s) that belimbing#487 attributed to their own id.\n");
        }

        if ($unrecoverable !== []) {
            fwrite(STDERR, 'operation_it_tickets.created_by_user_id repair: ticket(s) ['
                .implode(', ', $unrecoverable)
                ."] carry their own id as the filer but no longer resolve to exactly one user through their reporter, so the true filer is not recoverable from the database. Left unchanged — the column is NOT NULL and foreign-keyed, so there is no safe value to write. Reassign these by hand if the attribution matters.\n");
        }
    }

    /**
     * Same reason `000001` locks: the suspect set, the candidate counts and
     * the candidate ids are separate reads, and an affiliation change
     * committed between them would make the repair act on stale input.
     */
    private function lockRepairInputs(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('LOCK TABLE operation_it_tickets, base_workflow_status_history, employees, users IN SHARE ROW EXCLUSIVE MODE');
    }
};
