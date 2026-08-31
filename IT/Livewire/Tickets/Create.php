<?php

namespace App\Domains\Operation\IT\Livewire\Tickets;

use App\Base\Authz\DTO\Actor;
use App\Core\Employee\Models\Employee;
use App\Domains\Operation\IT\Services\TicketService;
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

    /**
     * The employee this ticket is about, if any — "On behalf of". Prefilled
     * with the filer's own linked employee; empty string means none.
     */
    public string $reporterEmployeeId = '';

    public function mount(): void
    {
        $employeeId = Auth::user()->employee_id;

        $this->reporterEmployeeId = $employeeId !== null ? (string) $employeeId : '';
    }

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
        $actor = Actor::forUser($user);

        $reporter = null;

        if ($this->reporterEmployeeId !== '') {
            $reporter = Employee::query()
                ->where('company_id', $actor->companyId)
                ->find((int) $this->reporterEmployeeId);

            if ($reporter === null) {
                $this->addError('reporterEmployeeId', __('That employee cannot be named on a ticket.'));

                return;
            }
        }

        $ticket = $ticketService->create($actor, $reporter, $validated);

        Session::flash('success', __('Ticket created successfully.'));

        $this->redirect(route('it.tickets.show', $ticket), navigate: true);
    }

    /**
     * Active employees of the acting company, for the "On behalf of" picker.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function reporterOptions(): array
    {
        $companyId = Auth::user()->company_id;

        return Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('full_name')
            ->get()
            ->map(fn (Employee $employee): array => [
                'value' => (string) $employee->id,
                'label' => $employee->displayName(),
            ])
            ->all();
    }

    public function render(): View
    {
        return view('operation-it::livewire.it.tickets.create', [
            'reporterOptions' => $this->reporterOptions(),
        ]);
    }
}
