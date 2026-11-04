<?php

use App\Models\User;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

it('shows today in the dashboard date pill while viewing the current month', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-04 10:00:00'));

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Nov 04, 2026')
        ->assertSee('November 2026');
});

it('shows the first of the browsed month in the pill when navigating away', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-04 10:00:00'));

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard', ['cal_date' => '2026-10-15']))
        ->assertOk()
        ->assertSee('Oct 01, 2026')
        ->assertSee('October 2026');
});

it('highlights today inside the grid for the current month', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-04 10:00:00'));

    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertOk();

    // November 2026 starts on a Sunday and the 4th is a Wednesday.
    expect($response->getContent())
        ->toContain('calendar-day current today');
});
