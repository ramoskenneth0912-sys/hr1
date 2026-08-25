<?php

namespace App\Models;

class OnboardingTask extends BaseModel
{
    protected $table = 'onboarding_tasks';

    // Table has no timestamp columns.
    public $timestamps = false;
}
