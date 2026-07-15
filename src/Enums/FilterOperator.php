<?php

declare(strict_types=1);

namespace DevactionLabs\FilterablePackage\Enums;

/**
 * Enum FilterOperator
 *
 * Defines all available SQL comparison operators for filtering.
 */
enum FilterOperator: string
{
    case EQUALS = '=';
    case LIKE = 'LIKE';
    case IN = 'IN';
    case GT = '>';
    case GTE = '>=';
    case LT = '<';
    case LTE = '<=';
    case BETWEEN = 'BETWEEN';
    case ILIKE = 'ILIKE';
    case NOT_EQUALS = '!=';
    case NOT_IN = 'NOT IN';
    case NOT_LIKE = 'NOT LIKE';
    case IS_NULL = 'IS NULL';
    case IS_NOT_NULL = 'IS NOT NULL';
    case STARTS_WITH = 'STARTS_WITH';
    case ENDS_WITH = 'ENDS_WITH';
    case FULL_TEXT = 'FULL_TEXT';
    case OR_GROUP = 'OR_GROUP';

    /**
     * Check if the operator requires a value
     */
    public function requiresValue(): bool
    {
        return ! in_array($this, [self::IS_NULL, self::IS_NOT_NULL], true);
    }

    /**
     * Check if the operator expects an array value
     */
    public function expectsArray(): bool
    {
        return in_array($this, [self::IN, self::NOT_IN, self::BETWEEN], true);
    }

    /**
     * Check if the operator is a string comparison
     */
    public function isStringComparison(): bool
    {
        return in_array($this, [
            self::LIKE,
            self::NOT_LIKE,
            self::ILIKE,
            self::STARTS_WITH,
            self::ENDS_WITH,
        ], true);
    }
}
