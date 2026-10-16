<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        DB::table('audit_logs')->truncate();
        DB::table('documents')->truncate();
        DB::table('interns')->truncate();
        DB::table('document_types')->truncate();
        DB::table('users')->truncate();
        DB::table('companies')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS = 1;');

        $this->call(CompanySeeder::class);
        $this->call(UserSeeder::class);
        $this->call(InternDocumentTypeSeeder::class);
    }
}
