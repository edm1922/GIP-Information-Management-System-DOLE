<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESTRUCTIVE: Strip the legacy CSC 201-Files / Department Document
 * Management subsystem and the Department entity, leaving GIMS-only schema.
 *
 * Data belonging to these tables/columns is dropped and cannot be restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // --- documents: remove department / 201-files columns ---
            $this->dropForeignIfExists('documents', 'department_id');
            $this->dropForeignIfExists('documents', 'document_folder_id');
            $this->dropForeignIfExists('documents', 'document_location_id');
            $this->dropForeignIfExists('documents', 'folder_location_id');
            $this->dropIndexIfExists('documents', 'docs_dept_type_status_idx');

            Schema::table('documents', function (Blueprint $table) {
                foreach (['department_id', 'document_folder_id', 'document_location_id', 'folder_location_id', 'upload_mode', 'source_filenames'] as $column) {
                    if (Schema::hasColumn('documents', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });

            // --- document_types: remove department ownership ---
            $this->dropForeignIfExists('document_types', 'department_id');

            Schema::table('document_types', function (Blueprint $table) {
                if (Schema::hasColumn('document_types', 'department_id')) {
                    $table->dropColumn('department_id');
                }
            });

            // --- interns: remove host department ---
            $this->dropForeignIfExists('interns', 'host_department_id');

            Schema::table('interns', function (Blueprint $table) {
                if (Schema::hasColumn('interns', 'host_department_id')) {
                    $table->dropColumn('host_department_id');
                }
            });
        }

        // --- drop legacy tables (children before parents) ---
        foreach ([
            'hiring_events',
            'document_expiry_notifications',
            'department_folder_sequences',
            'document_folders',
            'document_locations',
            'employees',
            'folders',
            'folder_locations',
            'company_folder_sequences',
            'bank_types',
            'department_user_access',
            'departments',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Destructive migration: schema and data are not restored.
    }

    private function dropForeignIfExists(string $table, string $column): void
    {
        if (DB::getDriverName() === 'sqlite' || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
            });
        } catch (\Throwable $e) {
            // Foreign key may not exist; safe to continue.
        }
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (DB::getDriverName() === 'sqlite' || ! Schema::hasTable($table)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($index) {
                $blueprint->dropIndex($index);
            });
        } catch (\Throwable $e) {
            // Index may not exist; safe to continue.
        }
    }
};
