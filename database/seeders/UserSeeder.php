<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Intern;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo accounts are provisioned with random, one-time credentials.
 *
 * SEC-02: seeded credentials are never derived from user data (last name +
 * suffix made them guessable), and every seeded account must change its
 * password on first login. The plaintext values are printed once at seed time.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAccount('admin', 'Admin', 'User', 'admin');
        $this->seedAccount('peso_manager', 'PESO', 'Manager', 'peso_manager');

        $internPassword = $this->randomPassword();

        $internUser = User::firstOrCreate(
            ['username' => 'juandc'],
            [
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'role' => 'intern',
                'password' => Hash::make($internPassword),
                'is_active' => true,
                'must_change_password' => true,
            ]
        );

        if ($internUser->wasRecentlyCreated) {
            $this->announceCredentials('juandc', $internPassword);
        }

        $agency = Company::where('is_partner_agency', true)->first();

        Intern::firstOrCreate(
            ['user_id' => $internUser->id],
            [
                'partner_agency_id' => $agency?->id,
                'contract_start_date' => now()->startOfMonth(),
                'contract_end_date' => now()->addMonths(3)->endOfMonth(),
                'required_hours' => 486,
                'rendered_hours' => 0,
                'status' => 'active',
                'intern_type' => 'GIP',
                'batch' => '2026-A',
                'school' => 'Provincial State College',
                'course' => 'BS Information Technology',
                'contact_number' => '09123456789',
                'supervisor' => 'Engr. Santos',
            ]
        );
    }

    /** Create a staff demo account only when it does not exist yet. */
    private function seedAccount(string $username, string $firstName, string $lastName, string $role): void
    {
        $password = $this->randomPassword();

        $user = User::firstOrCreate(
            ['username' => $username],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $role,
                'password' => Hash::make($password),
                'is_active' => true,
                'must_change_password' => true,
            ]
        );

        if ($user->wasRecentlyCreated) {
            $this->announceCredentials($username, $password);
        }
    }

    private function randomPassword(): string
    {
        return Str::password(16);
    }

    private function announceCredentials(string $username, string $password): void
    {
        $line = sprintf('%s / %s (one-time; must change on first login)', $username, $password);

        if (isset($this->command)) {
            $this->command->warn('Seeded demo account: '.$line);
        } else {
            fwrite(STDERR, 'Seeded demo account: '.$line.PHP_EOL);
        }
    }
}
