<?php

namespace App\Imports;

class ImportRegistry
{
    /** @return array<string, ImportTarget> */
    public static function all(): array
    {
        $targets = [new HoldingTarget(), new OrganizationTarget(), new BranchTarget(), new AreaTarget(), new CostCenterTarget(), new ProjectTarget(), new PersonTarget()];

        return collect($targets)->keyBy(fn (ImportTarget $t) => $t->key())->all();
    }

    public static function get(string $key): ?ImportTarget
    {
        return self::all()[$key] ?? null;
    }
}
