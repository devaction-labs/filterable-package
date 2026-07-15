<?php

declare(strict_types=1);

namespace Tests\Integration;

use DevactionLabs\FilterablePackage\Filter;
use DevactionLabs\FilterablePackage\Traits\Filterable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class AnyOfProduct extends Model
{
    public $timestamps = false;

    protected $table = 'anyof_products';

    protected $guarded = [];
}

class AnyOfItem extends Model
{
    public $timestamps = false;

    protected $table = 'anyof_items';

    protected $guarded = [];

    public function product()
    {
        return $this->belongsTo(AnyOfProduct::class, 'product_id');
    }
}

class AnyOfReceipt extends Model
{
    use Filterable;

    public $timestamps = false;

    protected $table = 'anyof_receipts';

    protected $guarded = [];

    public function items()
    {
        return $this->hasMany(AnyOfItem::class, 'receipt_id');
    }
}

beforeEach(function (): void {
    Capsule::schema()->create('anyof_receipts', function ($t): void {
        $t->id();
        $t->string('receipt_number');
        $t->string('invoice_number');
        $t->string('status')->default('open');
    });
    Capsule::schema()->create('anyof_items', function ($t): void {
        $t->id();
        $t->foreignId('receipt_id');
        $t->foreignId('product_id');
    });
    Capsule::schema()->create('anyof_products', function ($t): void {
        $t->id();
        $t->string('sku');
    });

    AnyOfReceipt::insert([
        ['id' => 1, 'receipt_number' => 'GR-001', 'invoice_number' => 'INV-900', 'status' => 'open'],
        ['id' => 2, 'receipt_number' => 'GR-ABC', 'invoice_number' => 'INV-901', 'status' => 'open'],
        ['id' => 3, 'receipt_number' => 'GR-003', 'invoice_number' => 'INV-ABC', 'status' => 'closed'],
        ['id' => 4, 'receipt_number' => 'GR-004', 'invoice_number' => 'INV-904', 'status' => 'open'],
        ['id' => 5, 'receipt_number' => 'GR-005', 'invoice_number' => 'INV-905', 'status' => 'closed'],
    ]);
    AnyOfProduct::insert([['id' => 1, 'sku' => 'SKU-ABC'], ['id' => 2, 'sku' => 'SKU-XYZ']]);
    AnyOfItem::insert([
        ['id' => 1, 'receipt_id' => 4, 'product_id' => 1],
        ['id' => 2, 'receipt_id' => 5, 'product_id' => 1],
    ]);
});

it('matches a shared search term across own columns OR a relationship', function (): void {
    $this->setRequestQuery(['status' => 'open', 'search' => 'ABC']);

    $ids = AnyOfReceipt::query()->filterable([
        Filter::exact('status'),
        Filter::anyOf([
            Filter::like('receipt_number'),
            Filter::like('invoice_number'),
            Filter::relationship('items.product', 'sku', 'ILIKE'),
        ], 'search'),
    ])->pluck('id')->all();

    // 2 = receipt_number~ABC, 4 = sku~ABC. 3 (invoice~ABC) and 5 (sku~ABC) are closed.
    expect($ids)->toBe([2, 4]);
});

it('keeps the OR group AND-ed to the other filters (no row leak)', function (): void {
    $this->setRequestQuery(['status' => 'open', 'search' => 'ABC']);

    $sql = AnyOfReceipt::query()->filterable([
        Filter::exact('status'),
        Filter::anyOf([
            Filter::like('receipt_number'),
            Filter::like('invoice_number'),
            Filter::relationship('items.product', 'sku', 'ILIKE'),
        ], 'search'),
    ])->toSql();

    // The alternatives must be wrapped in a single parenthesised group after
    // "status" = ? and (...). A closed receipt matching invoice_number must
    // never leak past the status constraint.
    expect($sql)->toContain('"status" = ? and ((')
        ->and($sql)->toContain('or (exists (');
});

it('adds no SQL when the shared search box is empty', function (): void {
    $this->setRequestQuery(['status' => 'open']);

    $query = AnyOfReceipt::query()->filterable([
        Filter::exact('status'),
        Filter::anyOf([
            Filter::like('receipt_number'),
            Filter::relationship('items.product', 'sku', 'ILIKE'),
        ], 'search'),
    ]);

    expect($query->toSql())->toBe('select * from "anyof_receipts" where "status" = ?')
        ->and($query->pluck('id')->all())->toBe([1, 2, 4]);
});

it('supports an OR group over independent request keys when filterBy is null', function (): void {
    $this->setRequestQuery(['receipt_number' => 'GR-004', 'invoice_number' => 'INV-901']);

    $ids = AnyOfReceipt::query()->filterable([
        Filter::anyOf([
            Filter::exact('receipt_number'),
            Filter::exact('invoice_number'),
        ]),
    ])->pluck('id')->sort()->values()->all();

    // receipt_number=GR-004 -> id 4, invoice_number=INV-901 -> id 2
    expect($ids)->toBe([2, 4]);
});

it('only ORs the children that actually received a value', function (): void {
    $this->setRequestQuery(['search' => 'GR-004']);

    $query = AnyOfReceipt::query()->filterable([
        Filter::anyOf([
            Filter::like('receipt_number'),
            Filter::like('invoice_number'),
        ], 'search'),
    ]);

    expect($query->pluck('id')->all())->toBe([4]);
});

it('rejects a non-Filter child', function (): void {
    expect(fn (): Filter => Filter::anyOf(['not-a-filter']))
        ->toThrow(InvalidArgumentException::class);
});
