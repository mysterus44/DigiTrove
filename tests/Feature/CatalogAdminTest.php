<?php

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\ProductFiles\Pages\CreateProductFile;
use App\Filament\Resources\ProductFiles\ProductFileResource;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\ProductPrice;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

function catalogAdmin(UserRole $role = UserRole::Admin, UserStatus $status = UserStatus::Active): User
{
    return User::factory()->create(compact('role', 'status'));
}

it('allows only active administrators to access every catalog resource', function (
    UserRole $role,
    UserStatus $status,
    bool $allowed,
) {
    $user = catalogAdmin($role, $status);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(ProductResource::canAccess())->toBe($allowed)
        ->and(CategoryResource::canAccess())->toBe($allowed)
        ->and(ProductFileResource::canAccess())->toBe($allowed);
})->with([
    'active admin' => [UserRole::Admin, UserStatus::Active, true],
    'active staff' => [UserRole::Staff, UserStatus::Active, false],
    'active customer' => [UserRole::Customer, UserStatus::Active, false],
    'suspended admin' => [UserRole::Admin, UserStatus::Suspended, false],
    'blocked admin' => [UserRole::Admin, UserStatus::Blocked, false],
]);

it('creates categories and draft products with an integer XOF price through Filament', function () {
    $this->actingAs(catalogAdmin());

    Livewire::test(CreateCategory::class)
        ->fillForm([
            'name' => 'Formations',
            'slug' => 'formations',
            'position' => 1,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $category = Category::query()->where('slug', 'formations')->sole();

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Formation Laravel',
            'slug' => 'formation-laravel',
            'type' => ProductType::Course->value,
            'status' => ProductStatus::Draft->value,
            'short_description' => 'Une formation pratique.',
            'categories' => [$category->id],
            'prices' => [[
                'currency' => 'XOF',
                'price_minor' => 15_000,
                'compare_at_price_minor' => 25_000,
                'is_active' => true,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('slug', 'formation-laravel')->sole();

    expect($product->status)->toBe(ProductStatus::Draft)
        ->and($product->published_at)->toBeNull()
        ->and($product->categories()->sole()->is($category))->toBeTrue()
        ->and($product->prices()->sole()->currency)->toBe('XOF')
        ->and($product->prices()->sole()->price_minor)->toBe(15_000);
});

it('stores product uploads privately and calculates their SHA-256 digest server-side', function () {
    Storage::fake('private');
    $this->actingAs(catalogAdmin());
    $product = Product::factory()->create();
    $upload = UploadedFile::fake()->createWithContent('course.zip', 'private-course-content');

    Livewire::test(CreateProductFile::class)
        ->fillForm([
            'product_id' => $product->id,
            'uploaded_file' => $upload,
            'version' => '1.0',
            'position' => 0,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $file = ProductFile::query()->sole();
    $storedContent = Storage::disk('private')->get($file->storage_path);

    expect($file->storage_disk)->toBe('private')
        ->and($file->original_name)->toBe('course.zip')
        ->and($file->size_bytes)->toBe(strlen($storedContent))
        ->and($file->checksum_sha256)->toBe(hash('sha256', $storedContent))
        ->and(Storage::disk('private')->exists($file->storage_path))->toBeTrue();
});

it('edits and soft deletes products through Filament without force deleting them', function () {
    $this->actingAs(catalogAdmin());
    $product = Product::factory()->create([
        'name' => 'Ancien titre',
        'slug' => 'ancien-titre',
        'status' => ProductStatus::Draft,
    ]);
    ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'XOF',
        'price_minor' => 4_900,
        'is_active' => true,
    ]);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'name' => 'Nouveau titre',
            'slug' => 'nouveau-titre',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->name)->toBe('Nouveau titre')
        ->and($product->slug)->toBe('nouveau-titre');

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->callAction(DeleteAction::class);

    $this->assertSoftDeleted('products', ['id' => $product->id]);
    expect(Product::withTrashed()->find($product->id))->not->toBeNull();
});

it('enforces the admin-only resource boundary over HTTP', function () {
    $admin = catalogAdmin();
    $staff = catalogAdmin(UserRole::Staff);

    $this->actingAs($admin)->get(ProductResource::getUrl('index'))->assertOk();
    auth()->logout();

    $this->actingAs($staff)->get(ProductResource::getUrl('index'))->assertForbidden();
});

/**
 * The upload physically lands on the private disk BEFORE the record is written, so a
 * failure between those two moments must take the file with it. Otherwise every retry of
 * a broken upload leaves another unreferenced blob on the private disk — invisible to the
 * catalogue, impossible to clean up from the admin, and still holding customer content.
 */
it('removes the private upload when its stream cannot be read', function () {
    $fake = Storage::fake('private');
    $this->actingAs(catalogAdmin());
    $product = Product::factory()->create();

    // Everything behaves normally except the read: the file exists, and it is the digest
    // pass that fails. This is the branch that previously threw without deleting.
    $broken = Mockery::mock($fake)->makePartial();
    $broken->shouldReceive('readStream')->andReturn(false);
    Storage::set('private', $broken);

    expect(fn () => Livewire::test(CreateProductFile::class)
        ->fillForm([
            'product_id' => $product->id,
            'uploaded_file' => UploadedFile::fake()->createWithContent('course.zip', 'private-course-content'),
            'version' => '1.0',
            'position' => 0,
            'is_active' => true,
        ])
        ->call('create'))->toThrow(RuntimeException::class);

    expect(ProductFile::query()->count())->toBe(0)
        // No orphan: the private disk is exactly as empty as before the attempt.
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});
