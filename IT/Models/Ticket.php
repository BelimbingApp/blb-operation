<?php

namespace App\Modules\Operation\IT\Models;

use App\Base\Workflow\Concerns\HasWorkflowStatus;
use App\Base\Workflow\Contracts\PresentsWorkflowNotifications;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Operation\IT\Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * IT Ticket — first business module using the workflow engine.
 *
 * @property int $id
 * @property int $company_id
 * @property int $reporter_id
 * @property int|null $assignee_id
 * @property string $status
 * @property string $priority
 * @property string|null $category
 * @property string $title
 * @property string|null $description
 * @property string|null $location
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read Employee $reporter
 * @property-read Employee|null $assignee
 */
class Ticket extends Model implements PresentsWorkflowNotifications
{
    use HasFactory, HasWorkflowStatus;

    /**
     * The workflow flow identifier for IT tickets.
     */
    public const string FLOW = 'it_ticket';

    /** @var list<string> */
    public const array OPEN_STATUSES = ['open', 'assigned', 'in_progress', 'blocked', 'awaiting_parts', 'review'];

    /** @var list<string> */
    public const array DONE_STATUSES = ['resolved', 'closed'];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'operation_it_tickets';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'reporter_id',
        'assignee_id',
        'status',
        'priority',
        'category',
        'title',
        'description',
        'location',
        'metadata',
        'resolved_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'json',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Return the flow identifier for this model.
     */
    public function flow(): string
    {
        return self::FLOW;
    }

    /**
     * Short human title for notification lists.
     */
    public function workflowNotificationTitle(): string
    {
        return sprintf('#%d %s', $this->id, $this->title);
    }

    /**
     * Deep link to the ticket page.
     */
    public function workflowNotificationUrl(): ?string
    {
        return route('it.tickets.show', $this);
    }

    /**
     * Portable CASE expression ranking priorities by severity.
     */
    public static function priorityRankSql(string $column = 'priority'): string
    {
        return "case {$column} when 'critical' then 4 when 'high' then 3 when 'medium' then 2 when 'low' then 1 else 0 end";
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TicketFactory
    {
        return TicketFactory::new();
    }

    /**
     * @return array{name: string, id: int}|null
     */
    public function getAuditSubject(): ?array
    {
        return $this->id !== null ? ['name' => 'ticket', 'id' => (int) $this->id] : null;
    }

    /**
     * Get the company that owns this ticket.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the employee who reported this ticket.
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reporter_id');
    }

    /**
     * Get the employee currently assigned to this ticket.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_id');
    }
}
