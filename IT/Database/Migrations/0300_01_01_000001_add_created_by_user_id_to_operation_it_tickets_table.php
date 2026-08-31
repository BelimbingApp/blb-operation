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
        // employee, and a plain `pluck('users.id', 'operation_it_tickets.id')`
        // over the join would keep whichever row the database returned last
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

        $reporterUserIds = DB::table('operation_it_tickets')
            ->join('employees', 'employees.id', '=', 'operation_it_tickets.reporter_id')
            ->join('users', 'users.employee_id', '=', 'employees.id')
            ->whereIn('operation_it_tickets.id', $ticketIds)
            ->whereIn('operation_it_tickets.id', $reporterUserCounts->filter(fn (int $count): bool => $count === 1)->keys())
            ->pluck('users.id', 'operation_it_tickets.id');

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
