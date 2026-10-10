<?php

declare(strict_types=1);

namespace App\Filament\Pricing\Resources\Rates\Pages;

use App\Filament\Pricing\Resources\Rates\RateResource;
use Filament\Resources\Pages\ListRecords;

class ListRates extends ListRecords
{
    protected static string $resource = RateResource::class;

    protected static ?string $title = 'Our rate';
}
