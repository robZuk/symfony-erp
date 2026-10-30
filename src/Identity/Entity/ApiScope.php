<?php

namespace App\Identity\Entity;

/**
 * What an API key may do. A key gets only the scopes its system needs: a price comparison site
 * reads the catalog, a shop also places orders.
 *
 * Each scope is a role of the API client, so endpoints check it like any other permission:
 * #[IsGranted('ROLE_API_ORDERS_WRITE')].
 */
enum ApiScope: string
{
    case CatalogRead = 'catalog:read';
    case StockRead = 'stock:read';
    case OrdersRead = 'orders:read';
    case OrdersWrite = 'orders:write';

    public function role(): string
    {
        return 'ROLE_API_'.strtoupper(str_replace(':', '_', $this->value));
    }
}
