<?php

namespace App\Models;

class SystemSetting extends BaseModel
{
    protected $table = 'system_settings';

    // String primary key (setting_key), auto-incrementing int is absent.
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'setting_key';

    // Table has updated_at only.
    const CREATED_AT = null;
}
