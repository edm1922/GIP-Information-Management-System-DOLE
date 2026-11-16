<?php

use App\Models\DocumentType;
use App\Models\Intern;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

uses(
    Tests\TestCase::class,
    Illuminate\Foundation\Testing\RefreshDatabase::class,
)->in(__DIR__ . '/Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create an intern row linked to an existing user with sensible defaults.
 */
function apiCreateIntern(User $user, array $attributes = []): Intern
{
    return Intern::create(array_merge([
        'user_id' => $user->id,
        'required_hours' => 486,
        'rendered_hours' => 0,
        'status' => 'active',
    ], $attributes));
}

/**
 * Create an intern document type for API upload/review tests.
 */
function apiInternDocumentType(string $code = 'DTR'): DocumentType
{
    return DocumentType::create([
        'name' => $code === 'DTR' ? 'Daily Time Record' : 'Accomplishment Report',
        'code' => $code,
        'has_expiry' => false,
        'is_intern_document' => true,
        'is_optional' => false,
    ]);
}
