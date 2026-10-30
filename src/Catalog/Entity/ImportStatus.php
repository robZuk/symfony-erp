<?php

namespace App\Catalog\Entity;

enum ImportStatus: string
{
    /** Waiting for the background worker. */
    case Pending = 'pending';
    /** Every row was valid and the catalog was updated. */
    case Done = 'done';
    /** The file or some rows were wrong; the catalog was left as it was. */
    case Failed = 'failed';
}
