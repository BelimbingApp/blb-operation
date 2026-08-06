<?php

namespace App\Domains\Operation\Quality\Models\Concerns;

use App\Domains\Operation\Quality\Models\QualityEvent;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasQualityEvents
{
    /**
     * Get the domain events for this quality record.
     */
    abstract protected function qualityEventForeignKey(): string;

    /**
     * Get the domain events for this quality record.
     */
    public function events(): HasMany
    {
        return $this->hasMany(QualityEvent::class, $this->qualityEventForeignKey());
    }
}
