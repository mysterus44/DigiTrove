<?php

use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

it('installs only the P6-A0 CRM identity and consent tables', function () {
    expect(Schema::hasTable('crm_contacts'))->toBeTrue()
        ->and(Schema::hasTable('crm_marketing_consent_events'))->toBeTrue()
        ->and(Schema::hasTable('customer_segments'))->toBeFalse()
        ->and(Schema::hasTable('crm_contact_currency_stats'))->toBeFalse();
});
