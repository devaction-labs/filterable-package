<?php

use DevactionLabs\FilterablePackage\Enums\PaginationType;

it('has correct values for all pagination types', function (): void {
    expect(PaginationType::PAGINATE->value)->toBe('paginate');
    expect(PaginationType::SIMPLE->value)->toBe('simple');
    expect(PaginationType::CURSOR->value)->toBe('cursor');
});

it('checks if pagination includes total', function (): void {
    expect(PaginationType::PAGINATE->includesTotal())->toBeTrue();
    expect(PaginationType::SIMPLE->includesTotal())->toBeFalse();
    expect(PaginationType::CURSOR->includesTotal())->toBeFalse();
});

it('returns correct description for each type', function (): void {
    expect(PaginationType::PAGINATE->description())
        ->toBe('Full pagination with total count');

    expect(PaginationType::SIMPLE->description())
        ->toBe('Simplified pagination without count');

    expect(PaginationType::CURSOR->description())
        ->toBe('Cursor-based pagination for large datasets');
});
