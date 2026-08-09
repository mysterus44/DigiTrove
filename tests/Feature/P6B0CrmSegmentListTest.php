<?php

declare(strict_types=1);

use App\Filament\Pages\CrmSegments;
use App\Services\Crm\CrmOperationException;
use App\Services\Crm\CrmSegmentService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\CrmAdminFixtures as Admin;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config(['crm.foundation_enabled' => true]);
});

it('lists segments with their current version, generation and member count', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', gross: 50_000);
    $definition = Fx::definition([
        'field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 1_000,
    ]);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion($definition, 'Fidèles');
    $generationId = Fx::buildGeneration($segmentId);

    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmSegments::class);
    $segments = $component->instance()->segments();

    expect($segments)->toHaveCount(1)
        ->and($segments[0]['segment_id'])->toBe($segmentId)
        ->and($segments[0]['status'])->toBe('active')
        ->and($segments[0]['current_version_number'])->toBe(1)
        ->and($segments[0]['current_generation_id'])->toBe($generationId)
        ->and($segments[0]['current_members_count'])->toBe(1)
        ->and($segments[0]['generation_published_at'])->not->toBeNull();

    $component->assertSee('#'.$segmentId)->assertSee('Fidèles');
});

it('shows a never-built segment without inventing a version or a count', function () {
    $segmentId = (int) Fx::owner()->selectOne('SELECT * FROM create_crm_segment(?)', ['Vide'])->segment_id;

    test()->actingAs(Admin::admin());

    $segments = Livewire::test(CrmSegments::class)->instance()->segments();

    // NULL is not zero: an absent generation must not be rendered as "0 members".
    expect($segments[0]['current_version_number'])->toBeNull()
        ->and($segments[0]['current_generation_id'])->toBeNull()
        ->and($segments[0]['current_members_count'])->toBeNull();
});

it('pages segments by keyset and never by offset', function () {
    $ids = [];

    for ($i = 0; $i < 3; $i++) {
        $ids[] = (int) Fx::owner()->selectOne('SELECT * FROM create_crm_segment(?)', ['S'.$i])->segment_id;
    }

    test()->actingAs(Admin::admin());

    $service = app(CrmSegmentService::class);

    expect(collect($service->listSegments(null, 2))->pluck('segment_id')->all())->toBe([$ids[0], $ids[1]])
        ->and(collect($service->listSegments($ids[1], 2))->pluck('segment_id')->all())->toBe([$ids[2]]);

    $component = Livewire::test(CrmSegments::class);
    $component->call('nextPage');
    expect($component->get('afterSegmentId'))->toBe($ids[2]);
    $component->call('previousPage');
    expect($component->get('afterSegmentId'))->toBeNull();

    // What actually runs is the authority body, not a PHP comment about it.
    $body = (string) DB::connection('pgsql_migration')->selectOne(
        "SELECT pg_get_functiondef(oid) AS def FROM pg_proc WHERE proname = 'list_crm_segments'",
    )->def;

    expect($body)->not->toContain('OFFSET')->and($body)->toContain('LIMIT');
});

it('keeps the page size inside the authority bounds', function () {
    test()->actingAs(Admin::admin());

    $service = app(CrmSegmentService::class);

    expect(fn () => $service->listSegments(null, 0))->toThrow(CrmOperationException::class)
        ->and(fn () => $service->listSegments(null, 101))->toThrow(CrmOperationException::class);
});

it('reads segments only through the authority, never through a table', function () {
    $service = file_get_contents(app_path('Services/Crm/CrmSegmentService.php'));

    expect($service)->toContain('public.list_crm_segments(')
        ->toContain('public.get_crm_segment(');

    // No direct table access of any kind from the runtime layer.
    foreach (['FROM crm_segments', 'FROM public.crm_segments', 'DB::table', '::query()'] as $token) {
        expect($service)->not->toContain($token);
    }
});
