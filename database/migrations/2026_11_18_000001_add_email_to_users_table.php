<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable so existing accounts (and staff accounts without a contact
        // address) are untouched. Login stays username-based; the email is
        // used for contact and credential delivery.
        if (! Schema::hasColumn('users', 'email')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email', 255)->nullable()->after('username');
            });
        }

        // The original users.email column was renamed to username, which left
        // its `users_email_unique` index on the username column. Use a fresh,
        // explicit index name for the new contact email column.
        $indexNames = collect(Schema::getIndexes('users'))->pluck('name');

        if (! $indexNames->contains('users_email_address_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('email', 'users_email_address_unique');
            });
        }
    }

    public function down(): void
    {
        $indexNames = collect(Schema::getIndexes('users'))->pluck('name');

        if ($indexNames->contains('users_email_address_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_email_address_unique');
            });
        }

        if (Schema::hasColumn('users', 'email')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('email');
            });
        }
    }
};
