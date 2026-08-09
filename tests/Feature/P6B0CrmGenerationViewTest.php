<?php

declare(strict_types=1);

use App\Filament\Pages\CrmSegments;
use App\Services\Crm\CrmSegmentService;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\CrmAdminFixtures as Admin;
use Tests\Support\SegmentFixtures as Fx;
use Tests\Support\SourceScanner as Scanner;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config([
        'crm.foundation_enabled' => true,
        'crm.segment_rebuild.enabled' => false,
        'crm.segment_rebuild.processing_enabled' => false,
    ]);
});

function p6b0SegmentWithMember(): array
{
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', gross: 50_000);

    $definition = Fx::definition([
        'field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 1_000,
    ]);

    return Fx::createSegmentWithVersion($definition) + ['contact_id' => $contactId];
}

it('renders the generation state fields the authority exposes', function () {
    ['segment_id' => $segmentId] = p6b0SegmentWithMember();
    $generationId = Fx::buildGeneration($segmentId);

    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmSegments::class)->call('selectSegment', $segmentId);
    $generation = $component->instance()->watchedGeneration();

    expect($generation['generation_id'])->toBe($generationId)
        ->and($generation['status'])->toBe('published')
        ->and($generation['members_count'])->toBe(1)
        ->and($generation['contact_id_high_water_mark'])->toBeGreaterThan(0)
        ->and($generation['last_error_code'])->toBeNull();

    $component->assertSee('Génération')
        ->assertSee('Publiée')
        ->assertSee('Borne de population');
});

/**
 * The rebuild flag is the REAL P6-A2 one (`crm.segment_rebuild.enabled`), the same flag
 * `crm:rebuild-segment` obeys. Disabling it must not merely grey out a button: the
 * server has to refuse independently, or a crafted Livewire call would still build.
 */
it('refuses a rebuild and creates no generation while the flag is disabled', function () {
    ['segment_id' => $segmentId] = p6b0SegmentWithMember();

    test()->actingAs(Admin::admin());

    $before = (int) Fx::owner()->selectOne('SELECT count(*) AS c FROM crm_segment_generations')->c;

    $component = Livewire::test(CrmSegments::class)->call('selectSegment', $segmentId);

    expect($component->instance()->rebuildEnabled())->toBeFalse();

    // The UI renders the control as disabled…
    $component->assertSee('Reconstruction désactivée par configuration', escape: false);

    // …and calling the action directly still creates nothing.
    $component->call('rebuild');

    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM crm_segment_generations')->c)->toBe($before)
        ->and($component->get('watchedGenerationId'))->toBeNull();
});

it('starts a durable generation once the real flag is enabled', function () {
    ['segment_id' => $segmentId] = p6b0SegmentWithMember();

    test()->actingAs(Admin::admin());
    config(['crm.segment_rebuild.enabled' => true]);

    $component = Livewire::test(CrmSegments::class)
        ->call('selectSegment', $segmentId)
        ->call('rebuild');

    $generationId = $component->get('watchedGenerationId');
    expect($generationId)->toBeInt();

    $generation = app(CrmSegmentService::class)->generation($generationId);

    // Durable straight away: with queue processing off the row simply waits in `ready`
    // and no work is lost.
    expect($generation['status'])->toBe('ready')
        ->and($generation['segment_id'])->toBe($segmentId);
});

it('treats a malformed rebuild flag as disabled', function () {
    ['segment_id' => $segmentId] = p6b0SegmentWithMember();

    test()->actingAs(Admin::admin());
    config(['crm.segment_rebuild.enabled' => 'yes']);

    $component = Livewire::test(CrmSegments::class)->call('selectSegment', $segmentId);

    // Fail closed: an invalid flag closes the door, it never opens it.
    expect($component->instance()->rebuildEnabled())->toBeFalse();

    $before = (int) Fx::owner()->selectOne('SELECT count(*) AS c FROM crm_segment_generations')->c;
    $component->call('rebuild');
    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM crm_segment_generations')->c)->toBe($before);
});

it('offers retry only on a failed generation and never automatically', function () {
    ['segment_id' => $segmentId] = p6b0SegmentWithMember();
    $generationId = Fx::failGeneration($segmentId);

    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmSegments::class)->call('selectSegment', $segmentId);
    $component->set('watchedGenerationId', $generationId);

    $generation = $component->instance()->watchedGeneration();
    expect($generation['status'])->toBe('failed')
        // A stable SQLSTATE class, never a driver sentence.
        ->and($generation['last_error_code'])->toMatch('/\A[0-9A-Z]{5}\z/');

    $component->assertSee('Relancer');

    // A failed generation never resumes on its own — only the explicit action moves it.
    expect(app(CrmSegmentService::class)->generation($generationId)['status'])->toBe('failed');

    $component->call('retryGeneration', $generationId);
    expect(app(CrmSegmentService::class)->generation($generationId)['status'])->toBe('ready');
});

it('refuses to retry a generation that is not failed', function () {
    ['segment_id' => $segmentId] = p6b0SegmentWithMember();
    $generationId = Fx::buildGeneration($segmentId);

    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmSegments::class)
        ->call('selectSegment', $segmentId)
        ->call('retryGeneration', $generationId);

    expect($component->get('notice'))->toContain('en échec')
        ->and(app(CrmSegmentService::class)->generation($generationId)['status'])->toBe('published');
});

it('never renders a raw exception on the generation panel', function () {
    // Scanned as CODE so an explanatory comment cannot trip the guard, and so the guard
    // cannot be satisfied by merely deleting the explanation.
    $page = Scanner::phpCode(app_path('Filament/Pages/CrmSegments.php'));
    $view = Scanner::bladeMarkup(resource_path('views/filament/pages/crm-segments.blade.php'));

    $forbidden = ['getMessage()', 'QueryException', 'SQLSTATE', 'getTraceAsString'];

    expect(Scanner::violations($page, $forbidden))->toBe([])
        ->and(Scanner::violations($view, $forbidden))->toBe([]);

    // The only error surface is the bounded five-character code the authority stores.
    expect($view)->toContain('last_error_code');
});
