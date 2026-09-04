<?php

/**
 * English counterpart of lang/ar/ui.php. Keep the two in step: a key added to
 * one belongs in the other, or the panel falls back mid-sentence.
 */
return [

    'nav' => [
        'dashboard' => 'Dashboard',
        'data' => 'Data management',
        'system' => 'System management',
        'reports' => 'Reports',
        'backup' => 'Backups',
        'trash' => 'Trash',
    ],

    'site' => [
        'fallback_name' => 'Terre des hommes - Nutrition Survey System',
    ],

    'common' => [
        'save' => 'Save',
        'cancel' => 'Cancel',
        'confirm' => 'Confirm',
        'yes' => 'Yes',
        'close' => 'Close',
        'none' => '—',
        'saved' => 'Saved',
        'failed' => 'The operation could not be completed',
    ],

    // -----------------------------------------------------------------
    // Trash
    // -----------------------------------------------------------------
    'trash' => [
        'title' => 'Trash',
        'heading' => 'Central trash',
        'description' => 'Restore records deleted by mistake back to their own listings, or delete them for good.',
        'total' => 'Deleted records',
        'modules_affected' => 'Modules affected',
        'latest_deletion' => 'Last deletion',
        'columns' => [
            'module' => 'Module',
            'name' => 'Full name / title',
            'identifier' => 'ID number / reference',
            'deleted_at' => 'Deleted at',
            'deleted_by' => 'Deleted by',
            'actions' => 'Actions',
        ],
        'restore' => 'Restore',
        'force_delete' => 'Delete permanently',
        'empty_title' => 'The trash is empty',
        'empty_description' => 'Nothing has been deleted yet. Any record you delete from a module shows up here and can be restored.',
        'confirm_restore' => [
            'title' => 'Restore record',
            'text' => 'Restore this record and return it to its own listing?',
            'confirm' => 'Yes, restore it',
            'success' => 'The record was restored',
            'error' => 'The record could not be restored',
        ],
        'confirm_force_delete' => [
            'title' => 'Delete permanently',
            'text' => 'Warning: this record will be removed from the database for good and cannot be recovered. Continue?',
            'confirm' => 'Yes, delete permanently',
            'success' => 'The record was permanently deleted',
            'error' => 'The record could not be deleted',
        ],
    ],

    // -----------------------------------------------------------------
    // Notification settings
    // -----------------------------------------------------------------
    'notification_settings' => [
        'title' => 'Notification settings',
        'switch_section' => 'Notifications',
        'switch_description' => 'Master switch for the whole notification system',
        'enabled' => 'Enable notifications',
        'enabled_help' => 'While off, nothing is sent; the existing log is left untouched.',
        'notify_self' => 'Include a Super Admin\'s own actions',
        'notify_self_help' => 'Useful while testing: when on, an action notifies even if a Super Admin performed it themselves.',
        'actions_section' => 'Action types',
        'actions_description' => 'Choose which actions send a notification',
        'enabled_actions' => 'Enabled actions',
        'recipients_section' => 'Recipients',
        'recipients_description' => 'Choose who receives the panel\'s action notifications',
        'recipients' => 'Recipients',
        'recipients_help' => 'Any user can be a recipient, not only Super Admins: add someone here and the notification bell reports every action on the panel to them. Leave it empty to notify every Super Admin.',
        'recipients_placeholder' => 'Every Super Admin',
        'no_role' => 'no role',
        'grouping_section' => 'Grouping',
        'grouping_description' => 'Fold repeated actions by the same user into one notification',
        'group_window' => 'Grouping window (seconds)',
        'group_window_help' => 'For example 60 folds one user\'s additions within a minute into a single notification. Zero sends every action on its own.',
        'saved' => 'Notification settings saved',
    ],

    // -----------------------------------------------------------------
    // Children -> Follow Up referral prompt
    // -----------------------------------------------------------------
    'referral' => [
        'title_sam' => '⚠️ SAM case detected',
        'title_mam' => '⚠️ MAM case detected',
        'child' => 'Child',
        'muac' => 'MUAC',
        'mm' => 'mm',
        'question' => 'Refer this child to the child follow-up programme?',
        'confirm' => 'Yes, refer',
        'cancel' => 'Cancel',
        'unnamed_child' => 'this child',
        'declined' => 'No referral was made. The visit was saved in the Children module only.',
        'edit_referred_title' => 'Referred to child follow-up',
        'edit_referred_body' => 'The change to :name was saved in Children, and a follow-up episode was opened because the new reading came back :fi.',
        'already_open' => 'The change was saved. This child already has an open follow-up episode, so no new one was opened.',
    ],

    // -----------------------------------------------------------------
    // SweetAlert dialogs shared by the whole panel
    // -----------------------------------------------------------------
    'alerts' => [
        'confirm_title' => 'Please confirm',
        'yes' => 'Yes',
        'cancel' => 'Cancel',
        'failed' => 'The operation could not be completed',

        'session_expired' => [
            'title' => 'Your session has expired',
            'line_1' => 'Nothing you typed on this page has been saved yet.',
            'line_2' => 'Copy anything you need first, then reload the page and sign in again.',
            'reload' => 'Reload the page',
            'stay' => 'Stay on this page',
        ],

        'duplicate_visit' => [
            'title' => 'Already recorded',
            'last_visit_date' => 'Last visit date',
            'last_visit_type' => 'Previous visit type',
            'last_status_type' => 'Previous status',
            'confirm' => 'Record it anyway',
            'skip' => 'Skip',
        ],

        'group_session_duplicate' => [
            'title' => 'This ID number is already registered',
            'last_session_date' => 'Last session date',
            'last_visit_type' => 'Visit type',
            'last_session_subject' => 'Session subject',
            'confirm' => 'Load the data',
            'close' => 'Close',
        ],

        'follow_up_discharge' => [
            'title' => 'The latest reading is normal',
            'child' => 'Child',
            'last_muac' => 'Latest MUAC',
            'mm' => 'mm',
            'question' => 'Discharge this child as cured? The follow-up episode is closed and the child returns to the Children module as a new visit.',
            'confirm' => 'Discharge as cured',
            'keep' => 'Keep under follow-up',
        ],
    ],

    // -----------------------------------------------------------------
    // Module names, shared by the trash, the notifications and the logs so
    // one module is never called two different things on two screens.
    // -----------------------------------------------------------------
    'modules' => [
        'child' => 'Children',
        'pregnant_lactating_woman' => 'Pregnant & lactating women',
        'group_session' => 'Group sessions',
        'mother_to_mother' => 'Mother-to-mother sessions',
        'individual_counseling' => 'Individual counselling',
        'follow_up_child' => 'Child follow-up',
        'follow_up_child_visit' => 'Child follow-up visits',
        'user' => 'Users',
        'role' => 'Roles & permissions',
    ],

    // -----------------------------------------------------------------
    // Validation messages the modules share word for word
    // -----------------------------------------------------------------
    'validation' => [
        'identity_required' => 'The ID number is required.',
        'identity_digits' => 'The ID number must be exactly 9 digits.',
        'child_identity_required' => 'The child\'s ID number is required.',
        'child_identity_digits' => 'The child\'s ID number must be exactly 9 digits.',
        'husband_identity_required' => 'The husband\'s ID number is required.',
        'husband_identity_digits' => 'The husband\'s ID number must be exactly 9 digits.',
        'phone_required' => 'The phone number is required.',
        'phone_digits' => 'The phone number must be exactly 10 digits.',
        'husband_phone_required' => 'The husband\'s phone number is required.',
        'husband_phone_digits' => 'The husband\'s phone number must be exactly 10 digits.',
        'reporting_date_required' => 'The reporting date is required.',
        'reporting_date_not_past' => 'You cannot pick a date before today.',
        'muac_required' => 'MUAC is required.',
        'muac_integer' => 'MUAC must be a whole number.',
        'muac_range' => 'MUAC must be between 1 and 200.',
        'email_required' => 'The email address is required.',
        'email_invalid' => 'Please enter a valid email address.',
        'email_taken' => 'That email address is already in use.',
        'role_name_taken' => 'That role name is already in use.',
    ],

    'visit_type' => [
        'new' => 'New',
        'follow_up' => 'Follow-up',
    ],

    'duplicate' => [
        'child_title' => 'This child is already in the system',
        'child_confirm' => 'Load the data and switch the visit type',
        'woman_title' => 'This woman is already in the system',
        'woman_confirm' => 'Load the data and switch the visit',
        'group_session_title' => 'This ID number is already registered on a group session',
        'group_session_confirm' => 'Load the data',
        'group_session_close' => 'Close',
    ],

    'tabs' => [
        'visit_and_location' => 'Visit & location',
        'child_and_parents' => 'Child & parents',
        'measurements_and_nutrition' => 'Measurements & nutrition',
        'family_and_special_cases' => 'Family & special cases',
        'personal_and_husband' => 'Personal & husband',
        'nutrition_measurements' => 'Nutrition measurements',
        'family_data' => 'Family',
        'follow_up_child_data' => 'Follow-up details',
        'ongoing_visits' => 'Visits & follow-up',
        'group_session_data' => 'Session details',
        'participant_data' => 'Participant',
        'mother_to_mother_data' => 'Mother-to-mother session',
        'female_participant_data' => 'Participant',
    ],

    'sections' => [
        'visit_data' => 'Visit',
        'personal_data' => 'Personal details',
        'measurements' => 'Measurements',
        'location' => 'Location',
        'extra_data' => 'Additional details',
        'husband_data' => 'Husband',
        'family_data' => 'Family',
    ],

    'marital' => [
        'married' => 'Married',
        'divorced' => 'Divorced',
        'widowed' => 'Widowed',
        'separated' => 'Separated',
        'husband_missing' => 'Husband missing',
        'abandoned' => 'Abandoned',
        'suspended' => 'Marriage in limbo',
    ],

    // -----------------------------------------------------------------
    // Notification wording
    // -----------------------------------------------------------------
    'notifications' => [
        'view_record' => 'Open the record',
        'record_count' => ':count records',
        'body' => ':actor (:role) :verb the [:module] module',
        'body_with_label' => ':actor (:role) :verb the [:module] module — :label',
        'body_with_count' => ':actor (:role) :verb the [:module] module — :count records',

        'actions' => [
            'create' => ['title' => 'New record added', 'verb' => 'added a new record to'],
            'update' => ['title' => 'Record updated', 'verb' => 'updated a record in'],
            'delete' => ['title' => 'Record deleted', 'verb' => 'deleted a record from'],
            'force_delete' => ['title' => 'Record permanently deleted', 'verb' => 'permanently deleted a record from'],
            'export' => ['title' => 'Excel export', 'verb' => 'exported an Excel file from'],
            'pdf_export' => ['title' => 'PDF export', 'verb' => 'exported a PDF file from'],
            'import' => ['title' => 'Excel import', 'verb' => 'imported an Excel file into'],
        ],
    ],

    // -----------------------------------------------------------------
    // The system pages
    // -----------------------------------------------------------------
    'notification_log' => [
        'title' => 'Notification log',
        'datetime' => 'Date & time',
        'action_type' => 'Action',
        'module' => 'Module',
        'actor' => 'User',
        'role' => 'Role',
        'record' => 'Record',
        'record_count' => 'Records',
        'priority' => 'Priority',
        'priority_high' => 'High',
        'priority_medium' => 'Medium',
        'priority_low' => 'Low',
        'status' => 'Status',
        'read' => 'Read',
        'unread' => 'Unread',
        'date' => 'Date',
        'from' => 'From',
        'until' => 'Until',
        'export' => 'Export to Excel',
        'link' => 'Link',
    ],

    'activity_log' => [
        'title' => 'Activity log',
        'log_name' => 'Log',
        'description' => 'Description',
        'subject_type' => 'Subject type',
        'subject_id' => 'Subject id',
        'causer' => 'User',
        'created_at' => 'Created at',
    ],

    'meal_report' => [
        'title' => 'Monthly MEAL report',
    ],

    'cache' => [
        'clear_all_heading' => 'Clear every cache at once',
        'clear_all_description' => 'Runs every clear in sequence, the roles and permissions cache included.',
        'clear_all_button' => 'Clear everything',
        'clear_all_hint' => 'Use this after running a seeder, or after editing roles and permissions straight in the database, so the change reaches every user at once.',
        'clear_all_confirm' => [
            'title' => 'Clear every cache',
            'text' => 'The application, config, view, route and permission caches will all be cleared. Continue?',
            'confirm' => 'Yes, clear everything',
        ],
        'specific_heading' => 'Clear one cache',
        'specific_description' => 'Pick the one you want cleared, leaving the others alone.',
        'clear_one_title' => 'Clear :label',
        'clear_one_text' => 'Clear :label?',
        'clear_one_confirm' => 'Yes, clear it',
        'clear_button' => 'Clear',
        'title' => 'Cache management',
        'application' => ['label' => 'Application cache', 'description' => 'Clears the general application cache (cache:clear).'],
        'config' => ['label' => 'Config cache', 'description' => 'Clears the compiled configuration file (config:clear).'],
        'view' => ['label' => 'View cache', 'description' => 'Clears the pre-compiled Blade views (view:clear).'],
        'route' => ['label' => 'Route cache', 'description' => 'Clears the compiled route file (route:clear).'],
        'permissions' => ['label' => 'Roles & permissions cache', 'description' => 'Makes any change to roles or permissions apply to users at once instead of waiting for the cache to expire.'],
        'unknown_title' => 'Unknown cache type',
        'unknown_body' => 'That operation cannot be carried out.',
        'clear_failed' => 'Could not clear :label',
        'cleared' => ':label cleared',
        'partial_title' => 'Finished with errors',
        'partial_cleared' => 'Cleared: :list. ',
        'partial_failed' => 'Could not clear: :list.',
        'all_cleared_title' => 'Every cache was cleared',
        'all_cleared_body' => 'Cleared: :list.',
        'command_failed' => 'The [:command] command exited with a failure.',
        'permission_still_cached' => 'The permission cache is still there after the attempt to clear it.',
        'permission_clear_failed' => 'The roles and permissions cache could not be cleared.',
        'separator' => ', ',
    ],

    'backups' => [
        'latest_heading' => 'Latest database backup',
        'latest_description' => 'Create and download a direct SQL backup so the centre\'s data cannot be lost.',
        'download_latest' => 'Download the latest backup (:size)',
        'datetime' => 'Date & time',
        'size' => 'File size',
        'filename' => 'File name',
        'none_yet' => 'There is no backup yet. Use the button above to create one.',
        'archive_heading' => 'Stored backups',
        'archive_description' => 'Every stored file, to download or delete for good.',
        'col_filename' => 'Backup file (SQL)',
        'col_created' => 'Created at',
        'col_size' => 'File size',
        'col_actions' => 'Actions',
        'download' => 'Download',
        'delete' => 'Delete',
        'archive_empty' => 'No backups are stored at the moment.',
        'confirm_delete' => [
            'title' => 'Delete backup',
            'text' => 'Delete this backup? This cannot be undone.',
            'confirm' => 'Yes, delete it',
            'success' => 'The backup was deleted',
            'error' => 'The backup could not be deleted',
        ],
        'navigation' => 'Backups',
        'title' => 'Backup management',
        'create' => 'Create and download an SQL backup',
        'modal_heading' => 'Create and download a backup',
        'modal_description' => 'A .sql backup of the database will be created and downloaded to your machine. Continue?',
        'modal_submit' => 'Yes, create and download',
        'created_title' => 'Backup created',
        'created_body' => 'The file is downloading to your machine...',
        'failed_title' => 'The backup could not be created',
    ],

    'profile' => [
        'title' => 'My profile',
        'name' => 'Name',
        'avatar' => 'Profile picture',
        'updated' => 'Your profile was updated',
    ],

    'users' => [
        'singular' => 'user',
        'plural' => 'Users',
        'name' => 'Name',
        'email' => 'Email address',
        'password' => 'Password',
        'password_confirmation' => 'Confirm password',
        'avatar' => 'Profile picture',
        'avatar_short' => 'Picture',
        'roles' => 'Roles',
        'created_at' => 'Created at',
    ],

    'roles' => [
        'singular' => 'role',
        'plural' => 'Roles',
        'name' => 'Name',
        'permissions' => 'Permissions',
        'permissions_count' => 'Permissions',
        'users_count' => 'Users',
        'created_at' => 'Created at',
    ],

    'settings' => [
        'navigation' => 'Settings',
        'title' => 'General settings',
        'identity_section' => 'Identity',
        'identity_description' => 'The name, logo and login tagline',
        'site_name' => 'System name',
        'login_tagline' => 'Login tagline',
        'login_tagline_help' => 'Shown under the system name on the sign-in screen.',
        'logo' => 'System logo',
        'logo_help' => 'Shown on the sign-in page and at the top of the sidebar. SVG, PNG or JPG, up to 2 MB.',
        'favicon' => 'Browser icon (favicon)',
        'favicon_help' => 'The small icon in the browser tab. A square SVG or PNG works best.',
        'logo_path_help' => 'Path inside the public folder, for example images/logo.svg',
        'favicon_path' => 'Favicon path',
        'appearance_section' => 'Appearance',
        'appearance_description' => 'Panel colours and the default theme',
        'primary_color' => 'Primary colour',
        'secondary_color' => 'Secondary colour',
        'default_theme' => 'Default theme',
        'theme_system' => 'Follow the device',
        'theme_light' => 'Light',
        'theme_dark' => 'Dark',
        'runtime_section' => 'Runtime',
        'runtime_description' => 'Language, time zone and rows per page',
        'default_locale' => 'Default language',
        'timezone' => 'Time zone',
        'page_size' => 'Rows per page',
        'page_size_help' => 'Applies to every table in the panel.',
        'support_section' => 'Contact & support',
        'support_description' => 'Footer and contact details',
        'support_email' => 'Support email',
        'support_phone' => 'Support phone',
        'footer_text' => 'Footer text',
        'contact_info' => 'Contact details',
        'save' => 'Save settings',
        'saved' => 'Settings saved',
    ],

    'timezones' => [
        'Asia/Gaza' => 'Gaza (Asia/Gaza)',
        'Asia/Hebron' => 'West Bank (Asia/Hebron)',
        'Asia/Amman' => 'Amman (Asia/Amman)',
        'Asia/Beirut' => 'Beirut (Asia/Beirut)',
        'Africa/Cairo' => 'Cairo (Africa/Cairo)',
        'Asia/Riyadh' => 'Riyadh (Asia/Riyadh)',
        'Europe/Istanbul' => 'Istanbul (Europe/Istanbul)',
        'UTC' => 'Coordinated Universal Time (UTC)',
    ],

    // Formatted ages, e.g. "27 months and 14 days"
    'age' => [
        'months' => ':count months',
        'days' => ':count days',
        'join' => ' and ',
        'zero' => '0 days',
    ],

];
