<?php

namespace App\Models;

class ApiToken extends BaseModel
{
    protected $table = 'api_tokens';

    // Table has created_at only.
    const UPDATED_AT = null;

    protected $hidden = ['token_hash'];
}
