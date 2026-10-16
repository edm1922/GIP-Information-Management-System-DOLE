<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['username' => 'admin'],
            [
                'first_name' => 'Admin',
                'last_name' => 'User',
                'role' => 'admin',
                'password' => Hash::make('admin' . config('brand.password_suffix')),
                'is_active' => true,
                'must_change_password' => false,
            ]
        );

        User::firstOrCreate(
            ['username' => 'peso_manager'],
            [
                'first_name' => 'PESO',
                'last_name' => 'Manager',
                'role' => 'peso_manager',
                'password' => Hash::make('manager' . config('brand.password_suffix')),
                'is_active' => true,
                'must_change_password' => false,
            ]
        );

        $internUser = User::firstOrCreate(
            ['username' => 'juandc'],
            [
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'role' => 'intern',
                'password' => Hash::make('delacruz' . config('brand.password_suffix')),
                'is_active' => true,
                'must_change_password' => true,
            ]
        );

        $agency = \App\Models\Company::where('is_partner_agency', true)->first();

        \App\Models\Intern::firstOrCreate(
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
}
