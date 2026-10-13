<?php

namespace App\Invoicing\Entity;

enum PdfStatus: string
{
    /** Waiting for the background worker. */
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
