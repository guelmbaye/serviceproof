<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReferenceGenerator
{
    /**
     * Sequential, human-readable, tenant-scoped references (WO-1042, CLM-1042).
     *
     * Must be called inside a transaction: the row lock below is what keeps
     * two simultaneous submissions from minting the same number, and a lock
     * outside a transaction is released immediately.
     */
    public static function next(string $table, string $organizationId, string $prefix, int $start = 1001): string
    {
        // Serialise reference generation per tenant.
        //
        // Reading the highest reference and then inserting the next one is a
        // read-modify-write, and a plain SELECT inside a transaction does not
        // make it atomic. At READ COMMITTED two concurrent submissions both
        // read CLM-1044, both generate CLM-1045, and the second INSERT dies on
        // the unique index — losing a technician's claim rather than merely
        // renumbering it. Two people finishing jobs in the same second is a
        // Tuesday, not an edge case.
        //
        // Locking the tenant's own row is enough: it exists for every caller,
        // so there is no phantom to miss, and the lock is held only for the
        // remainder of the caller's transaction. References are minted once
        // per claim or work order, so the contention is nil.
        //
        // The lock is a no-op on SQLite, which serialises writers anyway, so
        // the test suite is unaffected.
        DB::table('organizations')
            ->where('id', $organizationId)
            ->lockForUpdate()
            ->first();

        $last = DB::table($table)
            ->where('organization_id', $organizationId)
            ->where('reference', 'like', $prefix.'-%')
            // Sort numerically, not alphabetically.
            //
            // Plain `ORDER BY reference DESC` is a string sort, so it holds only
            // while every reference has the same digit count. At the ten
            // thousandth record it inverts: 'CLM-9999' sorts above 'CLM-10000',
            // next() reads 9999, and it starts handing out references that
            // already exist.
            //
            // Every reference here shares one prefix, so a longer string means
            // more digits means a larger number; within a single length,
            // lexicographic order and numeric order agree. Length-then-value is
            // therefore exact, and it works identically on PostgreSQL, MySQL and
            // SQLite — which CAST(SUBSTRING(... FROM '[0-9]+$') AS INTEGER)
            // would not, since SQLite has no regex in SQL.
            //
            // The one assumption is that the numeric part carries no leading
            // zeros. next() never emits them, so only a hand-written
            // 'CLM-00042' could break this.
            ->orderByRaw('LENGTH(reference) DESC, reference DESC')
            ->value('reference');

        // substr past the prefix and its separator, rather than str_replace,
        // so a prefix that reappears inside the number cannot corrupt it.
        $next = $last ? ((int) substr($last, strlen($prefix) + 1)) + 1 : $start;

        return sprintf('%s-%d', $prefix, $next);
    }

    public static function forModel(Model $model, string $prefix, int $start = 1001): string
    {
        return self::next($model->getTable(), $model->organization_id, $prefix, $start);
    }
}
