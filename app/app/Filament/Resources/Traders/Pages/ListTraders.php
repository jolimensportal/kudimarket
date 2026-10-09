<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders\Pages;

use App\Filament\Resources\Traders\TraderResource;
use Filament\Resources\Pages\ListRecords;

class ListTraders extends ListRecords
{
    protected static string $resource = TraderResource::class;
}
