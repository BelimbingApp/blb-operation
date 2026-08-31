<?php

namespace App\Domains\Operation\IT\Database\Factories;

use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\Operation\IT\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Model>
     */
    protected $model = Ticket::class;

    public function configure(): static
    {
        return $this->afterCreating(function (Ticket $ticket): void {
            if ($ticket->reporter_id !== null
                && (int) Employee::query()->whereKey($ticket->reporter_id)->value('company_id') !== (int) $ticket->company_id) {
                $ticket->reporter_id = Employee::factory()->create(['company_id' => $ticket->company_id])->id;
            }

            if ($ticket->assignee_id !== null
                && (int) Employee::query()->whereKey($ticket->assignee_id)->value('company_id') !== (int) $ticket->company_id) {
                $ticket->assignee_id = Employee::factory()->create(['company_id' => $ticket->company_id])->id;
            }

            $ticket->save();
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'created_by_user_id' => User::factory(),
            'reporter_id' => Employee::factory(),
            'assignee_id' => null,
            'status' => 'open',
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'category' => fake()->randomElement(['hardware', 'software', 'network', 'access', 'other']),
            'title' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'location' => fake()->optional()->randomElement([
                'Floor 1 - Room 101',
                'Floor 2 - Room 205',
                'Floor 3 - Server Room',
                'Floor 4 - Open Office',
                'Building B - Lab',
            ]),
            'metadata' => null,
        ];
    }

    /**
     * Indicate that the ticket has high priority.
     */
    public function highPriority(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'priority' => 'high',
            ],
        );
    }

    /**
     * Indicate that the ticket has critical priority.
     */
    public function critical(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'priority' => 'critical',
            ],
        );
    }

    /**
     * Indicate that the ticket has no reporter named — filed by a user with
     * no linked employee, on their own behalf.
     */
    public function unreported(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'reporter_id' => null,
            ],
        );
    }

    /**
     * Indicate that the ticket has been assigned to an employee.
     */
    public function assigned(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'assignee_id' => Employee::factory(),
                'status' => 'assigned',
            ],
        );
    }
}
