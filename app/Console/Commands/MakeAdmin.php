<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Grants (or revokes) access to the operator spend report. The only way to become an admin:
 * there is deliberately no web form for it.
 */
#[Signature('users:make-admin {email : The user\'s email address} {--revoke : Remove admin access instead}')]
#[Description('Give a user access to the operator spend report (or revoke it)')]
class MakeAdmin extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', strtolower((string) $this->argument('email')))->first();

        if ($user === null) {
            $this->error('No user with that email. They must register first.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();

        $this->info($user->is_admin
            ? "{$user->email} can now open the admin report."
            : "{$user->email} is no longer an admin.");

        return self::SUCCESS;
    }
}
