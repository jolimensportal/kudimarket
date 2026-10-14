<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * No two routes may share a name.
 *
 * Laravel does not complain when a duplicate name is registered: the second route quietly takes the
 * name's place and everything works, which is why this cost a production deploy to find. The name
 * collision only becomes an error at route:cache time -- "Another route has already been assigned name
 * [trade.dispute]" -- and route:cache runs in scripts/deploy.sh and nowhere else. Not one test in the
 * suite executed it, so 232 green tests shipped a tree that could not be deployed.
 *
 * The trade dispute screen was the collision: a GET to read the form and a POST to file it, both named
 * 'dispute'. The GET keeps the plain name, because that is the URL and the link; the POST is
 * 'dispute.store'.
 */
class RouteNamesAreUniqueTest extends TestCase
{
    public function test_no_two_routes_share_a_name(): void
    {
        /** @var array<string, string> $seen name => the method+uri that claimed it */
        $seen = [];
        $collisions = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null) {
                continue;
            }

            // The method matters: GET and POST on one URI are a normal pair, and only an exact method
            // + uri repeat is a real conflict.
            $signature = implode('|', $route->methods()).' '.$route->uri();

            if (isset($seen[$name])) {
                $collisions[] = sprintf('%s claimed by [%s] and [%s]', $name, $seen[$name], $signature);

                continue;
            }

            $seen[$name] = $signature;
        }

        $this->assertSame([], $collisions, "route:cache refuses these names:\n".implode("\n", $collisions));
    }

    public function test_the_dispute_form_posts_to_a_route_of_its_own(): void
    {
        // The specific collision, named so a future rename breaks a test rather than a deploy.
        $this->assertNotNull(Route::getRoutes()->getByName('trade.dispute'));
        $this->assertNotNull(Route::getRoutes()->getByName('trade.dispute.store'));

        $get = Route::getRoutes()->getByName('trade.dispute');
        $post = Route::getRoutes()->getByName('trade.dispute.store');

        $this->assertContains('GET', $get->methods());
        $this->assertContains('POST', $post->methods());
        $this->assertSame($get->uri(), $post->uri(), 'both must be the same URL or the form posts somewhere else');
    }
}
