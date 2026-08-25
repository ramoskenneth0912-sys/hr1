<?php

namespace App\Models;

class Interview extends BaseModel
{
    protected $table = 'interviews';

    // Table has created_at only.
    const UPDATED_AT = null;
}
