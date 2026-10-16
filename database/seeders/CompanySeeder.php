<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $agencies = [
            ['name' => 'DOLE Provincial Office', 'code' => 'DOLE-PO', 'is_active' => true, 'is_partner_agency' => true],
            ['name' => 'PESO Office - Provincial Capitol', 'code' => 'PESO-CAP', 'is_active' => true, 'is_partner_agency' => true],
            ['name' => 'City Social Welfare and Development Office (CSWDO)', 'code' => 'CSWDO', 'is_active' => true, 'is_partner_agency' => true],
            ['name' => 'Provincial Human Resource Management Office (PHRMO)', 'code' => 'PHRMO', 'is_active' => true, 'is_partner_agency' => true],
        ];

        foreach ($agencies as $agency) {
            Company::firstOrCreate(['code' => $agency['code']], $agency);
        }
    }
}
