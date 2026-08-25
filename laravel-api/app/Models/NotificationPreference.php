<?php

namespace App\Models;

class NotificationPreference extends BaseModel
{
    protected $table = 'notification_preferences';

    // Table has no timestamp columns.
    public $timestamps = false;
}
