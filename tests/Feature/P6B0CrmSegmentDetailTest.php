<?php

declare(strict_types=1);

use App\Filament\Pages\CrmSegments;
use App\Services\Crm\CrmAdminReadService;
use App\Services\Crm\CrmOperationException;
use App\Services\Crm\CrmSegmentService;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\CrmAdminFixtures as Admin;
use Tests\Support\SegmentFixtures as Fx;
use Tests\Support\SourceScanner as Scanner;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config(['crm.foundation_enabled' => true]);
});

it('renders metadata, version history, generation and current members', function () {
    $member = Fx::contact();
    Fx::rollup($member, 'XOF', gross: 50_000);
    $definition = Fx::definition([
        'field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 1_000,
    ]);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion($definition, 'Détail');
    Fx::buildGeneration($segmentId);

    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmSegments::class)->call('selectSegment', $segmentId);
    $page = $component->instance();

    expect($page->selectedSegment()['name'])->toBe($page->selectedSegment()['name'])
        ->and($page->versions())->toHaveCount(1)
        ->and($page->watchedGeneration()['status'])->toBe('published')
        ->and($page->currentMembers())->toBe([$member]);

    $component->assertSee('Historique des versions')
        ->assertSee('Membres courants')
        ->assertSee('Contact #'.$member);
});

/**
 * The stored definition is RENDERED, never re-opened as an editable blob. The reader
 * turns it into labelled sentences so an operator can audit a published version without
 * the page ever offering a JSON or SQL surface.
 */
it('describes a stored definition as read-only labelled rows', function () {
    $definition = Fx::definition([
        'field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 1_000,
    ]);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion($definition);

    test()->actingAs(Admin::admin());

    $page = Livewire::test(CrmSegments::class)->call('selectSegment', $segmentId)->instance();
    $version = $page->versions()[0];
    $lines = $page->describeDefinition($version['definition']);

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toContain('Commerce — revenu net')
        ->and($lines[0])->toContain('supérieur ou égal à')
        ->and($lines[0])->toContain('1000')
        // The currency is always explicit; it is never inferred or dropped.
        ->and($lines[0])->toContain('(XOF)');
});

it('describes an enum and a date criterion without leaking raw JSON', function () {
    $definition = [
        'schema_version' => 1,
        'match' => 'any',
        'criteria' => [
            ['field' => 'contact.status', 'operator' => 'in', 'values' => ['active', 'anonymized']],
            ['field' => 'contact.created_at', 'operator' => 'before', 'value' => '2026-07-21T12:00:00Z'],
        ],
    ];
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion($definition);

    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmSegments::class)->call('selectSegment', $segmentId);
    $lines = $component->instance()->describeDefinition($component->instance()->versions()[0]['definition']);

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toContain('[active, anonymized]')
        ->and($lines[1])->toContain('2026-07-21T12:00:00Z');

    // The raw envelope never reaches the browser.
    $component->assertDontSee('schema_version')
        ->assertDontSee('"criteria"', escape: false);
});

it('returns no description for an absent or malformed definition', function () {
    test()->actingAs(Admin::admin());

    $page = Livewire::test(CrmSegments::class)->instance();

    expect($page->describeDefinition(null))->toBe([])
        ->and($page->describeDefinition('not json'))->toBe([])
        ->and($page->describeDefinition('[]'))->toBe([]);
});

it('reads version history through the B0.1 authority only', function () {
    $definition = Fx::definition(['field' => 'contact.status', 'operator' => 'in', 'values' => ['active']]);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion($definition);

    Fx::owner()->selectOne('SELECT * FROM create_crm_segment_version(?, ?::jsonb)', [
        $segmentId,
        json_encode(Fx::definition(['field' => 'contact.origin', 'operator' => 'in', 'values' => ['guest_order']]), JSON_THROW_ON_ERROR),
    ]);

    test()->actingAs(Admin::admin());

    $versions = app(CrmAdminReadService::class)->segmentVersions($segmentId);

    expect($versions)->toHaveCount(2)
        ->and($versions[0]['version_number'])->toBe(1)
        ->and($versions[0]['status'])->toBe('published')
        ->and($versions[1]['version_number'])->toBe(2)
        ->and($versions[1]['status'])->toBe('draft');

    // Scanned as CODE: the class docblock legitimately says "no DB::table", and a raw
    // text search cannot tell that promise apart from a violation of it.
    $service = Scanner::phpCode(app_path('Services/Crm/CrmAdminReadService.php'));

    expect($service)->toContain('public.list_crm_segment_versions(')
        ->and(Scanner::violations($service, ['DB::table', 'crm_segment_versions AS', '::query()']))->toBe([]);
});

it('shows members of the current generation only', function () {
    $member = Fx::contact();
    Fx::rollup($member, 'XOF', gross: 50_000);
    $outsider = Fx::contact();
    Fx::rollup($outsider, 'XOF', gross: 1);

    $definition = Fx::definition([
        'field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 1_000,
    ]);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion($definition);
    Fx::buildGeneration($segmentId);

    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmSegments::class)->call('selectSegment', $segmentId);

    expect($component->instance()->currentMembers())->toBe([$member]);
    $component->assertSee('Contact #'.$member)->assertDontSee('Contact #'.$outsider);
});

it('returns null for a segment that does not exist and refuses an invalid id', function () {
    test()->actingAs(Admin::admin());

    $service = app(CrmSegmentService::class);

    expect($service->segment(999_999))->toBeNull()
        ->and(fn () => $service->segment(0))->toThrow(CrmOperationException::class);
});
