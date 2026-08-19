<?php

use App\Enums\OrderStatus;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFile;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;
use Tests\Support\PhaseMigrationHarness;

uses(RefreshDatabase::class);

/**
 * P4-B0 — PostgreSQL runtime privilege boundary (D-029.6).
 *
 * Every probe here runs under the REAL `digitrove_runtime` role, never the owner:
 * this is the suite that proves the boundary is not merely superuser-masked. It
 * closes the bypass proven before P4-B0, where `pg_trigger_depth() > 1` let any
 * nested trigger increment download_grants.downloads_count with no download log.
 */

/** A fresh connection authenticated as the restricted runtime role, outside the test transaction. */
function p4b0RuntimePdo(): PDO
{
    $c = config('database.connections.pgsql');

    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']),
        $c['username'],
        $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    // Probes must never block on a row/table lock still held by the enclosing
    // test transaction: a boundary refusal has to be an ACL answer, not a wait.
    $pdo->exec("SET lock_timeout = '2000ms'");

    return $pdo;
}

/** Runs a statement as the runtime role and returns the SQLSTATE it failed with. */
function p4b0RuntimeSqlState(string $sql): string
{
    try {
        p4b0RuntimePdo()->exec($sql);
    } catch (PDOException $e) {
        return (string) $e->getCode();
    }

    return 'NO_ERROR';
}

function p4b0Owner(): Connection
{
    return DB::connection('pgsql_migration');
}

it('keeps the P4-B0 ACL boundary active after the later P4 and P5-A0 migrations', function () {
    // On this branch the P4-B gate (000013) follows the ACL gate, so download_logs
    // legitimately exists here. The proof that NOTHING P4-B exists at the 000012
    // boundary itself lives in the isolated rollback test below, which stops
    // exactly there — that is where the gate frontier is asserted.
    expect(DB::table('migrations')->count())->toBe(50)
        ->and(DB::table('migrations')->where('migration', '2026_07_14_000012_harden_database_runtime_privileges')->exists())->toBeTrue()
        ->and(DB::table('migrations')->where('migration', '2026_07_14_000013_create_download_logs_table')->exists())->toBeTrue()
        ->and(DB::table('migrations')->where('migration', '2026_07_14_000016_create_analytics_rollups_tables')->exists())->toBeTrue()
        ->and(Schema::hasTable('download_grants'))->toBeTrue()
        ->and(Schema::hasTable('download_logs'))->toBeTrue()
        ->and(Schema::hasTable('events'))->toBeTrue();

    // The ACL gate stays additive: the only download-log functions are P4-B's G5
    // and G6, while P6 licensing remains absent.
    expect(DB::table('pg_proc')->where('proname', 'like', '%download_log%')->count())->toBe(2)
        ->and(Schema::hasTable('licenses'))->toBeFalse();
});

it('pins the three roles with non-forgeable attributes and a single SET-only membership', function () {
    $runtime = p4b0Owner()->selectOne("SELECT rolsuper, rolcanlogin, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls, rolinherit FROM pg_roles WHERE rolname = 'digitrove_runtime'");
    $executor = p4b0Owner()->selectOne("SELECT rolsuper, rolcanlogin, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls, rolinherit FROM pg_roles WHERE rolname = 'digitrove_download_executor'");

    expect($runtime->rolcanlogin)->toBeTrue()
        ->and($runtime->rolsuper)->toBeFalse()
        ->and($runtime->rolcreatedb)->toBeFalse()
        ->and($runtime->rolcreaterole)->toBeFalse()
        ->and($runtime->rolreplication)->toBeFalse()
        ->and($runtime->rolbypassrls)->toBeFalse()
        ->and($runtime->rolinherit)->toBeFalse();

    expect($executor->rolcanlogin)->toBeFalse()
        ->and($executor->rolsuper)->toBeFalse()
        ->and($executor->rolinherit)->toBeFalse();

    // The runtime belongs to nothing that could elevate it.
    $runtimeMemberships = p4b0Owner()->select(<<<'SQL'
        SELECT granted.rolname
        FROM pg_auth_members m
        JOIN pg_roles member ON member.oid = m.member
        JOIN pg_roles granted ON granted.oid = m.roleid
        WHERE member.rolname = 'digitrove_runtime'
    SQL);
    expect($runtimeMemberships)->toBeEmpty();

    // The migrator may only ASSUME the executor: SET yes, INHERIT no, ADMIN no.
    $path = p4b0Owner()->selectOne(<<<'SQL'
        SELECT m.set_option, m.inherit_option, m.admin_option
        FROM pg_auth_members m
        JOIN pg_roles member ON member.oid = m.member
        JOIN pg_roles granted ON granted.oid = m.roleid
        WHERE member.rolname = 'digitrove' AND granted.rolname = 'digitrove_download_executor'
    SQL);
    expect($path->set_option)->toBeTrue()
        ->and($path->inherit_option)->toBeFalse()
        ->and($path->admin_option)->toBeFalse();
});

it('runs the business suite under the restricted runtime identity, not the owner', function () {
    $identity = DB::selectOne('SELECT session_user, current_user');

    expect($identity->session_user)->toBe('digitrove_runtime')
        ->and($identity->current_user)->toBe('digitrove_runtime');

    // And it is genuinely unprivileged.
    expect(DB::selectOne('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->rolsuper)->toBeFalse();
});

it('denies the runtime every route to a writable object: TEMP, CREATE, schema and functions', function () {
    expect(DB::selectOne("SELECT has_database_privilege(current_user, current_database(), 'TEMP') AS v")->v)->toBeFalse()
        ->and(DB::selectOne("SELECT has_database_privilege(current_user, current_database(), 'CONNECT') AS v")->v)->toBeTrue()
        ->and(DB::selectOne("SELECT has_schema_privilege(current_user, 'public', 'CREATE') AS v")->v)->toBeFalse()
        ->and(DB::selectOne("SELECT has_schema_privilege(current_user, 'public', 'USAGE') AS v")->v)->toBeTrue();

    // The forged-trigger prerequisites are all refused at the ACL layer.
    expect(p4b0RuntimeSqlState('CREATE TEMP TABLE p4b0_temp (id int)'))->toBe('42501')
        ->and(p4b0RuntimeSqlState('CREATE TABLE public.p4b0_perm (id int)'))->toBe('42501')
        ->and(p4b0RuntimeSqlState("CREATE FUNCTION public.p4b0_fn() RETURNS int LANGUAGE sql AS 'SELECT 1'"))->toBe('42501')
        ->and(p4b0RuntimeSqlState('CREATE SCHEMA p4b0_schema'))->toBe('42501');
});

it('forbids the runtime from becoming the migrator or the download executor', function () {
    expect(p4b0RuntimeSqlState('SET ROLE digitrove_download_executor'))->toBe('42501')
        ->and(p4b0RuntimeSqlState('SET ROLE digitrove'))->toBe('42501');
});

it('withholds EXECUTE on the protected trigger functions from PUBLIC and from the runtime', function () {
    $protected = [
        'enforce_download_grants_immutability()',
        'validate_download_grant_delivery()',
        'prevent_download_grants_delete()',
        'enforce_product_file_content_immutability()',
    ];

    foreach ($protected as $signature) {
        expect(DB::selectOne("SELECT has_function_privilege(current_user, 'public.{$signature}', 'EXECUTE') AS v")->v)
            ->toBeFalse("runtime must not hold EXECUTE on {$signature}");
    }

    // Extension functions (citext …) are deliberately untouched: the suite that
    // exercises citext columns passes, so only trigger functions were locked.
    expect(DB::selectOne("SELECT 'A'::citext = 'a'::citext AS v")->v)->toBeTrue();
});

/**
 * The mirror of the list above, and the reason that list is about TRIGGER functions
 * specifically. `resolve_affiliate_attribution` was briefly written as a trigger on
 * `orders`; as such it belonged with the protected functions, because a trigger function is
 * never called directly and EXECUTE on it is pure attack surface.
 *
 * P6-D2 makes it an ORDINARY authority the `OrderPaid` listener invokes by id, so the
 * runtime MUST hold EXECUTE — while PUBLIC still must not. Asserting that positively is
 * stronger than dropping the old line: a future change that either revokes the runtime's
 * access or re-opens the function to PUBLIC fails here.
 */
it('grants the runtime EXECUTE on the two P6-D2 authorities while keeping PUBLIC out', function () {
    $authorities = [
        'record_affiliate_touch(uuid, bigint, character varying, character varying)',
        'resolve_affiliate_attribution(bigint)',
    ];

    foreach ($authorities as $signature) {
        expect(DB::selectOne("SELECT has_function_privilege(current_user, 'public.{$signature}', 'EXECUTE') AS v")->v)
            ->toBeTrue("runtime must hold EXECUTE on {$signature}")
            ->and(DB::selectOne("SELECT has_function_privilege('public', 'public.{$signature}', 'EXECUTE') AS v")->v)
            ->toBeFalse("PUBLIC must never hold EXECUTE on {$signature}");
    }

    // And the runtime still owns no direct write path into the affiliate block: the
    // authorities are the only door.
    expect(DB::selectOne("SELECT has_table_privilege(current_user, 'affiliate_touches', 'INSERT') AS v")->v)->toBeFalse()
        ->and(DB::selectOne("SELECT has_table_privilege(current_user, 'affiliate_attributions', 'INSERT') AS v")->v)->toBeFalse()
        ->and(DB::selectOne("SELECT has_table_privilege(current_user, 'affiliate_touches', 'SELECT') AS v")->v)->toBeFalse();
});

it('still fires the existing triggers under the runtime even though EXECUTE was revoked', function () {
    // G0 freezes product_files content. The runtime holds UPDATE on the table but
    // no EXECUTE on the trigger function — the trigger must fire regardless, so
    // the answer is the trigger's 23514, never a privilege error.
    $product = Product::factory()->create();
    $file = ProductFile::factory()->create(['product_id' => $product->getKey()]);

    $state = null;
    try {
        DB::table('product_files')->where('id', $file->getKey())->update(['checksum_sha256' => str_repeat('f', 64)]);
    } catch (QueryException $e) {
        $state = (string) $e->getCode();
    }

    expect($state)->toBe('23514');
});

it('bars the runtime from the consumption counter while keeping the legitimate columns writable', function () {
    expect(DB::selectOne("SELECT has_table_privilege(current_user, 'download_grants', 'UPDATE') AS v")->v)->toBeFalse()
        ->and(DB::selectOne("SELECT has_column_privilege(current_user, 'download_grants', 'downloads_count', 'UPDATE') AS v")->v)->toBeFalse()
        ->and(DB::selectOne("SELECT has_column_privilege(current_user, 'download_grants', 'token_hash', 'UPDATE') AS v")->v)->toBeFalse()
        ->and(DB::selectOne("SELECT has_column_privilege(current_user, 'download_grants', 'user_id', 'UPDATE') AS v")->v)->toBeFalse()
        ->and(DB::selectOne("SELECT has_column_privilege(current_user, 'download_grants', 'expires_at', 'UPDATE') AS v")->v)->toBeFalse()
        ->and(DB::selectOne("SELECT has_column_privilege(current_user, 'download_grants', 'revoked_at', 'UPDATE') AS v")->v)->toBeTrue()
        ->and(DB::selectOne("SELECT has_column_privilege(current_user, 'download_grants', 'revoked_reason_code', 'UPDATE') AS v")->v)->toBeTrue()
        ->and(DB::selectOne("SELECT has_table_privilege(current_user, 'download_grants', 'SELECT') AS v")->v)->toBeTrue()
        ->and(DB::selectOne("SELECT has_table_privilege(current_user, 'download_grants', 'INSERT') AS v")->v)->toBeTrue()
        ->and(DB::selectOne("SELECT has_table_privilege(current_user, 'download_grants', 'DELETE') AS v")->v)->toBeFalse();

    // Only the executor may touch the counter, and it cannot log in.
    expect(DB::selectOne("SELECT has_column_privilege('digitrove_download_executor', 'download_grants', 'downloads_count', 'UPDATE') AS v")->v)->toBeTrue();
});

it('refuses every historical bypass vector when replayed under the runtime, leaving the counter intact', function () {
    // Seed a deliverable purchase and a grant through the legitimate runtime path.
    $product = Product::factory()->create();
    $file = ProductFile::factory()->create(['product_id' => $product->getKey()]);
    $order = Order::factory()->create(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    $item = OrderItem::factory()->forOrder($order)->forProduct($product)->create();
    Payment::factory()->forOrder($order)->succeeded()->create();
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $grant = DownloadGrant::factory()->forOrderItem($item)->forProductFile($file)->create();
    DB::statement('SET CONSTRAINTS ALL DEFERRED');

    $id = (int) $grant->getKey();
    $before = (int) DB::table('download_grants')->where('id', $id)->value('downloads_count');

    $vectors = [
        // Direct counter write — the vector G2 alone used to arbitrate.
        'direct update' => "UPDATE download_grants SET downloads_count = downloads_count + 1 WHERE id = {$id}",
        // The proven forgery: a temporary trigger raising pg_trigger_depth().
        'temp table' => 'CREATE TEMP TABLE p4b0_forge (id int)',
        'temp function' => "CREATE FUNCTION pg_temp.p4b0_forge_fn() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN UPDATE download_grants SET downloads_count = downloads_count + 1 WHERE id = {$id}; RETURN NEW; END; \$\$",
        // A permanent trigger on an application table left untouched by this
        // transaction, so the answer is the ACL and not a lock wait.
        'permanent trigger' => 'CREATE TRIGGER p4b0_forge_trg AFTER UPDATE ON visitors FOR EACH ROW EXECUTE FUNCTION enforce_download_grants_immutability()',
        // Impersonation.
        'set role executor' => 'SET ROLE digitrove_download_executor',
    ];

    $states = [];
    foreach ($vectors as $label => $sql) {
        $states[$label] = p4b0RuntimeSqlState($sql);
    }

    foreach ($states as $label => $state) {
        expect($state)->toBe('42501', "vector '{$label}' must be refused with 42501, got {$state}");
    }

    // No increment, and no parasite object survived.
    expect((int) DB::table('download_grants')->where('id', $id)->value('downloads_count'))->toBe($before)
        ->and(DB::table('pg_class')->where('relname', 'like', 'p4b0_%')->count())->toBe(0);
});

it('refuses the counter write through Eloquent and the Query Builder alike', function () {
    $product = Product::factory()->create();
    $file = ProductFile::factory()->create(['product_id' => $product->getKey()]);
    $order = Order::factory()->create(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    $item = OrderItem::factory()->forOrder($order)->forProduct($product)->create();
    Payment::factory()->forOrder($order)->succeeded()->create();
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $grant = DownloadGrant::factory()->forOrderItem($item)->forProductFile($file)->create();
    DB::statement('SET CONSTRAINTS ALL DEFERRED');

    $builderState = null;
    try {
        DB::table('download_grants')->where('id', $grant->getKey())->update(['downloads_count' => 1]);
    } catch (QueryException $e) {
        $builderState = (string) $e->getCode();
    }
    expect($builderState)->toBe('42501');
});

it('proves the SECURITY DEFINER ownership path in an isolated database: runtime fires, executor writes', function () {
    $harness = new PhaseMigrationHarness('digitrove_p4b0_secdef_'.strtolower(Str::random(10)));
    $owner = config('database.connections.pgsql_migration');

    try {
        $harness->create();
        $harness->applyMigrationsThrough('2026_07_14_000012_harden_database_runtime_privileges.php');

        $ownerPdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $owner['host'], $owner['port'] ?? 5432, $harness->databaseName()),
            $owner['username'],
            $owner['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        // The exact dance P4-B will use for G5: a temporary CREATE grant, SET ROLE
        // to the executor, create the SECURITY DEFINER function, hand CREATE back.
        // The executor's global default privileges (set by migration 000012) mean
        // the function is born WITHOUT PUBLIC EXECUTE — no per-function revoke is
        // needed. Because PUBLIC no longer holds EXECUTE, a non-superuser migrator
        // must be granted EXECUTE explicitly to attach the trigger (production-safe
        // pattern: this is exactly what P4-B will do for G5 on download_logs).
        $ownerPdo->exec(<<<'SQL'
            BEGIN;
            CREATE TABLE public.p4b0_counter (id int primary key, n int not null default 0);
            INSERT INTO public.p4b0_counter (id, n) VALUES (1, 0);
            CREATE TABLE public.p4b0_probe (id serial primary key);
            GRANT CREATE ON SCHEMA public TO digitrove_download_executor;
            SET LOCAL ROLE digitrove_download_executor;
            CREATE FUNCTION public.p4b0_secdef() RETURNS trigger
                LANGUAGE plpgsql SECURITY DEFINER
                SET search_path = pg_catalog, public, pg_temp
                AS $fn$
                BEGIN
                    UPDATE public.p4b0_counter
                    SET n = n + 1
                    WHERE id = 1 AND session_user = 'digitrove_runtime' AND current_user = 'digitrove_download_executor';
                    RETURN NEW;
                END;
                $fn$;
            GRANT EXECUTE ON FUNCTION public.p4b0_secdef() TO digitrove;
            RESET ROLE;
            REVOKE CREATE ON SCHEMA public FROM digitrove_download_executor;
            GRANT SELECT, UPDATE ON public.p4b0_counter TO digitrove_download_executor;
            GRANT INSERT ON public.p4b0_probe TO digitrove_runtime;
            GRANT USAGE ON SEQUENCE public.p4b0_probe_id_seq TO digitrove_runtime;
            CREATE TRIGGER p4b0_secdef_trg BEFORE INSERT ON public.p4b0_probe
                FOR EACH ROW EXECUTE FUNCTION public.p4b0_secdef();
            COMMIT;
        SQL);

        // Ownership, security flag and pinned search_path.
        $fn = $ownerPdo->query("SELECT pg_get_userbyid(proowner) AS owner, prosecdef, array_to_string(proconfig, ',') AS cfg FROM pg_proc WHERE proname = 'p4b0_secdef'")->fetch(PDO::FETCH_ASSOC);
        expect($fn['owner'])->toBe('digitrove_download_executor')
            ->and($fn['prosecdef'])->toBe(true)
            ->and($fn['cfg'])->toBe('search_path=pg_catalog, public, pg_temp');

        // The executor keeps no permanent CREATE after the dance.
        expect($ownerPdo->query("SELECT has_schema_privilege('digitrove_download_executor','public','CREATE') AS v")->fetchColumn())->toBe(false);

        $runtimePdo = $harness->runtimePdo();

        // The runtime holds no EXECUTE, yet the trigger fires and the executor's
        // write lands: session_user stays the runtime, current_user becomes the
        // executor — the non-forgeable origin proof G2 will rely on.
        expect($runtimePdo->query("SELECT has_function_privilege(current_user,'public.p4b0_secdef()','EXECUTE') AS v")->fetchColumn())->toBe(false);
        $runtimePdo->exec('INSERT INTO public.p4b0_probe DEFAULT VALUES');
        expect((int) $ownerPdo->query('SELECT n FROM public.p4b0_counter WHERE id = 1')->fetchColumn())->toBe(1);

        // A direct call is refused, and the runtime cannot re-attach the function.
        $callState = null;
        try {
            $runtimePdo->exec('SELECT public.p4b0_secdef()');
        } catch (PDOException $e) {
            $callState = (string) $e->getCode();
        }
        expect($callState)->toBe('42501');

        $attachState = null;
        try {
            $runtimePdo->exec('CREATE TRIGGER p4b0_steal AFTER INSERT ON public.p4b0_counter FOR EACH ROW EXECUTE FUNCTION public.p4b0_secdef()');
        } catch (PDOException $e) {
            $attachState = (string) $e->getCode();
        }
        expect($attachState)->toBe('42501');
    } finally {
        $harness->drop();
    }

    expect(DB::table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});

it('makes every future function born unexecutable by PUBLIC via GLOBAL default privileges, for both the migrator and the executor', function () {
    $harness = new PhaseMigrationHarness('digitrove_p4b0_defacl_'.strtolower(Str::random(10)));
    $owner = config('database.connections.pgsql_migration');

    try {
        $harness->create();
        $harness->applyMigrationsThrough('2026_07_14_000012_harden_database_runtime_privileges.php');

        $ownerPdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $owner['host'], $owner['port'] ?? 5432, $harness->databaseName()),
            $owner['username'],
            $owner['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        // Tables created later inherit the runtime DML grants (schema-scoped GRANT
        // default) — this is what will cover download_logs when P4-B lands.
        $ownerPdo->exec('CREATE TABLE public.p4b0_future_table (id serial primary key)');
        expect($ownerPdo->query("SELECT has_table_privilege('digitrove_runtime','public.p4b0_future_table','INSERT') AS v")->fetchColumn())->toBe(true)
            ->and($ownerPdo->query("SELECT has_table_privilege('digitrove_runtime','public.p4b0_future_table','SELECT') AS v")->fetchColumn())->toBe(true);

        // (1) A function created by the MIGRATOR is born without PUBLIC EXECUTE —
        // the migration's GLOBAL default privileges (no IN SCHEMA) strip the
        // built-in grant, so no per-function revoke is needed.
        $ownerPdo->exec("CREATE FUNCTION public.p4b0_future_fn() RETURNS int LANGUAGE sql AS 'SELECT 1'");
        expect($ownerPdo->query("SELECT proacl FROM pg_proc WHERE proname = 'p4b0_future_fn'")->fetchColumn())->toContain('digitrove=X/digitrove')
            ->and($ownerPdo->query("SELECT has_function_privilege('digitrove_runtime','public.p4b0_future_fn()','EXECUTE') AS v")->fetchColumn())->toBe(false);

        // (2) A function created UNDER the executor (as G5 will be) is likewise
        // born without PUBLIC EXECUTE, because default privileges follow the role
        // that actually creates the object — the executor has its own default.
        $ownerPdo->exec('GRANT CREATE ON SCHEMA public TO digitrove_download_executor');
        $ownerPdo->exec('SET ROLE digitrove_download_executor');
        $ownerPdo->exec("CREATE FUNCTION public.p4b0_exec_fn() RETURNS int LANGUAGE sql AS 'SELECT 1'");
        $ownerPdo->exec('RESET ROLE');
        $ownerPdo->exec('REVOKE CREATE ON SCHEMA public FROM digitrove_download_executor');
        expect($ownerPdo->query("SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'p4b0_exec_fn'")->fetchColumn())->toBe('digitrove_download_executor')
            ->and($ownerPdo->query("SELECT has_function_privilege('digitrove_runtime','public.p4b0_exec_fn()','EXECUTE') AS v")->fetchColumn())->toBe(false);

        // Regression note (the mistake this migration avoids): the same REVOKE
        // written `IN SCHEMA public` records NO default-ACL entry and leaves the
        // built-in PUBLIC EXECUTE in place. Both global default entries below are
        // what actually close it — pinned here so a scope regression is caught.
        $entries = $ownerPdo->query("SELECT string_agg(pg_get_userbyid(defaclrole) || ':' || defaclnamespace, ',' ORDER BY 1) FROM pg_default_acl WHERE defaclobjtype = 'f'")->fetchColumn();
        expect($entries)->toBe('digitrove:0,digitrove_download_executor:0');
    } finally {
        $harness->drop();
    }
});

it('rolls back the ACL gate without ever re-opening the boundary', function () {
    $harness = new PhaseMigrationHarness('digitrove_p4b0_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $harness->applyMigrationsThrough('2026_07_14_000012_harden_database_runtime_privileges.php');

        $rolledBack = $harness->rollbackExactMigrations(['2026_07_14_000012_harden_database_runtime_privileges.php']);
        expect($rolledBack)->toBe(['2026_07_14_000012_harden_database_runtime_privileges']);

        $pdo = $harness->runtimePdo();

        // Fail-closed: the PUBLIC hardening survives the rollback.
        $temp = null;
        try {
            $pdo->exec('CREATE TEMP TABLE p4b0_after_rollback (id int)');
        } catch (PDOException $e) {
            $temp = (string) $e->getCode();
        }
        expect($temp)->toBe('42501');

        $create = null;
        try {
            $pdo->exec('CREATE TABLE public.p4b0_after_rollback (id int)');
        } catch (PDOException $e) {
            $create = (string) $e->getCode();
        }
        expect($create)->toBe('42501');

        // Fail-closed on functions too: after rollback the global default-privileges
        // REVOKE is kept, so a function created later is STILL born unexecutable by
        // PUBLIC. The boundary never regresses to the insecure default.
        $owner = config('database.connections.pgsql_migration');
        $ownerPdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $owner['host'], $owner['port'] ?? 5432, $harness->databaseName()),
            $owner['username'],
            $owner['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $ownerPdo->exec("CREATE FUNCTION public.p4b0_after_rollback_fn() RETURNS int LANGUAGE sql AS 'SELECT 1'");
        expect($ownerPdo->query("SELECT has_function_privilege('digitrove_runtime','public.p4b0_after_rollback_fn()','EXECUTE') AS v")->fetchColumn())->toBe(false);

        // Roles survive, prior gates survive, no data was dropped.
        expect($harness->countFunctions([
            'enforce_download_grants_immutability',
            'validate_download_grant_delivery',
            'prevent_download_grants_delete',
            'validate_download_grant_order_consistency',
        ]))->toBe(4)
            ->and($harness->hasTable('download_grants'))->toBeTrue()
            ->and($harness->hasTable('download_logs'))->toBeFalse()
            ->and($harness->ranMigrations())->not->toContain('2026_07_14_000012_harden_database_runtime_privileges');
    } finally {
        $harness->drop();
    }

    // The cluster roles are never dropped by a migration rollback.
    expect(p4b0Owner()->table('pg_roles')->whereIn('rolname', ['digitrove_runtime', 'digitrove_download_executor'])->count())->toBe(2)
        ->and(DB::table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});
