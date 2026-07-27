<?php

namespace App\Events;

use App\Models\CoordinatorDistributorAssignment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CoordinatorDistributorAssigned
{
    use Dispatchable, SerializesModels;

    public CoordinatorDistributorAssignment $assignment;

    /**
     * Create a new event instance.
     */
    public function __construct(CoordinatorDistributorAssignment $assignment)
    {
        $this->assignment = $assignment;
    }
}