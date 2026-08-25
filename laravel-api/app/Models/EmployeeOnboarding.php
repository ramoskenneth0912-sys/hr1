<?php

namespace App\Models;

class EmployeeOnboarding extends BaseModel
{
    protected $table = 'employee_onboarding';

    // Table has created_at only.
    const UPDATED_AT = null;
}
