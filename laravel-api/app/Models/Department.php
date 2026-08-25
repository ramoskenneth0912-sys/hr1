<?php

namespace App\Models;

class Department extends BaseModel
{
    protected $table = 'departments';

    // Table has created_at only.
    const UPDATED_AT = null;
}
