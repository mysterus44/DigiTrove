<?php

declare(strict_types=1);

use App\Filament\Pages\CrmContacts;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\CrmAdminFixtures as Admin;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config(['crm.foundation_enabled' => true]);
});

it('shows no membership before any generation is published', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', gross: 50_000);
    $definition = Fx::definition([
        'field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 1_000,
    ]);
    Fx::createSegmentWithVersion($definition);

    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmContacts::class)->call('select', $contactId);

    // A published VERSION is not a published GENERATION: membership requires the latter.
    expect($component->instance()->segmentMemberships())->toBe([]);
    $component->assertSee('Aucune appartenance courante');
});

it('shows only the current generation and never a superseded one', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', gross: 50_000);
    $definition = Fx::definition([
        'field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 1_000,
    ]);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion($definition);

    $first = Fx::buildGeneration($segmentId);

    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmContacts::class)->call('select', $contactId);
    $memberships = $component->instance()->segmentMemberships();

    expect($memberships)->toHaveCount(1)
        ->and($memberships[0]['segment_id'])->toBe($segmentId)
        ->and($memberships[0]['generation_id'])->toBe($first);

    // Rebuilding publishes a NEW generation. The contact is still a member exactly once,
    // and the row now points at the newer generation — never at both.
    $second = Fx::buildGeneration($segmentId);
    $after = $component->instance()->segmentMemberships();

    expect($after)->toHaveCount(1)
        ->and($after[0]['generation_id'])->toBe($second)
        ->and($second)->not->toBe($first);
});

it('excludes a contact the current generation does not contain', function () {
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

    $component = Livewire::test(CrmContacts::class);

    expect($component->call('select', $member)->instance()->segmentMemberships())->toHaveCount(1)
        ->and($component->call('select', $outsider)->instance()->segmentMemberships())->toBe([]);
});

it('presents membership without implying any send permission', function () {
    $view = file_get_contents(resource_path('views/filament/pages/crm-contacts.blade.php'));

    expect($view)->toContain('Segments actuels');

    // No campaign, no e-mail, no export button anywhere on the membership block.
    foreach (['Envoyer', 'Campagne', 'Exporter', 'mailto:'] as $token) {
        expect($view)->not->toContain($token);
    }
});
