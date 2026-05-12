<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    protected $signature   = 'glassportal:create-admin
                                {--name= : Full name of the user}
                                {--email= : Email address}
                                {--role=admin : Role (owner|admin|staff|support)}';

    protected $description = 'Create a GlassPortal staff/admin user from the CLI';

    public function handle(): int
    {
        $this->line('');
        $this->line('  <fg=blue>GlassPortal — Create Admin User</>');
        $this->line('');

        $name  = $this->option('name')  ?? $this->ask('Full name');
        $email = $this->option('email') ?? $this->ask('Email address');
        $role  = $this->option('role');

        // Validate role
        $validRoles = ['owner', 'admin', 'staff', 'support'];
        if (! in_array($role, $validRoles, true)) {
            $this->error("Invalid role '{$role}'. Valid roles: " . implode(', ', $validRoles));
            return self::FAILURE;
        }

        // Validate basic fields
        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users,email']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        // Securely prompt for password (no echo)
        $password = $this->secret('Password (hidden)');

        if (strlen($password) < 12) {
            $this->error('Password must be at least 12 characters.');
            return self::FAILURE;
        }

        $confirm = $this->secret('Confirm password (hidden)');

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');
            return self::FAILURE;
        }

        $user = User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => Hash::make($password),
            'role'     => UserRole::from($role),
        ]);

        $this->line('');
        $this->info("  User created successfully.");
        $this->line("  ID:    {$user->id}");
        $this->line("  Name:  {$user->name}");
        $this->line("  Email: {$user->email}");
        $this->line("  Role:  {$user->role->label()}");
        $this->line('');

        return self::SUCCESS;
    }
}
