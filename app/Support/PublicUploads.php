<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Where the panel's uploaded images live, and how a stored path becomes a URL
 * the browser can actually fetch.
 *
 * Two things went wrong with uploads going to the `public` disk, and both are
 * why images uploaded through the panel did not appear:
 *
 *   1. That disk is storage/app/public, which is only reachable through the
 *      public/storage symlink that `php artisan storage:link` creates. There
 *      is no terminal on the server this is deployed to - the whole
 *      application is uploaded as a zip and unpacked - so that symlink is
 *      never created there and every uploaded image 404s.
 *
 *   2. Storage::url() builds an absolute URL out of APP_URL. APP_URL is
 *      http://localhost, so an image opened at 127.0.0.1:8000 - or at the real
 *      domain - was pointed at a host the browser could not reach, even where
 *      the symlink existed.
 *
 * So uploads go under public/uploads, which needs no symlink and travels with
 * the rest of the application, and every URL here is root-relative, which the
 * browser resolves against whatever host it is already on.
 */
final class PublicUploads
{
    /** The disk uploads are written to. Rooted in public/uploads. */
    public const DISK = 'uploads';

    /**
     * A URL for a stored path, or null when there is nothing to show.
     *
     * Null rather than a guessed URL on purpose: a link to a file that is not
     * there renders as a broken image on every visitor's screen with nothing
     * to say why, and the callers all have something sensible to fall back to.
     */
    public static function url(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        // An operator pointing at an image hosted elsewhere.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (self::exists(self::DISK, $path)) {
            return '/uploads/' . ltrim($path, '/');
        }

        // Uploaded before this moved, and still readable wherever the symlink
        // does exist - a local machine, or a server where it was set up once.
        if (self::exists('public', $path)) {
            return '/storage/' . ltrim($path, '/');
        }

        // A file placed in public/ by hand, which is how the organisation's
        // own logo is shipped: public/images/ard-el-insan-logo.png.
        return is_file(public_path($path)) ? '/' . ltrim($path, '/') : null;
    }

    /**
     * A missing or misconfigured disk is not a reason to take a page down.
     */
    private static function exists(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }
}
