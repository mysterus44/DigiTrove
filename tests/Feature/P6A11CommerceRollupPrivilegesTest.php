<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('ensures table and function are owned by digitrove_crm_executor', function () {
    $tableOwner = DB::selectOne("
        SELECT tableowner 
        FROM pg_tables 
        WHERE tablename = 'crm_contact_commerce_rollups'
    ")->tableowner;

    expect($tableOwner)->toBe('digitrove_crm_executor');

    $functionOwner = DB::selectOne("
        SELECT proowner::regrole::text as owner 
        FROM pg_proc 
        WHERE proname = 'refresh_crm_contact_commerce_rollup'
    ")->owner;

    expect($functionOwner)->toBe('digitrove_crm_executor');
});

it('denies all table access to PUBLIC and digitrove_runtime', function () {
    $publicSelect = DB::selectOne("SELECT has_table_privilege('public', 'crm_contact_commerce_rollups', 'SELECT') AS allowed")->allowed;
    $runtimeSelect = DB::selectOne("SELECT has_table_privilege('digitrove_runtime', 'crm_contact_commerce_rollups', 'SELECT') AS allowed")->allowed;
    $runtimeInsert = DB::selectOne("SELECT has_table_privilege('digitrove_runtime', 'crm_contact_commerce_rollups', 'INSERT') AS allowed")->allowed;
    $runtimeUpdate = DB::selectOne("SELECT has_table_privilege('digitrove_runtime', 'crm_contact_commerce_rollups', 'UPDATE') AS allowed")->allowed;
    $runtimeDelete = DB::selectOne("SELECT has_table_privilege('digitrove_runtime', 'crm_contact_commerce_rollups', 'DELETE') AS allowed")->allowed;

    expect($publicSelect)->toBeFalse()
        ->and($runtimeSelect)->toBeFalse()
        ->and($runtimeInsert)->toBeFalse()
        ->and($runtimeUpdate)->toBeFalse()
        ->and($runtimeDelete)->toBeFalse();
});

it('denies function execution to PUBLIC and digitrove_runtime', function () {
    $signature = 'refresh_crm_contact_commerce_rollup(BIGINT, VARCHAR)';

    $publicExecute = DB::selectOne("SELECT has_function_privilege('public', 'public.{$signature}', 'EXECUTE') AS allowed")->allowed;
    $runtimeExecute = DB::selectOne("SELECT has_function_privilege('digitrove_runtime', 'public.{$signature}', 'EXECUTE') AS allowed")->allowed;

    expect($publicExecute)->toBeFalse()
        ->and($runtimeExecute)->toBeFalse();
});
