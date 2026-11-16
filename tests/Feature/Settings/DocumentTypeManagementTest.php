<?php

use App\Models\DocumentType;
use App\Models\User;

it('updates a document type without relying on a hidden id field', function () {
    $admin = User::factory()->admin()->create();
    $type = DocumentType::create([
        'name' => 'Web Unchanged',
        'code' => 'WEB-UNCHANGED',
        'has_expiry' => false,
    ]);

    // The web resource route binds `{document_type}`; the request must resolve
    // it so an unchanged name/code does not trip the unique rules.
    $this->actingAs($admin)
        ->put(route('settings.document-types.update', $type), [
            'name' => 'Web Unchanged',
            'code' => 'WEB-UNCHANGED',
            'has_expiry' => '1',
        ])
        ->assertRedirect(route('settings.document-types.index'));

    expect($type->fresh()->has_expiry)->toBeTrue();
});

it('still rejects duplicate document type names on update', function () {
    $admin = User::factory()->admin()->create();
    DocumentType::create([
        'name' => 'Taken Name',
        'code' => 'TAKEN',
        'has_expiry' => false,
    ]);
    $target = DocumentType::create([
        'name' => 'Target Name',
        'code' => 'TARGET',
        'has_expiry' => false,
    ]);

    $this->actingAs($admin)
        ->put(route('settings.document-types.update', $target), [
            'name' => 'Taken Name',
            'code' => 'TARGET',
            'has_expiry' => '0',
        ])
        ->assertSessionHasErrors('name');

    expect($target->fresh()->name)->toBe('Target Name');
});
