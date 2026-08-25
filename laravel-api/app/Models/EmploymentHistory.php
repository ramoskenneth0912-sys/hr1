<?php

namespace App\Models;

class EmploymentHistory extends BaseModel
{
    protected $table = 'employment_history';

    // Table has created_at only.
    const UPDATED_AT = null;
}
