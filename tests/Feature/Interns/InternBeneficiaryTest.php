<?php

use App\Models\Intern;
use App\Models\User;

/**
 * The intern create/edit form records a free-text beneficiary reference.
 */
function beneficiaryPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Juan',
        'middle_name' => null,
        'last_name' => 'Dela Cruz',
        'suffix' => null,
        'email' => 'juan.delacruz@example.test',
        'username' => 'juan.delacruz',
        'required_hours' => 486,
        'status' => 'active',
        'contract_start_date' => now()->toDateString(),
        'contract_end_date' => now()->addMonths(3)->toDateString(),
        'beneficiary' => 'GIP-2026-0001 / Juan Dela Cruz',
    ], $overrides);
}

it('shows the beneficiary field on the create form', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('interns.create'));

    $response->assertOk();
    $response->assertSee('Beneficiary');
    $response->assertSee('name="beneficiary"', false);
});

it('stores the beneficiary reference when creating an intern', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('interns.store'), beneficiaryPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('interns.show', Intern::firstOrFail()));

    $intern = Intern::firstOrFail();
    expect($intern->beneficiary)->toBe('GIP-2026-0001 / Juan Dela Cruz');

    // Visible on the intern profile.
    $this->actingAs($admin)
        ->get(route('interns.show', $intern))
        ->assertOk()
        ->assertSee('Beneficiary:')
        ->assertSee('GIP-2026-0001 / Juan Dela Cruz');
});

it('accepts an intern without a beneficiary', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('interns.store'), beneficiaryPayload(['beneficiary' => null]))
        ->assertSessionHasNoErrors();

    expect(Intern::firstOrFail()->beneficiary)->toBeNull();
});

it('updates the beneficiary from the edit form', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('interns.store'), beneficiaryPayload())
        ->assertSessionHasNoErrors();

    $intern = Intern::firstOrFail();

    $this->actingAs($admin)
        ->put(route('interns.update', $intern), beneficiaryPayload([
            'beneficiary' => 'SPES-2026-0042 / Juana D. Cruz',
        ]))
        ->assertSessionHasNoErrors();

    expect($intern->fresh()->beneficiary)->toBe('SPES-2026-0042 / Juana D. Cruz');
});

it('rejects a beneficiary reference longer than 255 characters', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('interns.store'), beneficiaryPayload([
            'beneficiary' => str_repeat('A', 256),
        ]))
        ->assertSessionHasErrors('beneficiary');

    expect(Intern::count())->toBe(0);
});
