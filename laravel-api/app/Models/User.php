<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;

class User extends BaseModel implements AuthenticatableContract
{
    protected $table = 'users';

    protected $hidden = ['password_hash'];

    // ---- Authenticatable contract (getters over existing columns only) ----

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return (int) $this->getAttribute('id');
    }

    /** Returns the EXISTING bcrypt hash untouched — comparison happens
     *  elsewhere, via Hash::check(), and never rewrites it. */
    public function getAuthPassword(): string
    {
        return (string) $this->getAttribute('password_hash');
    }

    /** Name of the existing password column in hr1_database.users. */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /** Legacy schema has no remember_token; inert. */
    public function getRememberToken(): ?string
    {
        return null;
    }

    /** Inert by design — must never write. */
    public function setRememberToken($value): void
    {
        //
    }

    /** Empty name signals "no remember column" to the framework. */
    public function getRememberTokenName(): string
    {
        return '';
    }
}
