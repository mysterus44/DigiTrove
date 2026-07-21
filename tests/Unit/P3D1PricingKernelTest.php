<?php

declare(strict_types=1);

use App\Services\Pricing\CouponSnapshot;
use App\Services\Pricing\DiscountAllocator;
use App\Services\Pricing\PricedLine;
use App\Services\Pricing\PricedQuote;
use App\Support\IntegerMath;
use App\Support\Money;

/*
|--------------------------------------------------------------------------
| P3-D1 — Pricing & Quote Kernel : noyau PUR (aucun accès PostgreSQL)
|--------------------------------------------------------------------------
|
| Cette suite couvre les deux briques déterministes du gate :
|   - App\Support\Money        : arithmétique monétaire entière (D-005)
|   - App\Services\Pricing\DiscountAllocator : allocation Hamilton (D-030 Q3=A)
|
| Le PricingService lui-même lit la base et vit dans la suite Feature.
*/

// ---------------------------------------------------------------------------
// IntegerMath — garde anti-dépassement d'entier
// ---------------------------------------------------------------------------

it('multiplies, adds and subtracts within the integer range', function () {
    expect(IntegerMath::multiply(12_500, 4))->toBe(50_000)
        ->and(IntegerMath::multiply(0, PHP_INT_MAX))->toBe(0)
        ->and(IntegerMath::add(5, 7))->toBe(12)
        ->and(IntegerMath::subtract(7, 5))->toBe(2);
});

it('refuses a subtraction that would overflow', function () {
    expect(fn () => IntegerMath::subtract(PHP_INT_MIN, 1))->toThrow(OverflowException::class);
});

it('refuses a multiplication that would overflow instead of degrading to float', function () {
    expect(fn () => IntegerMath::multiply(PHP_INT_MAX, 2))->toThrow(OverflowException::class);
});

it('refuses an addition that would overflow', function () {
    expect(fn () => IntegerMath::add(PHP_INT_MAX, 1))->toThrow(OverflowException::class);
});

// ---------------------------------------------------------------------------
// Money — value object entier, devise canonique
// ---------------------------------------------------------------------------

it('builds money from an integer amount and a canonical currency', function () {
    $money = Money::of(12_500, 'XOF');

    expect($money->minor)->toBe(12_500)
        ->and($money->currency)->toBe('XOF');
});

it('builds a zero amount', function () {
    expect(Money::zero('EUR')->minor)->toBe(0)
        ->and(Money::zero('EUR')->isZero())->toBeTrue();
});

it('accepts a negative amount as a value while exposing it explicitly', function () {
    expect(Money::of(-5, 'XOF')->isNegative())->toBeTrue()
        ->and(Money::of(0, 'XOF')->isNegative())->toBeFalse();
});

it('refuses a lowercase currency instead of silently normalising it', function () {
    // The database CHECK is `currency = upper(currency)`: the canonical form is
    // the ONLY accepted form, so an upstream bug surfaces here instead of being
    // masked by a strtoupper() call.
    expect(fn () => Money::of(100, 'xof'))->toThrow(InvalidArgumentException::class);
});

it('refuses a malformed currency', function (string $currency) {
    expect(fn () => Money::of(100, $currency))->toThrow(InvalidArgumentException::class);
})->with([['XO'], ['XOFF'], ['X0F'], [''], ['X F']]);

it('refuses a float amount instead of truncating it', function () {
    // strict_types turns the money contract into a hard type boundary: 1.5 must
    // never silently become 1.
    expect(fn () => Money::of(1.5, 'XOF'))->toThrow(TypeError::class); // @phpstan-ignore-line
});

it('adds and subtracts amounts sharing the same currency', function () {
    $a = Money::of(1_000, 'XOF');
    $b = Money::of(250, 'XOF');

    expect($a->add($b)->minor)->toBe(1_250)
        ->and($a->subtract($b)->minor)->toBe(750);
});

it('refuses to add two different currencies', function () {
    expect(fn () => Money::of(100, 'XOF')->add(Money::of(100, 'EUR')))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to subtract two different currencies', function () {
    expect(fn () => Money::of(100, 'XOF')->subtract(Money::of(100, 'EUR')))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to compare two different currencies', function () {
    expect(fn () => Money::of(100, 'XOF')->isGreaterThan(Money::of(100, 'EUR')))
        ->toThrow(InvalidArgumentException::class);
});

it('compares amounts sharing the same currency', function () {
    $small = Money::of(100, 'XOF');
    $large = Money::of(900, 'XOF');

    expect($large->isGreaterThan($small))->toBeTrue()
        ->and($small->isGreaterThan($large))->toBeFalse()
        ->and($small->equals(Money::of(100, 'XOF')))->toBeTrue();
});

it('refuses an addition that overflows the integer range', function () {
    expect(fn () => Money::of(PHP_INT_MAX, 'XOF')->add(Money::of(1, 'XOF')))
        ->toThrow(OverflowException::class);
});

// ---------------------------------------------------------------------------
// DiscountAllocator — méthode du plus grand reste (Hamilton)
// ---------------------------------------------------------------------------

/**
 * @param  list<array{line_id: int, product_id: int, subtotal_minor: int}>  $shares
 */
function allocateP3D1(int $discount, array $shares): array
{
    return (new DiscountAllocator)->allocate($discount, $shares);
}

it('allocates a discount that divides exactly, leaving no remainder', function () {
    $result = allocateP3D1(30, [
        ['line_id' => 10, 'product_id' => 1, 'subtotal_minor' => 100],
        ['line_id' => 20, 'product_id' => 2, 'subtotal_minor' => 200],
    ]);

    expect($result)->toBe([10 => 10, 20 => 20])
        ->and(array_sum($result))->toBe(30);
});

it('distributes a single leftover unit to the largest residue', function () {
    // 10 over three equal lines: base 3 each (9), one unit left.
    $result = allocateP3D1(10, [
        ['line_id' => 10, 'product_id' => 3, 'subtotal_minor' => 100],
        ['line_id' => 20, 'product_id' => 5, 'subtotal_minor' => 100],
        ['line_id' => 30, 'product_id' => 7, 'subtotal_minor' => 100],
    ]);

    expect(array_sum($result))->toBe(10)
        ->and($result)->toBe([10 => 4, 20 => 3, 30 => 3]);
});

it('distributes several leftover units one at a time', function () {
    $result = allocateP3D1(2, [
        ['line_id' => 10, 'product_id' => 3, 'subtotal_minor' => 100],
        ['line_id' => 20, 'product_id' => 5, 'subtotal_minor' => 100],
        ['line_id' => 30, 'product_id' => 7, 'subtotal_minor' => 100],
    ]);

    expect(array_sum($result))->toBe(2)
        ->and($result)->toBe([10 => 1, 20 => 1, 30 => 0]);
});

it('gives the leftover unit to the strictly larger residue before any tie-break', function () {
    // S = 300, D = 1 -> numerators 100 / 200: the 200-subtotal line has the
    // larger residue even though its product_id is higher.
    $result = allocateP3D1(1, [
        ['line_id' => 10, 'product_id' => 1, 'subtotal_minor' => 100],
        ['line_id' => 20, 'product_id' => 9, 'subtotal_minor' => 200],
    ]);

    expect($result)->toBe([10 => 0, 20 => 1]);
});

it('breaks a residue tie on the ascending product id', function () {
    $result = allocateP3D1(1, [
        ['line_id' => 10, 'product_id' => 5, 'subtotal_minor' => 100],
        ['line_id' => 20, 'product_id' => 3, 'subtotal_minor' => 100],
    ]);

    expect($result)->toBe([10 => 0, 20 => 1]);
});

it('breaks a full tie on the ascending line id', function () {
    $result = allocateP3D1(1, [
        ['line_id' => 30, 'product_id' => 7, 'subtotal_minor' => 100],
        ['line_id' => 12, 'product_id' => 7, 'subtotal_minor' => 100],
    ]);

    expect($result)->toBe([30 => 0, 12 => 1]);
});

it('produces the same allocation whatever the collection order', function () {
    $shares = [
        ['line_id' => 10, 'product_id' => 3, 'subtotal_minor' => 133],
        ['line_id' => 20, 'product_id' => 5, 'subtotal_minor' => 267],
        ['line_id' => 30, 'product_id' => 7, 'subtotal_minor' => 401],
    ];

    $forward = allocateP3D1(97, $shares);
    $reversed = allocateP3D1(97, array_reverse($shares));
    $shuffled = allocateP3D1(97, [$shares[1], $shares[2], $shares[0]]);

    ksort($forward);
    ksort($reversed);
    ksort($shuffled);

    expect($reversed)->toBe($forward)
        ->and($shuffled)->toBe($forward)
        ->and(array_sum($forward))->toBe(97);
});

it('allocates the whole eligible subtotal without exceeding any line', function () {
    $result = allocateP3D1(300, [
        ['line_id' => 10, 'product_id' => 1, 'subtotal_minor' => 100],
        ['line_id' => 20, 'product_id' => 2, 'subtotal_minor' => 100],
        ['line_id' => 30, 'product_id' => 3, 'subtotal_minor' => 100],
    ]);

    expect($result)->toBe([10 => 100, 20 => 100, 30 => 100])
        ->and(array_sum($result))->toBe(300);
});

it('never allocates more than a line subtotal, including a free line', function () {
    $result = allocateP3D1(50, [
        ['line_id' => 10, 'product_id' => 3, 'subtotal_minor' => 0],
        ['line_id' => 20, 'product_id' => 5, 'subtotal_minor' => 100],
    ]);

    expect($result[10])->toBe(0)
        ->and($result[20])->toBe(50)
        ->and(array_sum($result))->toBe(50);
});

it('allocates nothing when the discount is zero', function () {
    $result = allocateP3D1(0, [
        ['line_id' => 10, 'product_id' => 1, 'subtotal_minor' => 100],
        ['line_id' => 20, 'product_id' => 2, 'subtotal_minor' => 200],
    ]);

    expect($result)->toBe([10 => 0, 20 => 0]);
});

it('gives everything to a single eligible line', function () {
    expect(allocateP3D1(37, [['line_id' => 10, 'product_id' => 1, 'subtotal_minor' => 100]]))
        ->toBe([10 => 37]);
});

it('stays exact on very large integers without ever touching a float', function () {
    // Subtotals large enough that any float conversion would lose precision.
    $result = allocateP3D1(3, [
        ['line_id' => 10, 'product_id' => 1, 'subtotal_minor' => 1_000_000_000_000_001],
        ['line_id' => 20, 'product_id' => 2, 'subtotal_minor' => 1_000_000_000_000_002],
    ]);

    expect(array_sum($result))->toBe(3)
        ->and($result[10] + $result[20])->toBe(3);
});

it('refuses an allocation whose intermediate product would overflow', function () {
    expect(fn () => allocateP3D1(PHP_INT_MAX, [
        ['line_id' => 10, 'product_id' => 1, 'subtotal_minor' => PHP_INT_MAX],
    ]))->toThrow(OverflowException::class);
});

it('refuses a discount larger than the eligible subtotal', function () {
    expect(fn () => allocateP3D1(101, [
        ['line_id' => 10, 'product_id' => 1, 'subtotal_minor' => 100],
    ]))->toThrow(InvalidArgumentException::class);
});

it('refuses a negative discount', function () {
    expect(fn () => allocateP3D1(-1, [
        ['line_id' => 10, 'product_id' => 1, 'subtotal_minor' => 100],
    ]))->toThrow(InvalidArgumentException::class);
});

it('refuses to allocate a positive discount over an empty eligible base', function () {
    expect(fn () => allocateP3D1(5, []))->toThrow(InvalidArgumentException::class);

    expect(fn () => allocateP3D1(5, [
        ['line_id' => 10, 'product_id' => 1, 'subtotal_minor' => 0],
    ]))->toThrow(InvalidArgumentException::class);
});

// ===========================================================================
// P3-D1.1 — post-merge hardening (A1 to A4)
// ===========================================================================

// --- A1: the currency contract must be exactly three ASCII uppercase letters.
// PCRE's `$` also matches just before a FINAL newline, so '/^[A-Z]{3}$/'
// silently accepted "XOF\n".

it('refuses any currency that is not exactly three ASCII uppercase letters', function (string $currency) {
    expect(fn () => Money::of(100, $currency))->toThrow(InvalidArgumentException::class);
})->with([
    'trailing newline' => ["XOF\n"],
    'trailing CRLF' => ["XOF\r\n"],
    'trailing space' => ['XOF '],
    'leading space' => [' XOF'],
    'lowercase' => ['xof'],
    'trailing tab' => ["XOF\t"],
    'trailing NUL' => ["XOF\0"],
    'inner newline' => ["X\nO"],
    'non ascii lookalike' => ["XO\u{0130}"],
    'digits' => ['X0F'],
]);

it('still accepts the exact canonical currency', function () {
    expect(Money::of(100, 'XOF')->currency)->toBe('XOF')
        ->and(Money::of(0, 'EUR')->currency)->toBe('EUR');
});

// --- A4: duplicate line ids used to collapse silently, returning a sum that
// was smaller than the requested discount.

it('refuses duplicated line ids instead of silently collapsing the allocation', function () {
    expect(fn () => allocateP3D1(10, [
        ['line_id' => 7, 'product_id' => 1, 'subtotal_minor' => 100],
        ['line_id' => 7, 'product_id' => 2, 'subtotal_minor' => 100],
    ]))->toThrow(InvalidArgumentException::class);
});

it('refuses a non positive line id', function (int $lineId) {
    expect(fn () => allocateP3D1(10, [
        ['line_id' => $lineId, 'product_id' => 1, 'subtotal_minor' => 100],
    ]))->toThrow(InvalidArgumentException::class);
})->with([[0], [-1]]);

it('refuses a non positive product id', function () {
    expect(fn () => allocateP3D1(10, [
        ['line_id' => 1, 'product_id' => 0, 'subtotal_minor' => 100],
    ]))->toThrow(InvalidArgumentException::class);
});

it('always allocates the full discount for valid input', function () {
    $shares = [
        ['line_id' => 3, 'product_id' => 9, 'subtotal_minor' => 701],
        ['line_id' => 1, 'product_id' => 4, 'subtotal_minor' => 299],
        ['line_id' => 2, 'product_id' => 4, 'subtotal_minor' => 1],
    ];

    expect(array_sum(allocateP3D1(457, $shares)))->toBe(457);
});

// --- A3: the pricing DTOs must not be constructible in an inconsistent state.

function validP3D1Line(array $overrides = []): PricedLine
{
    $attributes = array_merge([
        'cartItemId' => 1,
        'productId' => 2,
        'productNameSnapshot' => 'Pack Laravel',
        'productSlugSnapshot' => 'pack-laravel',
        'productTypeSnapshot' => 'ebook',
        'unitPriceMinor' => 1_000,
        'quantity' => 2,
        'lineSubtotalMinor' => 2_000,
        'lineDiscountMinor' => 500,
        'lineTotalMinor' => 1_500,
    ], $overrides);

    return new PricedLine(...$attributes);
}

it('builds a coherent priced line', function () {
    expect(validP3D1Line()->lineTotalMinor)->toBe(1_500);
});

it('refuses an incoherent priced line', function (array $overrides) {
    expect(fn () => validP3D1Line($overrides))->toThrow(InvalidArgumentException::class);
})->with([
    'cart item id zero' => [['cartItemId' => 0]],
    'cart item id negative' => [['cartItemId' => -1]],
    'product id zero' => [['productId' => 0]],
    'blank name' => [['productNameSnapshot' => '   ']],
    'blank slug' => [['productSlugSnapshot' => "\t"]],
    'blank type' => [['productTypeSnapshot' => '']],
    'unknown type' => [['productTypeSnapshot' => 'hologram']],
    'quantity zero' => [['quantity' => 0]],
    'quantity negative' => [['quantity' => -3]],
    'negative unit price' => [['unitPriceMinor' => -1, 'lineSubtotalMinor' => -2, 'lineTotalMinor' => -2]],
    'subtotal not price times quantity' => [['lineSubtotalMinor' => 1_999, 'lineTotalMinor' => 1_499]],
    'negative discount' => [['lineDiscountMinor' => -1, 'lineTotalMinor' => 2_001]],
    'discount above subtotal' => [['lineDiscountMinor' => 2_001, 'lineTotalMinor' => -1]],
    'total not subtotal minus discount' => [['lineTotalMinor' => 1_499]],
]);

it('refuses a priced line whose subtotal would overflow', function () {
    expect(fn () => validP3D1Line([
        'unitPriceMinor' => PHP_INT_MAX,
        'quantity' => 2,
        'lineSubtotalMinor' => PHP_INT_MAX,
        'lineDiscountMinor' => 0,
        'lineTotalMinor' => PHP_INT_MAX,
    ]))->toThrow(OverflowException::class);
});

function validP3D1Snapshot(array $overrides = []): CouponSnapshot
{
    $attributes = array_merge([
        'couponId' => 1,
        'codeSnapshot' => 'PROMO10',
        'discountTypeSnapshot' => 'percent',
        'percentBasisPointsSnapshot' => 1_000,
        'fixedAmountMinorSnapshot' => null,
    ], $overrides);

    return new CouponSnapshot(...$attributes);
}

it('builds both coherent coupon snapshots', function () {
    expect(validP3D1Snapshot()->percentBasisPointsSnapshot)->toBe(1_000)
        ->and(validP3D1Snapshot([
            'discountTypeSnapshot' => 'fixed',
            'percentBasisPointsSnapshot' => null,
            'fixedAmountMinorSnapshot' => 2_500,
        ])->fixedAmountMinorSnapshot)->toBe(2_500);
});

it('refuses an incoherent coupon snapshot', function (array $overrides) {
    expect(fn () => validP3D1Snapshot($overrides))->toThrow(InvalidArgumentException::class);
})->with([
    'coupon id zero' => [['couponId' => 0]],
    'blank code' => [['codeSnapshot' => '  ']],
    'unknown type' => [['discountTypeSnapshot' => 'tiered']],
    'percent without basis points' => [['percentBasisPointsSnapshot' => null]],
    'percent basis points zero' => [['percentBasisPointsSnapshot' => 0]],
    'percent basis points above 10000' => [['percentBasisPointsSnapshot' => 10_001]],
    'percent carrying a fixed amount' => [['fixedAmountMinorSnapshot' => 500]],
    'fixed without amount' => [[
        'discountTypeSnapshot' => 'fixed',
        'percentBasisPointsSnapshot' => null,
        'fixedAmountMinorSnapshot' => null,
    ]],
    'fixed amount zero' => [[
        'discountTypeSnapshot' => 'fixed',
        'percentBasisPointsSnapshot' => null,
        'fixedAmountMinorSnapshot' => 0,
    ]],
    'fixed carrying basis points' => [[
        'discountTypeSnapshot' => 'fixed',
        'fixedAmountMinorSnapshot' => 500,
    ]],
]);

/**
 * @param  list<PricedLine>|null  $lines
 */
function validP3D1Quote(array $overrides = []): PricedQuote
{
    $attributes = array_merge([
        'currency' => 'XOF',
        'subtotalMinor' => 2_000,
        'discountMinor' => 500,
        'taxMinor' => 0,
        'totalMinor' => 1_500,
        'lines' => [validP3D1Line()],
        'couponSnapshot' => validP3D1Snapshot(),
    ], $overrides);

    return new PricedQuote(...$attributes);
}

it('builds a coherent quote', function () {
    expect(validP3D1Quote()->totalMinor)->toBe(1_500);
});

it('builds a coherent quote without any coupon', function () {
    expect(validP3D1Quote([
        'discountMinor' => 0,
        'totalMinor' => 2_000,
        'lines' => [validP3D1Line(['lineDiscountMinor' => 0, 'lineTotalMinor' => 2_000])],
        'couponSnapshot' => null,
    ])->discountMinor)->toBe(0);
});

it('refuses an incoherent quote', function (array $overrides) {
    expect(fn () => validP3D1Quote($overrides))->toThrow(InvalidArgumentException::class);
})->with([
    'no line' => [['lines' => []]],
    'foreign element in lines' => [['lines' => [validP3D1Line(), 'injected']]],
    'duplicated cart item id' => [[
        'subtotalMinor' => 4_000,
        'discountMinor' => 1_000,
        'totalMinor' => 3_000,
        'lines' => [validP3D1Line(), validP3D1Line()],
    ]],
    'invalid currency' => [['currency' => 'zzz']],
    'currency with trailing newline' => [['currency' => "XOF\n"]],
    'subtotal not the sum of lines' => [['subtotalMinor' => 1_999, 'totalMinor' => 1_499]],
    'discount not the sum of lines' => [['discountMinor' => 400, 'totalMinor' => 1_600]],
    'total breaking the order formula' => [['totalMinor' => 1_499]],
    'negative total' => [['subtotalMinor' => -1, 'totalMinor' => -1]],
    'non zero tax in this gate' => [['taxMinor' => 10, 'totalMinor' => 1_510]],
    'coupon snapshot with a zero discount' => [[
        'discountMinor' => 0,
        'totalMinor' => 2_000,
        'lines' => [validP3D1Line(['lineDiscountMinor' => 0, 'lineTotalMinor' => 2_000])],
    ]],
    'positive discount without a coupon snapshot' => [['couponSnapshot' => null]],
]);

it('keeps a hardened quote deeply immutable', function () {
    $quote = validP3D1Quote();

    expect(fn () => $quote->lines[] = 'injected')->toThrow(Error::class)
        ->and(fn () => $quote->subtotalMinor = 1)->toThrow(Error::class);

    $copy = $quote->lines;
    $copy[] = 'local';

    expect($quote->lines)->toHaveCount(1);
});
