<?php

declare(strict_types=1);

use App\Filament\Pages\CrmSegments;
use App\Services\Crm\CrmOperationException;
use App\Services\Crm\CrmSegmentService;
use App\Support\CrmSegmentDefinitionBuilder as Builder;
use Illuminate\Database\QueryException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\CrmAdminFixtures as Admin;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config(['crm.foundation_enabled' => true]);
});

it('creates a segment through the authority and selects it', function () {
    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmSegments::class)
        ->set('newSegmentName', 'Clients fidèles')
        ->call('createSegment');

    $segmentId = $component->get('selectedSegmentId');

    expect($segmentId)->toBeInt();

    $segment = app(CrmSegmentService::class)->segment($segmentId);
    expect($segment['name'])->toBe('Clients fidèles')
        ->and($segment['status'])->toBe('active')
        // A brand-new segment has no version and no generation yet.
        ->and($segment['current_version_number'])->toBeNull()
        ->and($segment['current_generation_id'])->toBeNull();

    $component->assertSet('newSegmentName', '');
});

it('refuses a segment name outside the real 1..120 bound without touching the database', function () {
    test()->actingAs(Admin::admin());

    $before = (int) Fx::owner()->selectOne('SELECT count(*) AS c FROM crm_segments')->c;

    Livewire::test(CrmSegments::class)
        ->set('newSegmentName', '   ')
        ->call('createSegment')
        ->assertSet('selectedSegmentId', null);

    Livewire::test(CrmSegments::class)
        ->set('newSegmentName', str_repeat('a', 121))
        ->call('createSegment')
        ->assertSet('selectedSegmentId', null);

    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM crm_segments')->c)->toBe($before);

    // The authority owns the same rule independently of the UI.
    expect(fn () => app(CrmSegmentService::class)->createSegment(str_repeat('a', 121)))
        ->toThrow(CrmOperationException::class);
});

it('creates and publishes a version, then a second version, from the builder', function () {
    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmSegments::class)
        ->set('newSegmentName', 'Cycle de vie')
        ->call('createSegment');

    $segmentId = $component->get('selectedSegmentId');

    // v1 — a currency-scoped commerce criterion.
    $component->set('matchMode', 'all')
        ->set('criteria', [[
            'field' => 'commerce.net_revenue_minor', 'operator' => 'gte',
            'currency' => 'XOF', 'value' => '1000',
            'lower' => '', 'upper' => '', 'values' => [],
        ]])
        ->call('createVersion')
        ->assertSet('builderError', null);

    $versions = $component->instance()->versions();
    expect($versions)->toHaveCount(1)
        ->and($versions[0]['version_number'])->toBe(1)
        ->and($versions[0]['status'])->toBe('draft');

    $component->call('publishVersion', $versions[0]['version_id']);
    expect($component->instance()->versions()[0]['status'])->toBe('published');

    // v2 — changing the definition NEVER edits v1; it creates a new version.
    $component->set('criteria', [[
        'field' => 'contact.status', 'operator' => 'in',
        'currency' => '', 'value' => '', 'lower' => '', 'upper' => '', 'values' => ['active'],
    ]])->call('createVersion');

    $after = $component->instance()->versions();
    expect($after)->toHaveCount(2)
        ->and($after[0]['version_number'])->toBe(1)
        ->and($after[0]['status'])->toBe('published')
        ->and($after[1]['version_number'])->toBe(2)
        ->and($after[1]['status'])->toBe('draft')
        // v1's stored definition is byte-for-byte what it was.
        ->and($after[0]['definition'])->toContain('net_revenue_minor');
});

/**
 * A version's CONTENT is immutable from the moment it is inserted — the only permitted
 * transition is draft → published. The UI offers no edit and no delete, and the
 * database enforces it regardless of what any client attempts.
 */
it('makes an existing version neither editable nor deletable', function () {
    $definition = Fx::definition(['field' => 'contact.status', 'operator' => 'in', 'values' => ['active']]);
    ['segment_id' => $segmentId, 'version_id' => $versionId] = Fx::createSegmentWithVersion($definition);

    $mutated = json_encode(Fx::definition(['field' => 'contact.origin', 'operator' => 'in', 'values' => ['guest_order']]), JSON_THROW_ON_ERROR);

    // Even the OWNER connection cannot rewrite the definition or drop the row: the
    // P6-A2 immutability trigger raises 23514 regardless of who is connected.
    expect(fn () => Fx::owner()->update(
        'UPDATE crm_segment_versions SET definition = ?::jsonb WHERE id = ?', [$mutated, $versionId],
    ))->toThrow(QueryException::class, 'immutable');

    expect(fn () => Fx::owner()->delete('DELETE FROM crm_segment_versions WHERE id = ?', [$versionId]))
        ->toThrow(QueryException::class);

    // And the admin page exposes no affordance for either operation.
    $page = file_get_contents(app_path('Filament/Pages/CrmSegments.php'));
    $view = file_get_contents(resource_path('views/filament/pages/crm-segments.blade.php'));

    foreach (['updateVersion', 'editVersion', 'deleteVersion', 'destroyVersion'] as $method) {
        expect($page)->not->toContain($method)
            ->and($view)->not->toContain($method);
    }
});

it('reports a refused publication without leaking a database message', function () {
    test()->actingAs(Admin::admin());

    // Publishing a version id that does not exist must be reported, not thrown raw.
    $component = Livewire::test(CrmSegments::class)->call('publishVersion', 999_999);

    expect($component->get('notice'))->toBeString()
        ->and($component->get('notice'))->not->toContain('SQLSTATE')
        ->and($component->get('notice'))->not->toContain('crm_segment_versions');
});

it('surfaces a builder refusal as a field message and creates nothing', function () {
    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmSegments::class)
        ->set('newSegmentName', 'Refus')
        ->call('createSegment');

    $segmentId = $component->get('selectedSegmentId');

    // A commerce criterion with no currency: refused before any database call.
    $component->set('criteria', [[
        'field' => 'commerce.net_revenue_minor', 'operator' => 'gte',
        'currency' => '', 'value' => '1000', 'lower' => '', 'upper' => '', 'values' => [],
    ]])->call('createVersion');

    expect($component->get('builderError'))->toBe('Devise obligatoire au format ISO à trois lettres majuscules.');

    expect((int) Fx::owner()->selectOne(
        'SELECT count(*) AS c FROM crm_segment_versions WHERE segment_id = ?', [$segmentId],
    )->c)->toBe(0);
});

it('requires a selected segment before a version can be created', function () {
    test()->actingAs(Admin::admin());

    Livewire::test(CrmSegments::class)
        ->set('criteria', [[
            'field' => 'contact.status', 'operator' => 'in',
            'currency' => '', 'value' => '', 'lower' => '', 'upper' => '', 'values' => ['active'],
        ]])
        ->call('createVersion')
        ->assertSet('builderError', 'Sélectionnez un segment.');
});

it('resets a criterion row when its field changes kind', function () {
    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmSegments::class)->call('addCriterion');

    // Default row is a contact enum…
    expect($component->get('criteria')[0]['field'])->toBe('contact.status')
        ->and($component->get('criteria')[0]['operator'])->toBe('in');

    // …switching to a commerce number must drop the enum values and pick a valid
    // operator, so the row can never carry a shape from the previous kind.
    $component->set('criteria.0.field', 'commerce.net_revenue_minor');

    expect($component->get('criteria')[0]['operator'])->toBe(Builder::NUMERIC_OPERATORS[0])
        ->and($component->get('criteria')[0]['values'])->toBe([])
        ->and($component->get('criteria')[0]['currency'])->toBe('');

    $component->call('removeCriterion', 0);
    expect($component->get('criteria'))->toBe([]);
});
