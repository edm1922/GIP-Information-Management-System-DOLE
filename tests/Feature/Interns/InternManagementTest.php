<?php

use App\Models\Intern;
use App\Models\User;

it('lets an admin create an intern together with a login account', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post(route('interns.store'), [
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'juandc@example.test',
        'username' => 'juandc',
        'required_hours' => 486,
        'status' => 'active',
    ]);

    $intern = Intern::first();

    expect($intern)->not->toBeNull();
    $response->assertRedirect(route('interns.show', $intern));

    $user = User::where('username', 'juandc')->first();
    expect($user)->not->toBeNull();
    expect($user->role)->toBe('intern');
    expect($user->must_change_password)->toBeTrue();
    expect((int) $intern->user_id)->toBe((int) $user->id);
});

it('allows admins and peso managers to browse interns but blocks other roles', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('interns.index'))->assertOk();

    $this->actingAs(User::factory()->pesoManager()->create())
        ->get(route('interns.index'))->assertOk();

    $this->actingAs(User::factory()->intern()->create())
        ->get(route('interns.index'))->assertForbidden();
});

it('only lets admins open the create intern form', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('interns.create'))->assertOk();

    $this->actingAs(User::factory()->pesoManager()->create())
        ->get(route('interns.create'))->assertForbidden();
});

it('renders the self-service internship page for an assigned intern', function () {
    $user = User::factory()->intern()->create();
    Intern::create([
        'user_id' => $user->id,
        'required_hours' => 486,
        'rendered_hours' => 10,
        'status' => 'active',
    ]);

    $this->actingAs($user)->get(route('my-internship.index'))->assertOk();
});

it('shows an unassigned notice when an intern has no record', function () {
    $user = User::factory()->intern()->create();

    $this->actingAs($user)
        ->get(route('my-internship.index'))
        ->assertOk()
        ->assertSee('No Internship Record Yet');
});

it('renders the dashboard directly for an assigned intern without redirecting', function () {
    $user = User::factory()->intern()->create([
        'first_name' => 'Maria',
        'middle_name' => null,
        'last_name' => 'Clara',
    ]);
    Intern::create([
        'user_id' => $user->id,
        'required_hours' => 486,
        'rendered_hours' => 120,
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Intern Portal')
        ->assertSee('Maria Clara')
        ->assertSee('Hours Rendered')
        ->assertSee('120');
});

it('renders the dashboard for an intern without an assigned record', function () {
    $user = User::factory()->intern()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('No Internship Record Assigned Yet');
});
