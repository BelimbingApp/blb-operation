<?php

namespace App\Modules\Operation\IT\Database\Seeders\Dev;

use App\Base\Authz\Database\Seeders\AuthzRoleCapabilitySeeder;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Database\Seeders\DevSeeder;
use App\Base\Workflow\Models\StatusHistory;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Database\Seeders\Dev\DevUserSeeder;
use App\Modules\Core\User\Models\User;
use App\Modules\Operation\IT\Database\Seeders\TicketWorkflowSeeder;
use App\Modules\Operation\IT\Models\Ticket;
use App\Modules\Operation\IT\Services\TicketService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seed a believable IT support queue for the licensee company.
 *
 * Tickets are driven through the real workflow engine (transitions, guards,
 * actions, notifications), then their timeline is backdated so the queue has
 * honest ages: a fresh critical outage, work mid-flight, tickets waiting on
 * parts or people, and a trail of recent wins.
 *
 * Rebuilds the company's tickets from scratch on every run so the scenario
 * stays deterministic in dev.
 */
class DevTicketSeeder extends DevSeeder
{
    private const FLOW = 'it_ticket';

    protected array $dependencies = [
        DevUserSeeder::class,
    ];

    /** @var array<string, Employee> */
    private array $crew = [];

    /** @var array<string, Actor> */
    private array $actors = [];

    private TicketService $tickets;

    protected function seed(): void
    {
        (new TicketWorkflowSeeder)->run();

        $company = $this->licenseeCompany();

        if (! $company) {
            return;
        }

        $this->tickets = app(TicketService::class);
        $this->call(AuthzRoleCapabilitySeeder::class);
        $this->seedCrew($company);
        $this->resetTickets($company);

        foreach ($this->scenarios() as $scenario) {
            $this->seedScenario($company, $scenario);
        }
    }

    /**
     * Ensure the licensee crew members have login users so assignments and
     * notifications behave like production. Password: 'password'.
     */
    private function seedCrew(Company $company): void
    {
        $definitions = [
            'kiat' => ['full_name' => 'Kiat Ng', 'email' => 'kiatng@gmail.com'],
            'aiman' => ['full_name' => 'Aiman Rahman', 'email' => 'aiman.rahman@blb.my'],
            'sofia' => ['full_name' => 'Sofia Lim', 'email' => 'sofia.lim@blb.my'],
            'daniel' => ['full_name' => 'Daniel Khoo', 'email' => 'daniel.khoo@blb.my'],
        ];

        foreach ($definitions as $key => $definition) {
            $employee = Employee::query()->firstOrCreate(
                ['company_id' => $company->id, 'full_name' => $definition['full_name']],
                ['status' => 'active'],
            );

            $employee->update([
                'email' => $definition['email'],
                'status' => 'active',
            ]);

            $user = User::query()->firstOrCreate(
                ['email' => $definition['email']],
                [
                    'company_id' => $company->id,
                    'name' => $definition['full_name'],
                    'password' => 'password',
                    'email_verified_at' => Carbon::now(),
                ],
            );

            // Heal stale dev data deterministically: this email, employee,
            // and company describe one crew identity.
            User::query()
                ->where('employee_id', $employee->id)
                ->where('id', '!=', $user->id)
                ->update(['employee_id' => null]);

            $user->update([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'name' => $definition['full_name'],
                'email_verified_at' => $user->email_verified_at ?? Carbon::now(),
            ]);
            PrincipalRole::query()
                ->where('principal_type', PrincipalType::USER->value)
                ->where('principal_id', $user->id)
                ->where('company_id', '!=', $company->id)
                ->delete();

            $this->grantItAgentRole($user);

            $this->crew[$key] = $employee;
            $this->actors[$key] = Actor::forUser($user);
        }

        $lara = Employee::query()->find(Employee::LARA_ID);

        if ($lara !== null) {
            $this->crew['lara'] = $lara;
            $this->actors['lara'] = new Actor(
                type: PrincipalType::AGENT,
                id: $lara->id,
                companyId: (int) $lara->company_id,
                actingForUserId: $this->actors['kiat']->id,
            );
        }
    }

    /**
     * Give a crew member the IT Agent role so queue work (assigning,
     * updating) passes authz like it will in production.
     */
    private function grantItAgentRole(User $user): void
    {
        $role = Role::query()
            ->whereNull('company_id')
            ->where('code', 'it_agent')
            ->first();

        if ($role === null) {
            return;
        }

        PrincipalRole::query()->firstOrCreate([
            'company_id' => $user->company_id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $user->id,
            'role_id' => $role->id,
        ]);
    }

    /**
     * Drop the company's tickets and their workflow history so every run
     * rebuilds the same deterministic scenario.
     */
    private function resetTickets(Company $company): void
    {
        $ticketIds = Ticket::query()->where('company_id', $company->id)->pluck('id');

        if ($ticketIds->isEmpty()) {
            return;
        }

        StatusHistory::query()
            ->where('flow', self::FLOW)
            ->whereIn('flow_id', $ticketIds)
            ->delete();

        // Notifications deep-link to tickets; drop only rows for this
        // company's ticket ids, leaving other tenants untouched.
        $ticketIdLookup = array_fill_keys($ticketIds->map(fn ($id): string => (string) $id)->all(), true);
        $notificationIds = DB::table('notifications')
            ->select('id', 'data')
            ->where('data', 'like', '%"flow":"'.self::FLOW.'"%')
            ->get()
            ->filter(function (object $notification) use ($ticketIdLookup): bool {
                $data = json_decode((string) $notification->data, true);

                return is_array($data)
                    && ($data['model_type'] ?? null) === Ticket::class
                    && isset($ticketIdLookup[(string) ($data['model_id'] ?? '')]);
            })
            ->pluck('id');

        if ($notificationIds->isNotEmpty()) {
            DB::table('notifications')->whereIn('id', $notificationIds)->delete();
        }

        Ticket::query()->whereKey($ticketIds)->delete();
    }

    /**
     * The queue: every status, every priority, several owners, honest ages.
     *
     * Steps: ['assign', crewKey, actorKey, daysAgo]
     *        ['move', toStatus, actorKey, daysAgo, comment?]
     *        ['comment', actorKey, daysAgo, text, tag?]
     *
     * @return array<int, array<string, mixed>>
     */
    private function scenarios(): array
    {
        return [
            // ---- Open queue -------------------------------------------------
            [
                'title' => 'Email delivery delayed for the whole office',
                'priority' => 'critical', 'category' => 'network',
                'description' => "Outgoing mail is queuing for 30+ minutes. Multiple departments affected.\nStarted around 09:15 this morning.",
                'reporter' => 'daniel', 'age' => 0.02, 'steps' => [],
            ],
            [
                'title' => 'VPN connection drops intermittently',
                'priority' => 'high', 'category' => 'network',
                'description' => 'VPN disconnects every 20-30 minutes when working from home. Reconnect works but interrupts calls.',
                'reporter' => 'sofia', 'age' => 1.1, 'steps' => [],
            ],
            [
                'title' => 'Projector in Meeting Room 3A not displaying',
                'priority' => 'medium', 'category' => 'hardware', 'location' => 'Floor 3 — Meeting Room 3A',
                'description' => 'HDMI input shows "no signal" from any laptop. Power cycling did not help.',
                'reporter' => 'daniel', 'age' => 2.3, 'steps' => [],
            ],
            [
                'title' => 'Access to shared drive \\\\files\\marketing',
                'priority' => 'low', 'category' => 'access',
                'description' => 'New campaign work needs read/write on the marketing share.',
                'reporter' => 'sofia', 'age' => 5.2, 'steps' => [],
            ],
            [
                'title' => 'Request: ergonomic mouse and keyboard',
                'priority' => 'low', 'category' => 'hardware', 'location' => 'Floor 2 — Desk 214',
                'description' => 'Wrist strain — occupational health recommended an ergonomic set.',
                'reporter' => 'daniel', 'age' => 12.5, 'steps' => [],
            ],

            // ---- Up next ----------------------------------------------------
            [
                'title' => 'Replace UPS battery in server rack B',
                'priority' => 'high', 'category' => 'hardware', 'location' => 'Floor 3 — Server Room',
                'description' => 'UPS self-test reports battery below 40% capacity. Replacement is on the shelf.',
                'reporter' => 'kiat', 'age' => 0.8,
                'steps' => [
                    ['assign', 'aiman', 'kiat', 0.2],
                ],
            ],
            [
                'title' => 'Laptop setup for new hire starting Monday',
                'priority' => 'medium', 'category' => 'hardware',
                'description' => 'Standard developer image, docking station, and account provisioning.',
                'reporter' => 'daniel', 'age' => 1.4,
                'steps' => [
                    ['assign', 'sofia', 'kiat', 0.9],
                ],
            ],

            // ---- In progress ------------------------------------------------
            [
                'title' => 'Wi-Fi dead zone near server room entrance',
                'priority' => 'high', 'category' => 'network', 'location' => 'Floor 3 — Server Room corridor',
                'description' => 'No usable signal for about 10 meters of corridor. Handhelds drop off mid-scan.',
                'reporter' => 'daniel', 'age' => 3.2,
                'steps' => [
                    ['assign', 'aiman', 'kiat', 2.9],
                    ['move', 'in_progress', 'aiman', 2.1, 'Site survey done — signal floor is -82 dBm. Testing a relocated AP.'],
                    ['comment', 'aiman', 0.9, 'Temporary AP mounted. Monitoring for 24h before making it permanent.'],
                ],
            ],
            [
                'title' => 'CRM crawls every morning between 9 and 10',
                'priority' => 'medium', 'category' => 'software',
                'description' => 'Page loads take 15-20 seconds during the morning peak, fine after 10am.',
                'reporter' => 'sofia', 'age' => 4.4,
                'steps' => [
                    ['assign', 'daniel', 'kiat', 4.0],
                    ['move', 'in_progress', 'daniel', 3.4, 'Correlates with the nightly report job overrunning into office hours.'],
                ],
            ],
            [
                'title' => 'Roll out new printer driver to Floor 2',
                'priority' => 'low', 'category' => 'software', 'location' => 'Floor 2',
                'description' => 'Old driver crashes on duplex jobs. New package tested OK on three machines.',
                'reporter' => 'kiat', 'age' => 6.5,
                'steps' => [
                    ['assign', 'sofia', 'kiat', 6.0],
                    ['move', 'in_progress', 'sofia', 4.8],
                ],
            ],
            [
                'title' => 'Automate nightly backup verification',
                'priority' => 'medium', 'category' => 'software',
                'description' => 'Verify last night\'s backup set and post the result to the ops channel every morning.',
                'reporter' => 'kiat', 'age' => 2.8,
                'steps' => [
                    ['assign', 'lara', 'kiat', 2.7],
                    ['move', 'in_progress', 'lara', 2.5, 'Picked up — drafting the verification script.', 'agent_progress'],
                    ['comment', 'lara', 1.2, 'Verification script restores a sample file from each set and checks checksums. Dry run passed on 3 of 3 sets.', 'agent_progress'],
                    ['comment', 'lara', 0.4, 'Should the morning summary go to email as well as the ops channel?', 'agent_question'],
                ],
            ],

            // ---- Waiting ----------------------------------------------------
            [
                'title' => 'Laptop overheats and shuts down under load',
                'priority' => 'medium', 'category' => 'hardware',
                'description' => 'Shuts down during video calls and builds. Fan audibly struggling.',
                'reporter' => 'sofia', 'age' => 5.8,
                'steps' => [
                    ['assign', 'aiman', 'kiat', 5.5],
                    ['move', 'in_progress', 'aiman', 5.0],
                    ['move', 'blocked', 'aiman', 3.8, 'Need the service tag from the underside of the laptop to order the fan assembly — please reply with it.'],
                ],
            ],
            [
                'title' => 'Workstation SSD failing SMART checks',
                'priority' => 'high', 'category' => 'hardware', 'location' => 'Floor 1 — Finance',
                'description' => 'Reallocated sector count climbing daily. Machine still boots.',
                'reporter' => 'daniel', 'age' => 7.2,
                'steps' => [
                    ['assign', 'aiman', 'kiat', 7.0],
                    ['move', 'in_progress', 'aiman', 6.5, 'Nightly image backup enabled as a safety net.'],
                    ['move', 'awaiting_parts', 'aiman', 4.2, 'Replacement 1TB NVMe ordered — ETA Thursday.'],
                ],
            ],

            // ---- Review -----------------------------------------------------
            [
                'title' => 'Firewall rule for vendor VPN access',
                'priority' => 'medium', 'category' => 'access',
                'description' => 'Vendor needs site-to-site access to the staging subnet for the integration project. Time-boxed to 90 days.',
                'reporter' => 'daniel', 'age' => 3.9,
                'steps' => [
                    ['assign', 'aiman', 'kiat', 3.6],
                    ['move', 'in_progress', 'aiman', 3.0],
                    ['move', 'review', 'aiman', 0.7, 'Rule staged: vendor /28 to staging subnet, TCP 443 + 5432 only, expires Oct 14. Please double-check the scope.'],
                ],
            ],

            // ---- Recently resolved -------------------------------------------
            [
                'title' => 'Outlook calendar sync broken after update',
                'priority' => 'high', 'category' => 'software',
                'description' => 'Meeting changes not syncing to mobile after the July client update.',
                'reporter' => 'sofia', 'age' => 6.8,
                'steps' => [
                    ['assign', 'daniel', 'kiat', 6.4],
                    ['move', 'in_progress', 'daniel', 5.9],
                    ['move', 'resolved', 'daniel', 1.8, 'Vendor hotfix KB5029871 deployed to all clients. Sync verified on five devices.'],
                ],
            ],
            [
                'title' => 'Guest Wi-Fi captive portal loops on iOS',
                'priority' => 'medium', 'category' => 'network',
                'description' => 'Visitors on iPhones bounce back to the portal after accepting terms.',
                'reporter' => 'kiat', 'age' => 9.5,
                'steps' => [
                    ['assign', 'aiman', 'kiat', 9.1],
                    ['move', 'in_progress', 'aiman', 8.7],
                    ['move', 'resolved', 'aiman', 2.9, 'Portal certificate chain was incomplete — reissued and re-tested on iOS 18.'],
                ],
            ],
            [
                'title' => 'Second monitor flickering at 4K',
                'priority' => 'low', 'category' => 'hardware', 'location' => 'Floor 2 — Desk 207',
                'description' => 'Flickers a few times a minute at 4K60; stable at 30Hz.',
                'reporter' => 'daniel', 'age' => 11.0,
                'steps' => [
                    ['assign', 'sofia', 'kiat', 10.5],
                    ['move', 'in_progress', 'sofia', 10.0],
                    ['move', 'resolved', 'sofia', 8.2, 'Faulty HDMI cable — replaced with certified DP cable.'],
                ],
            ],

            // ---- Closed (older history) --------------------------------------
            [
                'title' => 'New employee laptop setup — Tan Siew Mei',
                'priority' => 'medium', 'category' => 'hardware',
                'reporter' => 'kiat', 'age' => 24.0,
                'steps' => [
                    ['assign', 'sofia', 'kiat', 23.5],
                    ['move', 'in_progress', 'sofia', 23.0],
                    ['move', 'resolved', 'sofia', 21.5, 'Laptop imaged, accounts provisioned, handover done.'],
                    ['move', 'closed', 'kiat', 20.0],
                ],
            ],
            [
                'title' => 'Email password reset for reception account',
                'priority' => 'low', 'category' => 'access',
                'reporter' => 'daniel', 'age' => 30.0,
                'steps' => [
                    ['assign', 'aiman', 'kiat', 29.8],
                    ['move', 'in_progress', 'aiman', 29.7],
                    ['move', 'resolved', 'aiman', 29.5, 'Reset done, MFA re-enrolled.'],
                    ['move', 'closed', 'daniel', 28.0],
                ],
            ],
            [
                'title' => 'Meeting room booking panel frozen',
                'priority' => 'medium', 'category' => 'hardware', 'location' => 'Floor 1 — Room 1B',
                'reporter' => 'sofia', 'age' => 38.0,
                'steps' => [
                    ['assign', 'aiman', 'kiat', 37.5],
                    ['move', 'in_progress', 'aiman', 37.0],
                    ['move', 'resolved', 'aiman', 36.0, 'Panel firmware updated and auto-restart scheduled weekly.'],
                    ['move', 'closed', 'sofia', 34.0],
                ],
            ],
        ];
    }

    /**
     * Create one ticket, drive it through its steps, then backdate the trail.
     *
     * @param  array<string, mixed>  $scenario
     */
    private function seedScenario(Company $company, array $scenario): void
    {
        $reporter = $this->crew[$scenario['reporter']] ?? null;

        if ($reporter === null) {
            return;
        }

        $createdAt = Carbon::now()->subDays($scenario['age']);
        $moments = [$createdAt];

        $ticket = $this->tickets->create($this->actors[$scenario['reporter']], $reporter, [
            'title' => $scenario['title'],
            'priority' => $scenario['priority'],
            'category' => $scenario['category'] ?? null,
            'description' => $scenario['description'] ?? null,
            'location' => $scenario['location'] ?? null,
        ]);

        foreach ($scenario['steps'] as $step) {
            $moments[] = match ($step[0]) {
                'assign' => $this->stepAssign($ticket, $step),
                'move' => $this->stepMove($ticket, $step),
                'comment' => $this->stepComment($ticket, $step),
            };
        }

        $this->backdate($ticket, $moments);
    }

    /**
     * @param  array<int, mixed>  $step  ['assign', crewKey, actorKey, daysAgo]
     */
    private function stepAssign(Ticket $ticket, array $step): Carbon
    {
        [, $crewKey, $actorKey, $daysAgo] = $step;

        $this->tickets->assign($ticket, $this->actors[$actorKey], $this->crew[$crewKey]);
        $ticket->refresh();

        return Carbon::now()->subDays($daysAgo);
    }

    /**
     * @param  array<int, mixed>  $step  ['move', toStatus, actorKey, daysAgo, comment?, tag?]
     */
    private function stepMove(Ticket $ticket, array $step): Carbon
    {
        [, $toStatus, $actorKey, $daysAgo] = $step;

        $this->tickets->transition($ticket, $this->actors[$actorKey], $toStatus, $step[4] ?? null, $step[5] ?? null);
        $ticket->refresh();

        return Carbon::now()->subDays($daysAgo);
    }

    /**
     * @param  array<int, mixed>  $step  ['comment', actorKey, daysAgo, text, tag?]
     */
    private function stepComment(Ticket $ticket, array $step): Carbon
    {
        [, $actorKey, $daysAgo, $text] = $step;

        $this->tickets->postComment($ticket, $this->actors[$actorKey], $text, $step[4] ?? null);

        return Carbon::now()->subDays($daysAgo);
    }

    /**
     * Rewrite the trail's timestamps so ages read true: history rows get
     * their planned moments (TAT recomputed), the ticket's created/updated
     * and resolution stamps follow.
     *
     * @param  array<int, Carbon>  $moments  One per history row, in id order.
     */
    private function backdate(Ticket $ticket, array $moments): void
    {
        $rows = StatusHistory::query()
            ->where('flow', self::FLOW)
            ->where('flow_id', $ticket->id)
            ->orderBy('id')
            ->get();

        $resolvedAt = null;
        $previousMoment = null;

        foreach ($rows as $index => $row) {
            $moment = $moments[$index] ?? null;

            if ($moment === null) {
                continue;
            }

            $row->timestamps = false;
            $row->transitioned_at = $moment;

            if ($row->tat !== null && $previousMoment !== null) {
                $row->tat = max(0, (int) $previousMoment->diffInSeconds($moment));
            }

            $row->save();

            if ($row->status === 'resolved' && $row->comment_tag !== 'assignment') {
                $resolvedAt = $moment;
            }

            $previousMoment = $moment;
        }

        Ticket::query()->whereKey($ticket->id)->update([
            'created_at' => $moments[0],
            'updated_at' => end($moments) ?: $moments[0],
            'resolved_at' => $ticket->resolved_at !== null ? $resolvedAt : null,
        ]);
    }
}
