<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records who filed a ticket, separately from who it is about.
 *
 * `reporter_id` conflated the two: a user with no linked employee had no way
 * to file a ticket at all. `created_by_user_id` becomes the required filer
 * (creation is user-only — an agent has no ticket-creation tool); `reporter_id`
 * relaxes to an optional subject.
 *
 * ---------------------------------------------------------------------------
 * OPERATORS: stuck on `duplicate column name: created_by_user_id`? Read this.
 * ---------------------------------------------------------------------------
 *
 * If you are here because `php artisan migrate` is failing with a duplicate
 * `created_by_user_id` column, an earlier run of this migration aborted part
 * way through on **SQLite**, and the fix you have just pulled cannot get past
 * the wreckage on its own. This is not caused by the fix. It follows from the
 * released, broken version of this file (belimbing#487), which wrote each
 * ticket's own primary key into `created_by_user_id` and so tripped the
 * foreign key on the first ticket whose id was not also a live user id.
 *
 * Why SQLite specifically: only `PostgresGrammar` sets `$transactions = true`,
 * so `supportsSchemaTransactions()` is false on SQLite. There is no
 * transaction around the migration, so the failure keeps everything that
 * already succeeded. On PostgreSQL the failed statement poisons the
 * enclosing transaction (`25P02`) and the whole migration rolls back, leaving
 * the deployment untouched and simply re-runnable — none of this applies
 * there.
 *
 * What an aborted SQLite run leaves behind (reproduced, not inferred):
 *
 * - `created_by_user_id` exists, still nullable, with its foreign key.
 * - `reporter_id` is already relaxed to nullable — the same `Schema::table()`
 *   call did both, before the backfill.
 * - Some tickets carry a value, some are NULL, and the ones that carry a
 *   value carry the *wrong* one (their own id).
 * - The closing `nullable(false)` never ran.
 * - The migration was **never recorded**: `Migrator::runUp()` logs it only
 *   after `up()` returns, so a re-run tries to add the column again.
 *
 * Recovery: drop the half-applied column, then migrate normally.
 *
 *     ALTER TABLE operation_it_tickets DROP COLUMN created_by_user_id;
 *     php artisan migrate
 *
 * That is enough on its own. `reporter_id` is already nullable, which is
 * where this migration leaves it anyway, and the corrected backfill then runs
 * from a clean state.
 *
 * Do **not** recover by hand-inserting a row into `migrations` to mark this
 * as applied. The backfill never finished, so the column would stay nullable,
 * partially populated, and populated wrongly where it is populated at all —
 * a half-applied schema recorded as complete. `0300_01_01_000002` repairs
 * persisted rows, but it cannot repair a schema that was never finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->lockBackfillInputs();
        $assignments = $this->preflightAssignments();

        Schema::table('operation_it_tickets', function (Blueprint $table): void {
            $table->foreignId('created_by_user_id')->nullable()->after('company_id')->constrained('users');
            $table->foreignId('reporter_id')->nullable()->change();
        });

        foreach ($assignments as $ticketId => $userId) {
            DB::table('operation_it_tickets')->where('id', $ticketId)->update(['created_by_user_id' => $userId]);
        }

        Schema::table('operation_it_tickets', function (Blueprint $table): void {
            $table->foreignId('created_by_user_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('operation_it_tickets', function (Blueprint $table): void {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['created_by_user_id']);
            } else {
                $table->dropForeign('operation_it_tickets_created_by_user_id_foreign');
            }

            $table->dropColumn('created_by_user_id');
            $table->foreignId('reporter_id')->nullable(false)->change();
        });
    }

    /**
     * Who filed each existing ticket: the user actor on its initial `open`
     * status-history row, falling back to the reporter's own linked user.
     * A ticket that resolves neither has no principled filer to assign —
     * fail loudly rather than guess.
     *
     * @return array<int, int> ticket id => user id
     */
    private function preflightAssignments(): array
    {
        $ticketIds = DB::table('operation_it_tickets')->orderBy('id')->pluck('id');

        if ($ticketIds->isEmpty()) {
            return [];
        }

        $openingActors = DB::table('base_workflow_status_history')
            ->where('flow', 'it_ticket')
            ->where('status', 'open')
            ->where('actor_type', 'user')
            ->whereIn('flow_id', $ticketIds)
            ->orderBy('transitioned_at')
            ->orderBy('id')
            ->get(['flow_id', 'actor_id'])
            ->groupBy('flow_id')
            ->map(fn ($rows) => (int) $rows->first()->actor_id);

        // `users.employee_id` is nullable and foreign-keyed but not unique
        // (steward review, #453's identical fallback shape flagged there and
        // fixed here too): more than one user can link to the reporter's
        // employee, and a join that resolved a user per ticket without
        // counting first would keep whichever row the database returned last
        // for that ticket — an arbitrary, silently wrong filer. Counted per
        // ticket first so an ambiguous reporter is treated as unresolved
        // (this migration's own rule, applied consistently) rather than guessed.
        $reporterUserCounts = DB::table('operation_it_tickets')
            ->join('employees', 'employees.id', '=', 'operation_it_tickets.reporter_id')
            ->join('users', 'users.employee_id', '=', 'employees.id')
            ->whereIn('operation_it_tickets.id', $ticketIds)
            ->selectRaw('operation_it_tickets.id as ticket_id, count(*) as user_count')
            ->groupBy('operation_it_tickets.id')
            ->pluck('user_count', 'ticket_id');

        // Both sides of this join have a column called `id`, so the row the
        // driver hands back carries one `id` key and the later column in the
        // select list wins. `pluck('users.id', 'operation_it_tickets.id')`
        // therefore built a ticket => *ticket* map, not a ticket => user map,
        // and every ticket in this fallback class was filed as its own primary
        // key (belimbing#487). Alias both sides so the two ids stay distinct —
        // the same shape #453's sibling backfill already uses.
        $reporterUserIds = DB::table('operation_it_tickets')
            ->join('employees', 'employees.id', '=', 'operation_it_tickets.reporter_id')
            ->join('users', 'users.employee_id', '=', 'employees.id')
            ->whereIn('operation_it_tickets.id', $ticketIds)
            ->whereIn('operation_it_tickets.id', $reporterUserCounts->filter(fn (int $count): bool => $count === 1)->keys())
            ->select('operation_it_tickets.id as ticket_id', 'users.id as user_id')
            ->pluck('user_id', 'ticket_id');

        $assignments = [];
        $orphaned = [];
        $ambiguous = [];

        foreach ($ticketIds as $ticketId) {
            $ticketId = (int) $ticketId;

            $userId = $openingActors->get($ticketId) ?? $reporterUserIds->get($ticketId);

            if ($userId !== null) {
                $assignments[$ticketId] = (int) $userId;

                continue;
            }

            if (($reporterUserCounts->get($ticketId) ?? 0) > 1) {
                $ambiguous[] = $ticketId;
            } else {
                $orphaned[] = $ticketId;
            }
        }

        if ($orphaned !== [] || $ambiguous !== []) {
            // Reviewed on the sibling fix in belimbing#453: one message
            // covering both failure classes was wrong, not merely vague, for
            // whichever class it didn't name — "no reporter with a linked
            // user" is false for a ticket whose reporter has *several*.
            $reasons = [];

            if ($orphaned !== []) {
                $reasons[] = 'ticket(s) ['.implode(', ', $orphaned)
                    .'] have no user actor on their opening status-history row and no reporter with a linked user';
            }

            if ($ambiguous !== []) {
                $reasons[] = 'ticket(s) ['.implode(', ', $ambiguous)
                    .'] have no user actor on their opening status-history row and a reporter linked to more than one user';
            }

            throw new RuntimeException(
                'Cannot backfill operation_it_tickets.created_by_user_id: '
                .implode('; ', $reasons)
                .'. Assign a filer to each before retrying.'
            );
        }

        return $assignments;
    }

    private function lockBackfillInputs(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('LOCK TABLE operation_it_tickets, base_workflow_status_history, employees, users IN SHARE ROW EXCLUSIVE MODE');
    }
};
