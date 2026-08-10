<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6-D0 — Affiliate schema foundation (D-014 → D-057).
 *
 * SCHEMA ONLY. No attribution logic, no commission engine, no application flow, no
 * authority function: those contracts belong to P6-D1/D2/D3 and freezing them here would
 * decide too early.
 *
 * THREE INVARIANTS SHAPE EVERYTHING BELOW.
 *
 *  1. An affiliate is a `user`, never a role (D-014). A role cannot carry an application
 *     status, a history, a balance or an audit trail.
 *
 *  2. Money is BIGINT minor units with an explicit currency, and rates are INTEGER basis
 *     points. There is no FLOAT anywhere, and no amount lives in JSON.
 *
 *  3. A commission is never rewritten. Its identity is separate from its ledger entries,
 *     and a correction ADDS an entry — refunds included. The ledger is the only place a
 *     signed amount is allowed.
 *
 * No policy row is inserted here on purpose: seeding an "active" programme would make the
 * platform believe affiliation is running while no code uses it. Bootstrapping is
 * explicitly deferred to P6-D1.
 */
return new class extends Migration
{
    /**
     * Dropped in reverse dependency order by down(). Kept as one list so a table cannot
     * be created without also being dropped.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        return [
            'affiliate_program_policies',
            'affiliates',
            'affiliate_codes',
            'affiliate_touches',
            'affiliate_attributions',
            'affiliate_commissions',
            'affiliate_commission_entries',
            'affiliate_payouts',
            'affiliate_payout_items',
        ];
    }

    public function up(): void
    {
        $this->createPolicies();
        $this->createAffiliates();
        $this->createCodes();
        $this->createTouches();
        $this->createAttributions();
        $this->createCommissions();
        $this->createCommissionEntries();
        $this->createPayouts();
        $this->createPayoutItems();
        $this->lockDownPrivileges();
    }

    public function down(): void
    {
        // The payout↔ledger link is the one BACK-reference: it is added after both tables
        // exist, so a plain reverse-order drop would hit `affiliate_payouts` while
        // `affiliate_commission_entries` still points at it.
        DB::statement('ALTER TABLE IF EXISTS public.affiliate_commission_entries DROP CONSTRAINT IF EXISTS affiliate_commission_entries_payout_foreign');

        foreach (array_reverse($this->tables()) as $table) {
            Schema::dropIfExists($table);
        }

        // Dropping a table takes its triggers with it, but NOT the functions behind them:
        // without this the rollback would leave residues behind.
        DB::statement('DROP FUNCTION IF EXISTS public.enforce_affiliate_ledger_append_only()');
        DB::statement('DROP FUNCTION IF EXISTS public.enforce_affiliate_touch_subject()');
        DB::statement('DROP FUNCTION IF EXISTS public.enforce_affiliate_policy_immutability()');

        // The one object this gate added OUTSIDE its own block. Dropped last, once no
        // affiliate foreign key points at it, so the `000028` boundary is restored exactly.
        DB::statement('DROP INDEX IF EXISTS public.order_items_id_order_id_unique');
    }

    // ── 1. Versioned programme policies ──────────────────────────────────────────

    /**
     * Every tunable lives HERE, versioned, never on `affiliates` and never in code.
     * Changing a rate creates a NEW version; an already-effective policy is never
     * rewritten, so a past commission can always be explained by the policy it snapshot.
     */
    private function createPolicies(): void
    {
        Schema::create('affiliate_program_policies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->integer('version');
            $table->string('status', 16)->default('draft');
            $table->string('attribution_model', 32);
            $table->integer('attribution_window_days');
            $table->integer('default_commission_bps');
            $table->integer('payable_delay_days');
            $table->bigInteger('payout_threshold_minor');
            $table->string('payout_currency', 3);
            $table->boolean('manual_payout_only')->default(true);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->timestampsTz();

            $table->unique('public_id', 'affiliate_program_policies_public_id_unique');
            $table->unique('version', 'affiliate_program_policies_version_unique');
            $table->index(['status', 'effective_from'], 'affiliate_program_policies_status_from_index');
        });

        DB::statement("ALTER TABLE public.affiliate_program_policies ADD CONSTRAINT affiliate_program_policies_status_check CHECK (status IN ('draft', 'active', 'superseded'))");
        DB::statement('ALTER TABLE public.affiliate_program_policies ADD CONSTRAINT affiliate_program_policies_version_check CHECK (version >= 1)');
        // Only the arbitrated model exists today; a new one is a reviewed decision.
        DB::statement("ALTER TABLE public.affiliate_program_policies ADD CONSTRAINT affiliate_program_policies_model_check CHECK (attribution_model IN ('code_then_last_click'))");
        DB::statement('ALTER TABLE public.affiliate_program_policies ADD CONSTRAINT affiliate_program_policies_window_check CHECK (attribution_window_days BETWEEN 1 AND 365)');
        // Basis points: 1500 = 15 %. A 100 % commission is refused as absurd.
        DB::statement('ALTER TABLE public.affiliate_program_policies ADD CONSTRAINT affiliate_program_policies_bps_check CHECK (default_commission_bps BETWEEN 0 AND 5000)');
        DB::statement('ALTER TABLE public.affiliate_program_policies ADD CONSTRAINT affiliate_program_policies_delay_check CHECK (payable_delay_days BETWEEN 0 AND 365)');
        DB::statement('ALTER TABLE public.affiliate_program_policies ADD CONSTRAINT affiliate_program_policies_threshold_check CHECK (payout_threshold_minor >= 0)');
        DB::statement('ALTER TABLE public.affiliate_program_policies ADD CONSTRAINT affiliate_program_policies_currency_check CHECK (char_length(payout_currency) = 3 AND payout_currency = upper(payout_currency))');
        DB::statement('ALTER TABLE public.affiliate_program_policies ADD CONSTRAINT affiliate_program_policies_period_check CHECK (effective_until IS NULL OR effective_until > effective_from)');

        // At most ONE active policy at a time: two overlapping active versions would make
        // "which rate applied" unanswerable.
        //
        // Historical overlap BETWEEN SUPERSEDED versions is deliberately NOT constrained
        // here: an exclusion constraint would require the `btree_gist` extension, which no
        // earlier gate installs, and dropping a shared extension at rollback would damage
        // infrastructure this gate does not own. "Which rate applies now" is already
        // answered by this index; ordering the historical timeline belongs to the P6-D1
        // authority.
        DB::statement("CREATE UNIQUE INDEX affiliate_program_policies_single_active ON public.affiliate_program_policies ((status)) WHERE status = 'active'");

        // Once a policy leaves `draft` it is EFFECTIVE, and an effective policy is never
        // rewritten: changing 30 days, 1500 bps, 14 days or 10 000 XOF requires a NEW
        // version. Without this, a past commission's snapshot could no longer be explained
        // by the policy it points at — the audit trail would silently become fiction.
        //
        // Status transitions and closing `effective_until` stay allowed: they move the
        // policy through its lifecycle without altering what it ever meant.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_affiliate_policy_immutability()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF OLD.status = 'draft' THEN
                    RETURN NEW;
                END IF;

                IF NEW.version                  IS DISTINCT FROM OLD.version
                OR NEW.attribution_model        IS DISTINCT FROM OLD.attribution_model
                OR NEW.attribution_window_days  IS DISTINCT FROM OLD.attribution_window_days
                OR NEW.default_commission_bps   IS DISTINCT FROM OLD.default_commission_bps
                OR NEW.payable_delay_days       IS DISTINCT FROM OLD.payable_delay_days
                OR NEW.payout_threshold_minor   IS DISTINCT FROM OLD.payout_threshold_minor
                OR NEW.payout_currency          IS DISTINCT FROM OLD.payout_currency
                OR NEW.effective_from           IS DISTINCT FROM OLD.effective_from
                OR NEW.public_id                IS DISTINCT FROM OLD.public_id
                THEN
                    RAISE EXCEPTION USING ERRCODE = '23514',
                        MESSAGE = 'an effective affiliate policy is immutable: publish a new version instead';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER affiliate_program_policies_immutability_trigger
            BEFORE UPDATE ON public.affiliate_program_policies
            FOR EACH ROW EXECUTE FUNCTION public.enforce_affiliate_policy_immutability();
            SQL);
    }

    // ── 2. Affiliates ────────────────────────────────────────────────────────────

    private function createAffiliates(): void
    {
        Schema::create('affiliates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->timestampTz('applied_at');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique('public_id', 'affiliates_public_id_unique');
            // ONE affiliate per user. D-014: affiliation is attached to a user, and a
            // second affiliate row for the same person would split their balance.
            $table->unique('user_id', 'affiliates_user_id_unique');
            $table->index(['status', 'id'], 'affiliates_status_id_index');
        });

        DB::statement("ALTER TABLE public.affiliates ADD CONSTRAINT affiliates_status_check CHECK (status IN ('pending', 'active', 'suspended', 'rejected', 'closed'))");
        DB::statement(<<<'SQL'
            ALTER TABLE public.affiliates
            ADD CONSTRAINT affiliates_status_timestamps_check
            CHECK (
                (status <> 'active' OR approved_at IS NOT NULL)
                AND (status <> 'suspended' OR suspended_at IS NOT NULL)
                AND (status <> 'rejected' OR rejected_at IS NOT NULL)
                AND (status <> 'closed' OR closed_at IS NOT NULL)
            )
            SQL);
    }

    // ── 3. Codes — durable, public, non-secret ───────────────────────────────────

    /**
     * A code is a PUBLIC identifier, not an authentication secret: it is meant to be
     * shared. It is normalised and unique, carries no PII, and is deactivated rather
     * than deleted so past touches keep pointing at something real.
     */
    private function createCodes(): void
    {
        Schema::create('affiliate_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('affiliates')->restrictOnDelete();
            $table->string('code', 32);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestampsTz();

            $table->unique('code', 'affiliate_codes_code_unique');
            $table->index(['affiliate_id', 'id'], 'affiliate_codes_affiliate_id_index');
        });

        // Normalised alphabet: uppercase alphanumerics only. No e-mail, no name, no
        // punctuation that could smuggle an identifier.
        DB::statement("ALTER TABLE public.affiliate_codes ADD CONSTRAINT affiliate_codes_format_check CHECK (code ~ '^[A-Z0-9]{4,32}$')");
        DB::statement('ALTER TABLE public.affiliate_codes ADD CONSTRAINT affiliate_codes_deactivated_check CHECK (is_active OR deactivated_at IS NOT NULL)');
    }

    // ── 4. Touches — volumetric, financially authoritative ───────────────────────

    /**
     * DELIBERATELY SEPARATE from `affiliate_codes`: a code is durable and rare, a touch
     * is volumetric and perishable, and rotating a code must never erase the history of
     * the touches that used it.
     *
     * This table exists because `visitors.first_touch_*` and `events` are MARKETING
     * signals — D-037 froze that events are non-authoritative — and a non-authoritative
     * signal must never justify paying money.
     *
     * `visitor_id` is therefore an IDENTITY anchor, not evidence: a pre-login click has to
     * be attached to someone, and `visitors` (migration `000004`, P1 era) is that someone.
     * What is refused is reading its marketing columns as proof of who earned a commission
     * — the authoritative fact is THIS row.
     *
     * `ON DELETE SET NULL` follows the repository's OWN precedent for financial rows:
     * `orders.visitor_id` and `carts.visitor_id` are both nullable-on-delete. A financial
     * record must never depend on a visitor's continued existence, and erasing a visitor
     * must never cascade into an attribution, a commission or a ledger entry.
     *
     * Anchoring is therefore enforced **at INSERT only**, by a trigger rather than a CHECK.
     * A CHECK would be re-evaluated by the SET NULL update and would block visitor
     * erasure permanently — an unjustified veto on any purge or anonymisation policy.
     * A touch that later loses its anchor is not ambiguous: it can match NOTHING, which is
     * the fail-closed outcome, and any attribution built from it keeps its own snapshots.
     */
    private function createTouches(): void
    {
        Schema::create('affiliate_touches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('affiliates')->restrictOnDelete();
            $table->foreignId('affiliate_code_id')->constrained('affiliate_codes')->restrictOnDelete();
            $table->foreignUuid('visitor_id')->nullable()->constrained('visitors')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 16);
            $table->timestampTz('occurred_at');
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->index(['visitor_id', 'occurred_at'], 'affiliate_touches_visitor_occurred_index');
            $table->index(['user_id', 'occurred_at'], 'affiliate_touches_user_occurred_index');
            $table->index('affiliate_id', 'affiliate_touches_affiliate_id_index');
        });

        // `code` = the visitor typed it; `link` = they followed a referral URL. The
        // arbitrated model prefers an explicit code over a click.
        DB::statement("ALTER TABLE public.affiliate_touches ADD CONSTRAINT affiliate_touches_source_check CHECK (source IN ('code', 'link'))");
        // The attribution window is materialised per touch, from the policy in force when
        // it happened — changing the window later must not move an existing touch.
        DB::statement('ALTER TABLE public.affiliate_touches ADD CONSTRAINT affiliate_touches_window_check CHECK (expires_at > occurred_at)');
        // A touch must be anchored to SOMEONE when it is CREATED, or it could never be
        // matched to an order. Deliberately INSERT-only: see the class comment above —
        // a CHECK here would veto every visitor erasure for ever.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_affiliate_touch_subject()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                IF NEW.visitor_id IS NULL AND NEW.user_id IS NULL THEN
                    RAISE EXCEPTION USING ERRCODE = '23514',
                        MESSAGE = 'an affiliate touch must be anchored to a visitor or a user';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER affiliate_touches_subject_trigger
            BEFORE INSERT ON public.affiliate_touches
            FOR EACH ROW EXECUTE FUNCTION public.enforce_affiliate_touch_subject();
            SQL);
    }

    // ── 5. Attribution — one authoritative row per order ─────────────────────────

    private function createAttributions(): void
    {
        Schema::create('affiliate_attributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('affiliate_id')->constrained('affiliates')->restrictOnDelete();
            $table->foreignId('affiliate_code_id')->constrained('affiliate_codes')->restrictOnDelete();
            $table->foreignId('affiliate_touch_id')->nullable()->constrained('affiliate_touches')->restrictOnDelete();
            $table->foreignId('policy_id')->constrained('affiliate_program_policies')->restrictOnDelete();
            $table->string('matched_by', 16);
            $table->timestampTz('attributed_at');
            $table->timestampsTz();

            // EXACTLY ONE financial attribution per order. Two affiliates cannot both be
            // paid for the same sale.
            $table->unique('order_id', 'affiliate_attributions_order_id_unique');
            $table->index('affiliate_id', 'affiliate_attributions_affiliate_id_index');

            // Redundant against the primary key, and that is the point: these are the
            // TARGETS a commission's composite foreign keys point at, so "the attribution
            // belongs to the same order and the same affiliate" becomes structural rather
            // than a rule someone has to remember.
            $table->unique(['id', 'order_id'], 'affiliate_attributions_id_order_unique');
            $table->unique(['id', 'affiliate_id'], 'affiliate_attributions_id_affiliate_unique');
        });

        DB::statement("ALTER TABLE public.affiliate_attributions ADD CONSTRAINT affiliate_attributions_matched_by_check CHECK (matched_by IN ('code', 'last_click'))");
        // A click-matched attribution must name the touch it came from; a typed code
        // need not, because the code itself is the evidence.
        DB::statement("ALTER TABLE public.affiliate_attributions ADD CONSTRAINT affiliate_attributions_touch_required_check CHECK (matched_by <> 'last_click' OR affiliate_touch_id IS NOT NULL)");
    }

    // ── 6. Commissions — one per order line ──────────────────────────────────────

    /**
     * Granularity is `order_item` so a PARTIAL refund and a future per-product rule stay
     * unambiguous. `order_items` already carries a delete-prevention trigger, so the
     * target cannot vanish under a commission.
     *
     * Every rate and delay is SNAPSHOT here, mirroring `orders.coupon_*_snapshot`: the
     * policy may change tomorrow, and this row must still explain itself.
     */
    private function createCommissions(): void
    {
        Schema::create('affiliate_commissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('affiliate_id')->constrained('affiliates')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->foreignId('attribution_id')->constrained('affiliate_attributions')->restrictOnDelete();
            $table->foreignId('policy_id')->constrained('affiliate_program_policies')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->integer('rate_bps_snapshot');
            // Wide enough for the allowlisted value itself: `line_total_after_discount`
            // is 25 characters, so a tighter column would make the CHECK unsatisfiable.
            $table->string('base_kind_snapshot', 32);
            $table->bigInteger('base_amount_minor_snapshot');
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->integer('payable_delay_days_snapshot');
            $table->timestampTz('payable_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->unique('public_id', 'affiliate_commissions_public_id_unique');
            // One commission per order line: a line cannot be commissioned twice.
            $table->unique('order_item_id', 'affiliate_commissions_order_item_unique');
            $table->index(['affiliate_id', 'status'], 'affiliate_commissions_affiliate_status_index');
            $table->index(['status', 'payable_at'], 'affiliate_commissions_status_payable_index');

            // FK targets for the ledger and the payout items: an entry cannot credit a
            // different affiliate than the commission it corrects, and cannot silently
            // change currency along the way.
            $table->unique(['id', 'affiliate_id'], 'affiliate_commissions_id_affiliate_unique');
            $table->unique(['id', 'currency'], 'affiliate_commissions_id_currency_unique');
        });

        DB::statement("ALTER TABLE public.affiliate_commissions ADD CONSTRAINT affiliate_commissions_status_check CHECK (status IN ('pending', 'payable', 'allocated', 'paid', 'cancelled'))");
        DB::statement('ALTER TABLE public.affiliate_commissions ADD CONSTRAINT affiliate_commissions_rate_check CHECK (rate_bps_snapshot BETWEEN 0 AND 5000)');
        // The arbitrated base is the after-discount line total. No tax policy exists in
        // this repository, so no other base may be claimed.
        DB::statement("ALTER TABLE public.affiliate_commissions ADD CONSTRAINT affiliate_commissions_base_kind_check CHECK (base_kind_snapshot IN ('line_total_after_discount'))");
        DB::statement('ALTER TABLE public.affiliate_commissions ADD CONSTRAINT affiliate_commissions_base_amount_check CHECK (base_amount_minor_snapshot >= 0)');
        // A commission itself is never negative: corrections live in the ledger.
        DB::statement('ALTER TABLE public.affiliate_commissions ADD CONSTRAINT affiliate_commissions_amount_check CHECK (amount_minor >= 0)');
        // A commission can never exceed the line it is computed from. With the rate capped
        // at 5000 bps this is strictly weaker than the real bound, so it can only reject
        // genuinely absurd rows — and it does NOT decide the rounding policy, which belongs
        // to P6-D3. A calculation bug in a later gate is stopped by the database, not paid.
        DB::statement('ALTER TABLE public.affiliate_commissions ADD CONSTRAINT affiliate_commissions_amount_within_base_check CHECK (amount_minor <= base_amount_minor_snapshot)');
        DB::statement('ALTER TABLE public.affiliate_commissions ADD CONSTRAINT affiliate_commissions_delay_check CHECK (payable_delay_days_snapshot BETWEEN 0 AND 365)');
        DB::statement('ALTER TABLE public.affiliate_commissions ADD CONSTRAINT affiliate_commissions_currency_check CHECK (char_length(currency) = 3 AND currency = upper(currency))');
        DB::statement("ALTER TABLE public.affiliate_commissions ADD CONSTRAINT affiliate_commissions_cancelled_check CHECK (status <> 'cancelled' OR cancelled_at IS NOT NULL)");

        // ── Cross-coherence, enforced structurally ───────────────────────────────
        //
        // Separate foreign keys only prove that each id EXISTS. They do not prove the ids
        // belong together: a commission could point at order 7 and at a line of order 9,
        // and every single-column FK would be satisfied. These composite keys close that.
        //
        // `order_items` gains one redundant unique key to serve as the target. It is added
        // by THIS migration and dropped by its down(), so no historical migration file is
        // touched — the same pattern P6-C used to grant and revoke Commerce privileges.
        DB::statement('CREATE UNIQUE INDEX order_items_id_order_id_unique ON public.order_items (id, order_id)');

        // The commissioned line really belongs to the commissioned order.
        DB::statement(<<<'SQL'
            ALTER TABLE public.affiliate_commissions
            ADD CONSTRAINT affiliate_commissions_order_item_scope_foreign
            FOREIGN KEY (order_item_id, order_id)
            REFERENCES public.order_items (id, order_id) ON DELETE RESTRICT
            SQL);

        // The attribution really covers that same order...
        DB::statement(<<<'SQL'
            ALTER TABLE public.affiliate_commissions
            ADD CONSTRAINT affiliate_commissions_attribution_order_foreign
            FOREIGN KEY (attribution_id, order_id)
            REFERENCES public.affiliate_attributions (id, order_id) ON DELETE RESTRICT
            SQL);

        // ...and really names that same affiliate. Paying affiliate B on affiliate A's
        // attribution is now impossible, not merely discouraged.
        DB::statement(<<<'SQL'
            ALTER TABLE public.affiliate_commissions
            ADD CONSTRAINT affiliate_commissions_attribution_affiliate_foreign
            FOREIGN KEY (attribution_id, affiliate_id)
            REFERENCES public.affiliate_attributions (id, affiliate_id) ON DELETE RESTRICT
            SQL);
    }

    // ── 7. Ledger — append-only, the only signed amounts ─────────────────────────

    /**
     * THE financial proof. A correction ADDS an entry; it never rewrites one. That is why
     * this is the single table where `amount_minor` may be negative — a refund reversal
     * or an admin adjustment is a movement, not an erasure.
     */
    private function createCommissionEntries(): void
    {
        Schema::create('affiliate_commission_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('affiliates')->restrictOnDelete();
            $table->foreignId('commission_id')->nullable()->constrained('affiliate_commissions')->restrictOnDelete();
            $table->string('entry_type', 24);
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->restrictOnDelete();
            $table->foreignId('payout_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_code', 32)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['affiliate_id', 'currency', 'id'], 'affiliate_commission_entries_balance_index');
            $table->index('commission_id', 'affiliate_commission_entries_commission_index');
        });

        DB::statement("ALTER TABLE public.affiliate_commission_entries ADD CONSTRAINT affiliate_commission_entries_type_check CHECK (entry_type IN ('accrual', 'release', 'refund_reversal', 'admin_adjustment', 'payout_allocation', 'payout_reversal'))");
        DB::statement('ALTER TABLE public.affiliate_commission_entries ADD CONSTRAINT affiliate_commission_entries_currency_check CHECK (char_length(currency) = 3 AND currency = upper(currency))');
        // A zero movement is not an event: it would pollute the audit trail with noise.
        DB::statement('ALTER TABLE public.affiliate_commission_entries ADD CONSTRAINT affiliate_commission_entries_amount_check CHECK (amount_minor <> 0)');
        // Directional sanity: an accrual credits, a reversal debits.
        DB::statement(<<<'SQL'
            ALTER TABLE public.affiliate_commission_entries
            ADD CONSTRAINT affiliate_commission_entries_direction_check
            CHECK (
                (entry_type IN ('accrual', 'release') AND amount_minor > 0)
                OR (entry_type IN ('refund_reversal', 'payout_allocation') AND amount_minor < 0)
                OR entry_type IN ('admin_adjustment', 'payout_reversal')
            )
            SQL);
        // A refund reversal must name the refund it compensates, and only it may.
        DB::statement("ALTER TABLE public.affiliate_commission_entries ADD CONSTRAINT affiliate_commission_entries_refund_scope_check CHECK ((entry_type = 'refund_reversal') = (refund_id IS NOT NULL))");
        // An administrative adjustment must always be justified.
        DB::statement("ALTER TABLE public.affiliate_commission_entries ADD CONSTRAINT affiliate_commission_entries_reason_check CHECK (entry_type <> 'admin_adjustment' OR reason_code IS NOT NULL)");

        // An entry attached to a commission cannot credit a DIFFERENT affiliate, nor
        // change currency on the way. A NULL `commission_id` (a standalone administrative
        // adjustment) leaves both unenforced by MATCH SIMPLE, which is intended.
        DB::statement(<<<'SQL'
            ALTER TABLE public.affiliate_commission_entries
            ADD CONSTRAINT affiliate_commission_entries_commission_affiliate_foreign
            FOREIGN KEY (commission_id, affiliate_id)
            REFERENCES public.affiliate_commissions (id, affiliate_id) ON DELETE RESTRICT
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE public.affiliate_commission_entries
            ADD CONSTRAINT affiliate_commission_entries_commission_currency_foreign
            FOREIGN KEY (commission_id, currency)
            REFERENCES public.affiliate_commissions (id, currency) ON DELETE RESTRICT
            SQL);

        // ── Idempotency identities ───────────────────────────────────────────────
        //
        // Rather than an opaque idempotency key, the domain provides the natural ones. A
        // retried P6-D3 worker is stopped by the storage layer, not by hoping the caller
        // remembered. These are the append-only equivalent of "do it exactly once".

        // A commission accrues ONCE. A replayed accrual is the classic double payment.
        DB::statement("CREATE UNIQUE INDEX affiliate_commission_entries_single_accrual ON public.affiliate_commission_entries (commission_id) WHERE entry_type = 'accrual'");
        // A given refund reverses a given commission ONCE, however often it is processed.
        DB::statement("CREATE UNIQUE INDEX affiliate_commission_entries_refund_once ON public.affiliate_commission_entries (commission_id, refund_id) WHERE entry_type = 'refund_reversal'");

        // Append-only at the storage layer: no UPDATE, no DELETE, ever.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_affiliate_ledger_append_only()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
                RAISE EXCEPTION USING ERRCODE = '23514',
                    MESSAGE = 'affiliate commission entries are append-only';
            END;
            $$;

            CREATE TRIGGER affiliate_commission_entries_append_only_trigger
            BEFORE UPDATE OR DELETE ON public.affiliate_commission_entries
            FOR EACH ROW EXECUTE FUNCTION public.enforce_affiliate_ledger_append_only();
            SQL);
    }

    // ── 8. Payouts — manual, single affiliate, single currency ───────────────────

    private function createPayouts(): void
    {
        Schema::create('affiliate_payouts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('affiliate_id')->constrained('affiliates')->restrictOnDelete();
            $table->foreignId('policy_id')->constrained('affiliate_program_policies')->restrictOnDelete();
            $table->string('status', 16)->default('requested');
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->bigInteger('threshold_minor_snapshot');
            // A NON-SENSITIVE administrative reference only. No bank account, no Wave or
            // Mobile Money number: that would need its own reviewed gate.
            $table->string('administrative_reference', 64)->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->unique('public_id', 'affiliate_payouts_public_id_unique');
            $table->index(['affiliate_id', 'status'], 'affiliate_payouts_affiliate_status_index');

            // FK targets that make "single affiliate, single currency" structural rather
            // than a sentence in a document.
            $table->unique(['id', 'affiliate_id'], 'affiliate_payouts_id_affiliate_unique');
            $table->unique(['id', 'currency'], 'affiliate_payouts_id_currency_unique');
        });

        DB::statement("ALTER TABLE public.affiliate_payouts ADD CONSTRAINT affiliate_payouts_status_check CHECK (status IN ('requested', 'approved', 'paid', 'rejected', 'cancelled'))");
        DB::statement('ALTER TABLE public.affiliate_payouts ADD CONSTRAINT affiliate_payouts_amount_check CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE public.affiliate_payouts ADD CONSTRAINT affiliate_payouts_threshold_check CHECK (threshold_minor_snapshot >= 0)');
        DB::statement('ALTER TABLE public.affiliate_payouts ADD CONSTRAINT affiliate_payouts_currency_check CHECK (char_length(currency) = 3 AND currency = upper(currency))');
        DB::statement(<<<'SQL'
            ALTER TABLE public.affiliate_payouts
            ADD CONSTRAINT affiliate_payouts_status_timestamps_check
            CHECK (
                (status <> 'approved' OR approved_at IS NOT NULL)
                AND (status <> 'paid' OR (paid_at IS NOT NULL AND approved_at IS NOT NULL))
                AND (status <> 'rejected' OR rejected_at IS NOT NULL)
                AND (status <> 'cancelled' OR cancelled_at IS NOT NULL)
            )
            SQL);
    }

    // ── 9. Payout items — the allocation link ────────────────────────────────────

    private function createPayoutItems(): void
    {
        Schema::create('affiliate_payout_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payout_id')->constrained('affiliate_payouts')->restrictOnDelete();
            $table->foreignId('commission_id')->constrained('affiliate_commissions')->restrictOnDelete();
            // Denormalised ON PURPOSE: it is the only way to make the payout↔commission
            // affiliate agreement a foreign key instead of a hope. It is never a source of
            // truth — the composite keys below force it to equal both sides.
            $table->foreignId('affiliate_id')->constrained('affiliates')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->timestampTz('created_at')->useCurrent();

            // A commission can only be paid once within a payout, and a payout cannot
            // list the same commission twice.
            $table->unique(['payout_id', 'commission_id'], 'affiliate_payout_items_payout_commission_unique');
            $table->index('commission_id', 'affiliate_payout_items_commission_index');
        });

        DB::statement('ALTER TABLE public.affiliate_payout_items ADD CONSTRAINT affiliate_payout_items_amount_check CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE public.affiliate_payout_items ADD CONSTRAINT affiliate_payout_items_currency_check CHECK (char_length(currency) = 3 AND currency = upper(currency))');

        // ── Single affiliate, single currency — now impossible to violate ────────
        //
        // Four composite keys. The item must agree with its payout AND with the commission
        // it pays, on both axes. Paying affiliate A's commission inside affiliate B's
        // payout, or mixing XOF and USD inside one payout, is refused by PostgreSQL — so no
        // conversion can be smuggled in to reach a withdrawal threshold.
        DB::statement(<<<'SQL'
            ALTER TABLE public.affiliate_payout_items
            ADD CONSTRAINT affiliate_payout_items_payout_affiliate_foreign
            FOREIGN KEY (payout_id, affiliate_id)
            REFERENCES public.affiliate_payouts (id, affiliate_id) ON DELETE RESTRICT
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE public.affiliate_payout_items
            ADD CONSTRAINT affiliate_payout_items_commission_affiliate_foreign
            FOREIGN KEY (commission_id, affiliate_id)
            REFERENCES public.affiliate_commissions (id, affiliate_id) ON DELETE RESTRICT
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE public.affiliate_payout_items
            ADD CONSTRAINT affiliate_payout_items_payout_currency_foreign
            FOREIGN KEY (payout_id, currency)
            REFERENCES public.affiliate_payouts (id, currency) ON DELETE RESTRICT
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE public.affiliate_payout_items
            ADD CONSTRAINT affiliate_payout_items_commission_currency_foreign
            FOREIGN KEY (commission_id, currency)
            REFERENCES public.affiliate_commissions (id, currency) ON DELETE RESTRICT
            SQL);

        // The payout↔ledger link, added now that both tables exist.
        DB::statement('ALTER TABLE public.affiliate_commission_entries ADD CONSTRAINT affiliate_commission_entries_payout_foreign FOREIGN KEY (payout_id) REFERENCES public.affiliate_payouts (id) ON DELETE RESTRICT');
    }

    // ── ACL ──────────────────────────────────────────────────────────────────────

    /**
     * Fail-closed foundation: the runtime gets NOTHING — no read and no write.
     *
     * P6-D0 ships no authority, so there is no legitimate runtime access yet. Granting a
     * speculative SELECT now would have to be justified by a caller that does not exist;
     * each later gate opens exactly the privilege its own authority needs, and no more.
     *
     * No new role is created — the existing identities are enough.
     */
    private function lockDownPrivileges(): void
    {
        foreach ($this->tables() as $table) {
            DB::statement('REVOKE ALL ON TABLE public.'.$table.' FROM PUBLIC');
            DB::statement('REVOKE ALL ON TABLE public.'.$table.' FROM digitrove_runtime');
            DB::statement('REVOKE ALL ON SEQUENCE public.'.$table.'_id_seq FROM PUBLIC');
            DB::statement('REVOKE ALL ON SEQUENCE public.'.$table.'_id_seq FROM digitrove_runtime');
        }
    }
};
