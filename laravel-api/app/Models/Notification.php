<?php

namespace App\Models;

class Notification extends BaseModel
{
    protected $table = 'notifications';

    // Table has created_at only.
    const UPDATED_AT = null;
}
