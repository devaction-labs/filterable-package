<?php

declare(strict_types=1);

namespace Tests\Integration;

use DevactionLabs\FilterablePackage\Traits\Filterable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class SortUser extends Model
{
    use Filterable;

    public $timestamps = false;

    protected $table = 'sort_users';

    protected $guarded = [];
}

beforeEach(function (): void {
    Capsule::schema()->create('sort_users', function ($t): void {
        $t->id();
        $t->string('name');
        $t->string('password');
    });

    $rows = [];
    for ($i = 1; $i <= 250; $i++) {
        $rows[] = ['name' => sprintf('user-%03d', $i), 'password' => 'secret'.$i];
    }

    SortUser::insert($rows);
});

it('rejects a PostgreSQL JSON-arrow sort injection payload', function (): void {
    $this->setRequestQuery([]);

    expect(fn () => SortUser::query()->customPaginate('paginate', 15, [
        'sort' => "-email->x'||(SELECT password FROM sort_users LIMIT 1)||'",
    ]))->toThrow(InvalidArgumentException::class, 'is not a valid column name');
});

it('rejects an array sort value instead of crashing with a TypeError', function (): void {
    $this->setRequestQuery([]);

    expect(fn () => SortUser::query()->customPaginate('paginate', 15, ['sort' => ['name']]))
        ->toThrow(InvalidArgumentException::class, 'must be a string');
});

it('clamps a request per_page to the configured maximum', function (): void {
    $this->setRequestQuery([]);

    $page = SortUser::query()->customPaginate('paginate', null, ['per_page' => 1_000_000_000]);

    expect($page->perPage())->toBe(100);
});

it('raises per_page of zero or negative to at least one', function (): void {
    $this->setRequestQuery([]);

    expect(SortUser::query()->customPaginate('paginate', null, ['per_page' => 0])->perPage())->toBe(1)
        ->and(SortUser::query()->customPaginate('paginate', null, ['per_page' => -5])->perPage())->toBe(1);
});

it('honors a config override for the per_page maximum', function (): void {
    $this->config()->set('filterable.max_per_page', 25);
    $this->setRequestQuery([]);

    expect(SortUser::query()->customPaginate('paginate', null, ['per_page' => 500])->perPage())->toBe(25);
});

it('still applies a legitimate sort', function (): void {
    $this->setRequestQuery([]);

    $first = SortUser::query()->customPaginate('paginate', 5, ['sort' => '-name'])->first();

    expect($first->name)->toBe('user-250');
});

it('permits any column sort by default (documented enumeration risk)', function (): void {
    $this->setRequestQuery([]);

    expect(SortUser::query()->customPaginate('paginate', 5, ['sort' => 'password'])->count())->toBe(5);
});

it('blocks unlisted sorts under strict_sorts when no allow-list is set', function (): void {
    $this->config()->set('filterable.strict_sorts', true);
    $this->setRequestQuery([]);

    expect(fn () => SortUser::query()->customPaginate('paginate', 15, ['sort' => 'password']))
        ->toThrow(InvalidArgumentException::class, 'Sorting is not allowed');
});

it('still enforces an explicit allow-list', function (): void {
    $this->setRequestQuery([]);

    expect(fn () => SortUser::query()->allowedSorts(['name'])->customPaginate('paginate', 15, ['sort' => 'password']))
        ->toThrow(InvalidArgumentException::class, 'is not acceptable');
});

it('allows a listed column under an explicit allow-list', function (): void {
    $this->setRequestQuery([]);

    $first = SortUser::query()->allowedSorts(['name'])->customPaginate('paginate', 5, ['sort' => '-name'])->first();

    expect($first->name)->toBe('user-250');
});
