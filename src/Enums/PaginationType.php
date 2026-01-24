<?php

namespace DevactionLabs\FilterablePackage\Enums;

/**
 * Enum PaginationType
 *
 * Defines available pagination strategies.
 */
enum PaginationType: string
{
    case PAGINATE = 'paginate';
    case SIMPLE = 'simple';
    case CURSOR = 'cursor';

    /**
     * Check if the pagination type includes total count
     */
    public function includesTotal(): bool
    {
        return $this === self::PAGINATE;
    }

    /**
     * Get description of the pagination type
     */
    public function description(): string
    {
        return match ($this) {
            self::PAGINATE => 'Full pagination with total count',
            self::SIMPLE => 'Simplified pagination without count',
            self::CURSOR => 'Cursor-based pagination for large datasets',
        };
    }
}
