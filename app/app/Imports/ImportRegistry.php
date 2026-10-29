<?php

namespace App\Imports;

class ImportRegistry
{
    /** @return array<string, ImportTarget> */
    public static function all(): array
    {
        $targets = [new OrganizationTarget(), new PersonTarget()];

        return collect($targets)->keyBy(fn (ImportTarget $t) => $t->key())->all();
    }

    public static function get(string $key): ?ImportTarget
    {
        return self::all()[$key] ?? null;
    }
}
