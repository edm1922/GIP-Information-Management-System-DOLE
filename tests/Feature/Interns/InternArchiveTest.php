<?php

use App\Models\Intern;
use App\Models\User;

function archiveTestIntern(string $firstName = 'Juan', string $lastName = 'Dela Cruz', string $username = 'juandc'): array
{
    $user = User::factory()->intern()->create([
        'first_name' => $firstName,
        'middle_name' => null,
        'last_name' => $lastName,
        'username' => $username,
    ]);

    $intern = Intern::create([
        'user_id' => $user->id,
        'required_hours' => 486,
        'rendered_hours' => 10,
        'status' => 'active',
    ]);

    return [$user, $intern];
}

it('lets an admin archive an intern and disables the login account', function () {
    $admin = User::factory()->admin()->create();
    [$user, $intern] = archiveTestIntern();

    $this->actingAs($admin)
        ->delete(route('interns.destroy', $intern))
        ->assertRedirect(route('interns.index'))
        ->assertSessionHas('success');

    $this->assertSoftDeleted('interns', ['id' => $intern->id]);
    expect($user->fresh()->is_active)->toBeFalse();
});

it('hides archived interns from the active list but shows them in the archived view', function () {
    $admin = User::factory()->admin()->create();
    [, $intern] = archiveTestIntern();

    $intern->delete();

    $this->actingAs($admin)
        ->get(route('interns.index'))
        ->assertOk()
        ->assertDontSee('Juan Dela Cruz');

    $this->actingAs($admin)
        ->get(route('interns.index', ['archived' => 1]))
        ->assertOk()
        ->assertSee('Juan Dela Cruz')
        ->assertSee('Archived');
});

it('does not let peso managers archive interns', function () {
    $peso = User::factory()->pesoManager()->create();
    [, $intern] = archiveTestIntern();

    $this->actingAs($peso)
        ->delete(route('interns.destroy', $intern))
        ->assertForbidden();

    $this->assertDatabaseHas('interns', ['id' => $intern->id, 'deleted_at' => null]);
});

it('does not let interns archive themselves', function () {
    [$user, $intern] = archiveTestIntern();

    $this->actingAs($user)
        ->delete(route('interns.destroy', $intern))
        ->assertForbidden();

    $this->assertDatabaseHas('interns', ['id' => $intern->id, 'deleted_at' => null]);
});

it('does not expose the archived view to peso managers', function () {
    $peso = User::factory()->pesoManager()->create();
    [, $intern] = archiveTestIntern();

    $intern->delete();

    $this->actingAs($peso)
        ->get(route('interns.index', ['archived' => 1]))
        ->assertOk()
        ->assertDontSee('Juan Dela Cruz');
});

it('lets an admin restore an archived intern and re-enables the login', function () {
    $admin = User::factory()->admin()->create();
    [$user, $intern] = archiveTestIntern();

    $intern->delete();
    $user->update(['is_active' => false]);

    $this->actingAs($admin)
        ->patch(route('interns.restore', $intern->id))
        ->assertRedirect(route('interns.index'))
        ->assertSessionHas('success');

    $this->assertNotSoftDeleted('interns', ['id' => $intern->id]);
    expect($user->fresh()->is_active)->toBeTrue();

    $this->actingAs($admin)
        ->get(route('interns.index'))
        ->assertOk()
        ->assertSee('Juan Dela Cruz');
});

it('lets an admin permanently delete an archived intern and archives its login account', function () {
    $admin = User::factory()->admin()->create();
    [$user, $intern] = archiveTestIntern();

    $intern->delete();

    $this->actingAs($admin)
        ->delete(route('interns.forceDelete', $intern->id))
        ->assertRedirect(route('interns.index'))
        ->assertSessionHas('success');

    // The intern row is gone; the login row is kept soft-deleted so audit
    // history and document authorship stay referentially intact.
    $this->assertDatabaseMissing('interns', ['id' => $intern->id]);
    $this->assertSoftDeleted('users', ['id' => $user->id]);
    expect($user->fresh()->is_active)->toBeFalse();
});

it('shows the archive action on the intern list for admins', function () {
    $admin = User::factory()->admin()->create();
    archiveTestIntern();

    $this->actingAs($admin)
        ->get(route('interns.index'))
        ->assertOk()
        ->assertSee('internConfirmModal', false)
        ->assertSee('Archive intern', false);
});
