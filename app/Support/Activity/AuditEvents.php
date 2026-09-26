<?php

namespace App\Support\Activity;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Discrete audit events written to the existing activity_log.
 *
 * Sign-ins, sign-outs, failed sign-ins, Excel and PDF exports, imports, MEAL
 * report exports and backup downloads each become one row, in the same table
 * and through the same package the six data modules already use, so the
 * Activity Log page lists them without any change to it. IP address and user
 * agent ride along in the properties column.
 *
 * Every method is guarded: an audit row that cannot be written is logged as a
 * warning and the action it describes carries on regardless.
 */
final class AuditEvents
{
    public const LOG_AUTH = 'auth';

    public const LOG_EXCEL = 'excel';

    public const LOG_EXPORT = 'export';

    public const LOG_BACKUP = 'backup';

    public static function login(User $user, bool $remember = false): ?Activity
    {
        return self::write(
            logName: self::LOG_AUTH,
            event: 'login',
            description: 'User logged in',
            properties: ['remember' => $remember],
            subject: $user,
            causer: $user,
        );
    }

    public static function logout(User $user): ?Activity
    {
        return self::write(
            logName: self::LOG_AUTH,
            event: 'logout',
            description: 'User logged out',
            properties: [],
            subject: $user,
            causer: $user,
        );
    }

    /**
     * The attempted email is the only credential kept. The password is never
     * read from the event, let alone stored.
     */
    public static function loginFailed(?string $email, ?User $user = null): ?Activity
    {
        return self::write(
            logName: self::LOG_AUTH,
            event: 'login_failed',
            description: 'Login attempt failed',
            properties: ['email' => $email],
            subject: $user,
            causer: null,
        );
    }

    /** Excel import or export, from the event the call sites already fire. */
    public static function excel(string $module, string $action, ?User $actor, ?int $recordCount = null): ?Activity
    {
        return self::write(
            logName: self::LOG_EXCEL,
            event: $action,
            description: "{$module} Excel {$action}",
            properties: array_filter([
                'module' => $module,
                'action' => $action,
                'record_count' => $recordCount,
            ], static fn ($value): bool => $value !== null),
            subject: null,
            causer: $actor,
        );
    }

    public static function pdfExport(string $module): ?Activity
    {
        return self::write(
            logName: self::LOG_EXPORT,
            event: 'pdf_export',
            description: "{$module} PDF export",
            properties: ['module' => $module, 'format' => 'pdf'],
            subject: null,
            causer: self::currentUser(),
        );
    }

    /**
     * An export that could not be completed and was not sent.
     */
    public static function exportFailed(string $module, string $format, string $reason): ?Activity
    {
        return self::write(
            logName: self::LOG_EXPORT,
            event: 'export_failed',
            description: "{$module} {$format} export failed",
            properties: ['module' => $module, 'format' => $format, 'reason' => $reason],
            subject: null,
            causer: self::currentUser(),
        );
    }

    public static function mealExport(string $site, string $period): ?Activity
    {
        return self::write(
            logName: self::LOG_EXPORT,
            event: 'meal_export',
            description: 'MEAL report export',
            properties: ['site' => $site, 'period' => $period, 'format' => 'xlsx'],
            subject: null,
            causer: self::currentUser(),
        );
    }

    public static function backupDownload(string $filename, bool $created = false): ?Activity
    {
        return self::write(
            logName: self::LOG_BACKUP,
            event: $created ? 'backup_created' : 'backup_downloaded',
            description: $created ? 'Backup created and downloaded' : 'Backup downloaded',
            properties: ['filename' => $filename],
            subject: null,
            causer: self::currentUser(),
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function write(
        string $logName,
        string $event,
        string $description,
        array $properties,
        ?Model $subject,
        ?User $causer,
    ): ?Activity {
        if (! (bool) config('user-activity.enabled', true)) {
            return null;
        }

        try {
            $logger = activity($logName)
                ->event($event)
                ->withProperties(array_merge($properties, self::requestContext()));

            if ($subject !== null) {
                $logger->performedOn($subject);
            }

            // The package falls back to the signed-in user when no causer is
            // given, which is wrong for a failed sign-in: nobody caused it.
            if ($causer !== null) {
                $logger->causedBy($causer);
            } else {
                $logger->causedByAnonymous();
            }

            $activity = $logger->log($description);

            return $activity instanceof Activity ? $activity : null;
        } catch (Throwable $e) {
            Log::warning('Audit event could not be written: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return null;
        }
    }

    private static function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /**
     * IP and user agent of the request being served, when there is one.
     *
     * @return array<string, mixed>
     */
    private static function requestContext(): array
    {
        try {
            if (! app()->bound('request')) {
                return [];
            }

            $request = request();

            return array_filter([
                'ip' => ActivityRecorder::ip($request),
                'user_agent' => ActivityRecorder::userAgent($request),
            ], static fn ($value): bool => $value !== null);
        } catch (Throwable) {
            return [];
        }
    }
}
