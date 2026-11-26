<?php

namespace Easi\DB2\Database\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Class Builder
 *
 * @package Easi\DB2\Database\Query
 */
class Builder extends BaseBuilder
{
    /**
     * The common table expressions for the query, keyed by name.
     *
     * @var array<string, BaseBuilder>
     */
    public array $expressions = [];

    /**
     * Add a common table expression (CTE) to the query.
     *
     * @param string $name
     * @param BaseBuilder $subquery
     * @return $this
     */
    public function withExpression(string $name, BaseBuilder $subquery): static
    {
        if (!array_key_exists('expressions', $this->bindings)) {
            $this->bindings = array_merge(['expressions' => []], $this->bindings);
        }

        $this->expressions[$name] = $subquery;

        foreach ($subquery->getBindings() as $binding) {
            $this->bindings['expressions'][] = $binding;
        }

        return $this;
    }

    /**
     * Insert new records, skipping the ones that violate a unique constraint.
     *
     * Db2 has no insert-or-ignore statement. The rows go in one multi-row insert; if that hits a
     * unique violation, they are inserted one at a time and the duplicates are skipped. Db2 rolls
     * back only the failing statement, so a surrounding transaction survives.
     *
     * @param array $values One row, or a list of rows
     *
     * @return int The number of rows stored
     */
    public function insertOrIgnore(array $values): int
    {
        if (empty($values)) {
            return 0;
        }

        if (!is_array(array_first($values))) {
            $values = [$values];
        }

        try {
            $this->insert($values);

            return count($values);
        } catch (UniqueConstraintViolationException) {
            // fall through to the row-by-row insert
        }

        $stored = 0;

        foreach ($values as $row) {
            try {
                $this->insert($row);
                $stored++;
            } catch (UniqueConstraintViolationException) {
                // duplicate: ignored
            }
        }

        return $stored;
    }
}
