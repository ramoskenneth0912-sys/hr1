<?php

namespace App\Models;

class LeaveRequest extends BaseModel
{
    protected $table = 'leave_requests';

    // Table has created_at only.
    const UPDATED_AT = null;
}
