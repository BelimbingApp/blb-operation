<?php

namespace App\Modules\Operation\IT\Livewire\Tickets;

use App\Base\Authz\DTO\Actor;
use App\Modules\Operation\IT\Services\TicketService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    public string $title = '';

    public string $priority = 'medium';

    public ?string $category = null;

    public ?string $description = null;

    public ?string $location = null;

    public function store(TicketService $ticketService): void
    {
        // Empty selects/inputs arrive as '' — normalize to null so `nullable` applies.
        $this->category = $this->category !== '' ? $this->category : null;
        $this->description = $this->description !== '' ? $this->description : null;
        $this->location = $this->location !== '' ? $this->location : null;

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'priority' => ['required', Rule::in(array_keys(config('it.priorities')))],
            'category' => ['nullable', Rule::in(array_keys(config('it.categories')))],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $user = Auth::user();
        $reporter = $user->employee;

        if (! $reporter) {
            Session::flash('error', __('Your account must be linked to an employee record.'));

            return;
        }

        $actor = Actor::forUser($user);

        $ticket = $ticketService->create($actor, $reporter, $validated);

        Session::flash('success', __('Ticket created successfully.'));

        $this->redirect(route('it.tickets.show', $ticket), navigate: true);
    }

    public function render(): View
    {
        return view('operation-it::livewire.it.tickets.create');
    }
}
