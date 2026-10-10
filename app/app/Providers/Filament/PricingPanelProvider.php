<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The pricing desk.
 *
 * A separate panel, on purpose. kudi-core/006 lists segregation of duties as one of four properties of
 * the rate card:
 *
 *   "kudi_pricing, separate from kudi_ops. Ops resolve disputes; pricing sets our price. If one account
 *    could do both, a compromised desk account could reprice the book and then judge its own trades.
 *    Segregation of duties, enforced in the database rather than in a policy document."
 *
 * So the rate card is NOT a page on the operations console. It is this. The database already refuses
 * set_rate to the operations role, and User::canAccessPanel refuses each desk to the other's staff, so
 * the separation holds in both directions and neither half is a matter of policy.
 *
 * The resources it discovers live in their own directory for the same reason: a pricing desk must not be
 * able to reach a Filament resource by discovery.
 */
class PricingPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('pricing')
            ->path('pricing')
            ->login()
            // Same plum as the operations console: this is one product with two desks, not two products.
            ->brandName('KudiMarket pricing')
            ->colors([
                'primary' => Color::hex('#5E2D5A'),
            ])
            ->discoverResources(in: app_path('Filament/Pricing/Resources'), for: 'App\Filament\Pricing\Resources')
            ->discoverPages(in: app_path('Filament/Pricing/Pages'), for: 'App\Filament\Pricing\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
