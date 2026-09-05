<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * The site root, which only ever forwards into the panel.
 *
 * A signed-in user goes to the dashboard; anyone else goes straight to the
 * sign-in page rather than to /admin, which would only bounce them there and
 * cost a second redirect.
 *
 * A controller rather than a closure so the route table can be cached; see
 * routes/web.php.
 */
class HomeRedirectController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect(auth()->check() ? '/admin' : '/admin/login');
    }
}
