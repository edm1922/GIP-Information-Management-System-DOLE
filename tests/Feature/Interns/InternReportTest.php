<?php

use App\Models\Company;
use App\Models\Intern;
use App\Models\User;

function partnerAgency(string $name, string $code): Company
{
    return Company::create([
        'name' => $name,
        'code' => $code,
        'is_active' => true,
        'is_partner_agency' => true,
    ]);
}

it('lets admins and peso managers open the internship reports page but blocks other roles', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('reports.intern'))->assertOk();

    $this->actingAs(User::factory()->pesoManager()->create())
        ->get(route('reports.intern'))->assertOk();

    $this->actingAs(User::factory()->encoder()->create())
        ->get(route('reports.intern'))->assertForbidden();

    $this->actingAs(User::factory()->intern()->create())
        ->get(route('reports.intern'))->assertForbidden();

    $this->actingAs(User::factory()->encoder()->create())
        ->get(route('reports.export-interns'))->assertForbidden();
});

it('exports the intern master list as csv', function () {
    $agency = partnerAgency('Tech Corp', 'TECH');
    Intern::factory()->create(['partner_agency_id' => $agency->id, 'status' => 'active']);

    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('reports.export-interns'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    expect($response->streamedContent())->toContain('Tech Corp');
});

it('exports the intern hours summary as csv with a total row', function () {
    Intern::factory()->create(['required_hours' => 486, 'rendered_hours' => 100]);

    $response = $this->actingAs(User::factory()->pesoManager()->create())
        ->get(route('reports.export-intern-hours'));

    $response->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain('Rendered Hours');
    expect($csv)->toContain('TOTAL');
});

it('exports the partner agency summary as csv', function () {
    $agency = partnerAgency('Agency One', 'AG1');
    Intern::factory()->count(2)->create(['partner_agency_id' => $agency->id]);

    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('reports.export-intern-agencies'));

    $response->assertOk();
    expect($response->streamedContent())->toContain('Agency One');
});

it('exports the intern contract expiry report as csv', function () {
    Intern::factory()->create([
        'contract_start_date' => now()->subMonths(3),
        'contract_end_date' => now()->addDays(5),
        'status' => 'active',
    ]);

    $response = $this->actingAs(User::factory()->pesoManager()->create())
        ->get(route('reports.export-intern-expiry', ['expiry_status' => 'expiring']));

    $response->assertOk();
    expect($response->streamedContent())->toContain('Expiring Soon');
});
