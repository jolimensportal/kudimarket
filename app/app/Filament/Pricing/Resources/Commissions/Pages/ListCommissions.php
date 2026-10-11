<?php

declare(strict_types=1);

namespace App\Filament\Pricing\Resources\Commissions\Pages;

use App\Filament\Pricing\Resources\Commissions\CommissionResource;
use Filament\Resources\Pages\ListRecords;

class ListCommissions extends ListRecords
{
    protected static string $resource = CommissionResource::class;

    protected static ?string $title = 'What we charge';
}
