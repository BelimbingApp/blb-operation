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

        $reporterUserIds = DB::table('operation_it_tickets')
            ->join('employees', 'employees.id', '=', 'operation_it_tickets.reporter_id')
            ->join('users', 'users.employee_id', '=', 'employees.id')
            ->whereIn('operation_it_tickets.id', $ticketIds)
            ->pluck('users.id', 'operation_it_tickets.id');

        $assignments = [];
        $unresolved = [];

        foreach ($ticketIds as $ticketId) {
            $ticketId = (int) $ticketId;

            $userId = $openingActors->get($ticketId) ?? $reporterUserIds->get($ticketId);

            if ($userId === null) {
                $unresolved[] = $ticketId;

                continue;
            }

            $assignments[$ticketId] = (int) $userId;
        }

        if ($unresolved !== []) {
            throw new RuntimeException(
                'Cannot backfill operation_it_tickets.created_by_user_id: ticket(s) ['
                .implode(', ', $unresolved)
                .'] have no user actor on their opening status-history row and no reporter with a linked user.'
                .' Assign a filer to each before retrying.'
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
