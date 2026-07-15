<?php

declare(strict_types=1);

namespace Tests\Integration;

use DevactionLabs\FilterablePackage\Filter;
use DevactionLabs\FilterablePackage\Traits\Filterable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;

class LikeUser extends Model
{
    use Filterable;

    public $timestamps = false;

    protected $table = 'like_users';

    protected $guarded = [];
}

beforeEach(function (): void {
    Capsule::schema()->create('like_users', function ($t): void {
        $t->id();
        $t->string('name');
    });

    LikeUser::insert([
        ['name' => 'Alice'],
        ['name' => 'BOB'],
        ['name' => 'charlie'],
    ]);
});

it('applies an ILIKE filter as a native, index-friendly LIKE (no LOWER wrapper)', function (): void {
    $this->setRequestQuery(['name' => 'bob']);

    $query = LikeUser::query()->filterable([Filter::ilike('name')]);

    expect($query->toSql())->not->toContain('LOWER(');
});

it('matches case-insensitively through ILIKE on the real connection', function (): void {
    $this->setRequestQuery(['name' => 'ali']);

    $names = LikeUser::query()->filterable([Filter::ilike('name')])->pluck('name')->all();

    expect($names)->toBe(['Alice']);
});
