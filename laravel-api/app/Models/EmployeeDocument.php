<?php

namespace App\Models;

class EmployeeDocument extends BaseModel
{
    protected $table = 'employee_documents';

    // Table's single timestamp column is uploaded_at, not created_at/updated_at.
    public $timestamps = false;
}
