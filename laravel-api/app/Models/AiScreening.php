<?php

namespace App\Models;

class AiScreening extends BaseModel
{
    protected $table = 'ai_screening';

    // Single timestamp column is screened_at, not created_at/updated_at.
    public $timestamps = false;
}
