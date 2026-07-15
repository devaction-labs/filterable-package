<?php

declare(strict_types=1);

namespace Tests\Integration;

use DevactionLabs\FilterablePackage\MCP\Tools\GenerateFiltersTool;
use DevactionLabs\FilterablePackage\MCP\Tools\GetModelSchemaTool;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class McpCustomer extends Model
{
    public $timestamps = false;

    protected $table = 'mcp_customers';

    protected $guarded = [];
}

class McpOrder extends Model
{
    public $timestamps = false;

    protected $table = 'mcp_orders';

    protected $guarded = [];

    // A genuine relationship without a declared return type.
    public function customer()
    {
        return $this->belongsTo(McpCustomer::class, 'customer_id');
    }

    // A genuine relationship with a declared return type.
    public function lines(): HasMany
    {
        return $this->hasMany(McpCustomer::class, 'customer_id');
    }

    // An ordinary domain method that must NEVER be invoked by introspection.
    public function notifyWarehouse(): int
    {
        Capsule::table('mcp_audit')->insert(['what' => 'notifyWarehouse ran']);

        return 1;
    }

    public function recalculateTotals(): void
    {
        Capsule::table('mcp_audit')->insert(['what' => 'recalculateTotals ran']);
    }
}

beforeEach(function (): void {
    Capsule::schema()->create('mcp_customers', function ($t): void {
        $t->id();
    });
    Capsule::schema()->create('mcp_orders', function ($t): void {
        $t->id();
        $t->foreignId('customer_id')->nullable();
        $t->string('status')->default('open');
    });
    Capsule::schema()->create('mcp_audit', function ($t): void {
        $t->id();
        $t->string('what');
    });
});

it('detects relationships without invoking unrelated model methods', function (): void {
    $output = (new GetModelSchemaTool)->execute(['model' => McpOrder::class]);

    expect(Capsule::table('mcp_audit')->count())->toBe(0)
        ->and($output)->toContain('customer: BelongsTo')
        ->and($output)->toContain('lines: HasMany')
        ->and($output)->not->toContain('notifyWarehouse');
});

it('generates filters without triggering model side effects', function (): void {
    $output = (new GenerateFiltersTool)->execute(['model' => McpOrder::class]);

    expect(Capsule::table('mcp_audit')->count())->toBe(0)
        ->and($output)->toContain('Filter::');
});
