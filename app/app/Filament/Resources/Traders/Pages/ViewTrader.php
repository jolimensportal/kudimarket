<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders\Pages;

use App\Filament\Resources\Traders\TraderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTrader extends ViewRecord
{
    protected static string $resource = TraderResource::class;

    protected static ?string $title = 'Trader';
}
