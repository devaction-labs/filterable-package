<?php

declare(strict_types=1);

namespace Tests\Integration;

use DevactionLabs\FilterablePackage\Filter;
use DevactionLabs\FilterablePackage\Traits\Filterable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;

class FtDoc extends Model
{
    use Filterable;

    public $timestamps = false;

    protected $table = 'ft_docs';

    protected $guarded = [];
}

beforeEach(function (): void {
    Capsule::schema()->create('ft_docs', function ($t): void {
        $t->id();
        $t->string('title');
    });
});

it('does not crash on an invalid-UTF-8 full-text term (prefix mode)', function (): void {
    $this->setRequestQuery(['search' => 'ok'.chr(0xC3).chr(0x28)]);

    $filter = Filter::fullText(['title'])->setDatabaseDriver('pgsql')->setFullTextPrefixMatch(true);

    $sql = FtDoc::query()->filterable([$filter])->toSql();

    // The malformed lexeme must not produce a bare ":*" in the tsquery binding.
    expect($sql)->toBeString();
});

it('does not crash on an invalid-UTF-8 full-text term (exact mode)', function (): void {
    $this->setRequestQuery(['search' => 'ok'.chr(0xC3).chr(0x28)]);

    $filter = Filter::fullText(['title'])->setDatabaseDriver('pgsql')->setFullTextPrefixMatch(false);

    // Before the fix this threw a TypeError (null returned from a ": string" closure).
    $sql = FtDoc::query()->filterable([$filter])->toSql();

    expect($sql)->toBeString();
});

it('treats an all-hyphen term as a no-op instead of a malformed tsquery', function (): void {
    $this->setRequestQuery(['search' => '--- -']);

    $filter = Filter::fullText(['title'])->setDatabaseDriver('pgsql');

    $query = FtDoc::query()->filterable([$filter]);

    expect($query->getBindings())->toBe([]);
});
