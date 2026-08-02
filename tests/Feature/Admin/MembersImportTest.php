<?php

use App\Models\Member;
use App\Models\Title;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(fn () => Storage::fake('local'));

function storeImportFile(array $rows, string $filename): UploadedFile
{
    Excel::store(new class($rows) implements FromArray
    {
        public function __construct(private array $rows) {}

        public function array(): array
        {
            return $this->rows;
        }
    }, $filename, 'local');

    return new UploadedFile(Storage::disk('local')->path($filename), $filename, null, null, true);
}

it('requires authentication to download the import template', function () {
    $this->get(route('admin.members.import-template'))->assertRedirect(route('admin.login'));
});

it('downloads an xlsx template with the expected headings', function () {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.members.import-template'));

    $response->assertOk();
    expect($response->headers->get('content-type'))
        ->toContain('spreadsheetml');
});

it('requires authentication to import members', function () {
    $this->post(route('admin.members.import'), [])->assertRedirect(route('admin.login'));
});

it('imports valid rows as club members', function () {
    $rotary = Title::where('name', 'Rotary')->sole();
    $president = $rotary->positions()->where('name', 'Président')->sole();

    $file = storeImportFile([
        ['email', 'name', 'phone', 'club', 'classification', 'titre', 'poste'],
        ['nouvelle.recrue@example.com', 'Nouvelle Recrue', '+229 90 00 00 09', 'RC Cotonou Ife', 'Ingénieur', $rotary->name, $president->name],
    ], 'import-test.xlsx');

    $this->actingAs(User::factory()->create())
        ->post(route('admin.members.import'), ['file' => $file])
        ->assertRedirect(route('admin.members.index'))
        ->assertSessionHas('membersImported', 1);

    $member = Member::where('email', 'nouvelle.recrue@example.com')->sole();

    expect($member->name)->toBe('Nouvelle Recrue')
        ->and($member->is_club_member)->toBeTrue()
        ->and($member->title_id)->toBe($rotary->id)
        ->and($member->position_id)->toBe($president->id);
});

it('reports row errors for missing or invalid data without aborting the batch', function () {
    $rotary = Title::where('name', 'Rotary')->sole();

    $file = storeImportFile([
        ['email', 'name', 'phone', 'club', 'classification', 'titre', 'poste'],
        ['', 'Sans Email', '+229 90 00 00 10', 'RC Cotonou Ife', '', $rotary->name, ''],
        ['organisation.inconnue@example.com', 'Organisation Inconnue', '+229 90 00 00 11', 'RC Cotonou Ife', '', 'Ne Existe Pas', ''],
        ['valide@example.com', 'Personne Valide', '+229 90 00 00 12', 'RC Cotonou Ife', '', $rotary->name, ''],
    ], 'import-errors-test.xlsx');

    $this->actingAs(User::factory()->create())
        ->post(route('admin.members.import'), ['file' => $file])
        ->assertRedirect(route('admin.members.index'))
        ->assertSessionHas('membersImported', 1);

    expect(Member::where('email', 'valide@example.com')->exists())->toBeTrue()
        ->and(Member::where('name', 'Sans Email')->exists())->toBeFalse()
        ->and(Member::where('email', 'organisation.inconnue@example.com')->exists())->toBeFalse();

    expect(session('membersImportErrors'))->toHaveCount(2);
});

it('updates an existing member on import, preserving blank optional fields', function () {
    $rotary = Title::where('name', 'Rotary')->sole();
    $existing = Member::factory()->create([
        'email' => 'existant@example.com',
        'classification' => 'Classification Existante',
        'is_club_member' => false,
    ]);

    $file = storeImportFile([
        ['email', 'name', 'phone', 'club', 'classification', 'titre', 'poste'],
        ['existant@example.com', 'Nom Mis A Jour', '+229 90 00 00 13', 'RC Porto-Novo', '', $rotary->name, ''],
    ], 'import-update-test.xlsx');

    $this->actingAs(User::factory()->create())
        ->post(route('admin.members.import'), ['file' => $file]);

    $existing->refresh();

    expect($existing->name)->toBe('Nom Mis A Jour')
        ->and($existing->club)->toBe('RC Porto-Novo')
        ->and($existing->classification)->toBe('Classification Existante')
        ->and($existing->is_club_member)->toBeTrue();
});

it('ignores fully blank rows', function () {
    $rotary = Title::where('name', 'Rotary')->sole();

    $file = storeImportFile([
        ['email', 'name', 'phone', 'club', 'classification', 'titre', 'poste'],
        ['', '', '', '', '', '', ''],
        ['valide2@example.com', 'Autre Personne', '+229 90 00 00 14', 'RC Cotonou Ife', '', $rotary->name, ''],
    ], 'import-blank-test.xlsx');

    $this->actingAs(User::factory()->create())
        ->post(route('admin.members.import'), ['file' => $file])
        ->assertSessionHas('membersImported', 1);

    expect(session('membersImportErrors'))->toBeEmpty();
});
