<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteAdminUserCommand extends Command
{
    protected $signature = 'user:promote-admin {email : The email address of the user to promote}';

    protected $description = 'Promote an existing user to administrator.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("User with email [{$email}] not found.");

            return self::FAILURE;
        }

        $user->is_admin = true;
        $user->save();

        $this->info("User [{$user->email}] has been promoted to admin.");

        return self::SUCCESS;
    }
}
