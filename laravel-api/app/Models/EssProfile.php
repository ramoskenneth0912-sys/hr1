<?php

namespace App\Models;

class EssProfile extends BaseModel
{
    protected $table = 'ess_profiles';

    // Table has updated_at only.
    const CREATED_AT = null;
}
