<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * May this person use the operations console?
     *
     * Filament refuses panel access to any user model that does not implement FilamentUser unless
     * app.env is 'local'. Without this method the console worked on the developer's machine and 403'd for
     * every operator the moment APP_ENV was anything else -- production included -- and nothing in the
     * test suite noticed, because the test environment is not 'local' either but no test asked.
     *
     * The rule is that being in this table IS the grant. There is no role column and no second table,
     * deliberately: an operations account is created by somebody who already decided this person needs
     * one, and every state change in the console is recorded against their identity. A trader has a row
     * in `trader`, not here, so they cannot reach this at all -- OpsConsoleTest asserts that.
     *
     * An unverified address is refused. A staff account whose email has never been confirmed is an
     * account somebody typed rather than one anybody owns.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->email_verified_at !== null;
    }
}
