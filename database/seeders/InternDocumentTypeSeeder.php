<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class InternDocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Accomplishment Report', 'code' => 'AR', 'has_expiry' => false, 'is_optional' => false],
            ['name' => 'Daily Time Record', 'code' => 'DTR', 'has_expiry' => false, 'is_optional' => false],
            ['name' => 'Resignation Letter', 'code' => 'RESIGN', 'has_expiry' => false, 'is_optional' => true],
            ['name' => 'Certificate', 'code' => 'CERT', 'has_expiry' => false, 'is_optional' => true],
        ];

        foreach ($types as $type) {
            DocumentType::updateOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'has_expiry' => $type['has_expiry'],
                    'is_intern_document' => true,
                    'is_optional' => $type['is_optional'],
                ]
            );
        }
    }
}
