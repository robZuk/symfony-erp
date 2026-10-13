<?php

namespace App\Shared\Audit;

/**
 * An entity whose every creation, change and removal goes into the activity log automatically.
 * Implement it on master data: records people edit directly and whose history matters.
 */
interface Audited
{
    /**
     * A stable code for the kind of record, used as the prefix of the logged action: "catalog.product".
     */
    public static function auditCode(): string;

    /**
     * The record in words, in the accusative, as it should read after "Dodano" or "Zmieniono": "produkt KAB-1".
     */
    public function auditLabel(): string;

    /**
     * The fields worth reporting when they change: property name => label, or [label, format].
     * Formats: "money" (grosze) and "quantity" (thousandths). Fields that are not listed are ignored,
     * which is also how secrets stay out of the log.
     *
     * @return array<string, string|array{string, string}>
     */
    public static function auditFields(): array;

    /**
     * Route and parameters of the page where the record can be seen, or null if there is none.
     *
     * @return array{string, array<string, int|string>}|null
     */
    public function auditRoute(): ?array;
}
