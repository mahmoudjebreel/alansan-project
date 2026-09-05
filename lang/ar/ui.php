<?php

/**
 * Everything the panel says that is not a data field: navigation, page titles,
 * the system pages, and the SweetAlert dialogs.
 *
 * Field labels stay in fields.php - this file is the chrome around them, so
 * that switching the panel to English translates the whole screen and not just
 * the form inputs.
 */
return [

    // Navigation groups and the shared page chrome
    'nav' => [
        'dashboard' => 'لوحة التحكم',
        'data' => 'إدارة البيانات',
        'system' => 'إدارة النظام',
        'reports' => 'التقارير',
        'backup' => 'النسخ الاحتياطي',
        'trash' => 'سلة المحذوفات',
    ],

    'site' => [
        'fallback_name' => 'أرض الإنسان - نظام المسح التغذوي',
    ],

    'common' => [
        'save' => 'حفظ',
        'cancel' => 'إلغاء',
        'confirm' => 'تأكيد',
        'yes' => 'نعم',
        'close' => 'إغلاق',
        'none' => '—',
        'saved' => 'تم الحفظ',
        'failed' => 'تعذّر تنفيذ العملية',
    ],

    // -----------------------------------------------------------------
    // Trash
    // -----------------------------------------------------------------
    'trash' => [
        'title' => 'سلة المحذوفات',
        'heading' => 'سلة المحذوفات المركزية',
        'description' => 'استرجاع أية سجلات مُسحت بالخطأ وإعادتها لقوائمها الرئيسية، أو حذفها نهائياً.',
        'total' => 'إجمالي المحذوفات',
        'modules_affected' => 'الوحدات المتأثرة',
        'latest_deletion' => 'آخر عملية حذف',
        'columns' => [
            'module' => 'الوحدة التابعة',
            'name' => 'الاسم الكامل / العنوان',
            'identifier' => 'رقم الهوية / الرقم المرجعي',
            'deleted_at' => 'تاريخ الحذف',
            'deleted_by' => 'حُذف بواسطة',
            'actions' => 'الإجراءات',
        ],
        'restore' => 'استعادة',
        'force_delete' => 'حذف نهائي',
        'empty_title' => 'سلة المحذوفات فارغة',
        'empty_description' => 'لم يُحذف أي سجل بعد. أي سجل تحذفه من الوحدات سيظهر هنا ويمكن استرجاعه.',
        'confirm_restore' => [
            'title' => 'استعادة السجل',
            'text' => 'هل تريد استعادة هذا السجل وإرجاعه إلى قائمته الرئيسية؟',
            'confirm' => 'نعم، استعِد السجل',
            'success' => 'تمت استعادة السجل بنجاح',
            'error' => 'تعذّرت استعادة السجل',
        ],
        'confirm_force_delete' => [
            'title' => 'حذف نهائي',
            'text' => 'تحذير: سيتم حذف هذا السجل نهائياً من قاعدة البيانات ولا يمكن استرجاعه مطلقاً. هل ترغب بالاستمرار؟',
            'confirm' => 'نعم، احذف نهائياً',
            'success' => 'تم حذف السجل نهائياً',
            'error' => 'تعذّر حذف السجل',
        ],
    ],

    // -----------------------------------------------------------------
    // Notification settings
    // -----------------------------------------------------------------
    'notification_settings' => [
        'title' => 'إعدادات الإشعارات',
        'switch_section' => 'تشغيل الإشعارات',
        'switch_description' => 'مفتاح رئيسي لإيقاف أو تشغيل نظام الإشعارات بالكامل',
        'enabled' => 'تفعيل نظام الإشعارات',
        'enabled_help' => 'عند الإيقاف لن يتم إرسال أي إشعار، مع بقاء السجل السابق كما هو.',
        'notify_self' => 'تضمين إجراءات مدير النظام (Super Admin) في الإشعارات',
        'notify_self_help' => 'مفيد للتجربة والاختبار: عند التفعيل يتم إرسال إشعار حتى عند قيام مدير النظام بالإجراء بنفسه.',
        'actions_section' => 'أنواع الإجراءات',
        'actions_description' => 'اختر الإجراءات التي تُرسل إشعاراً',
        'enabled_actions' => 'الإجراءات المفعّلة',
        'recipients_section' => 'المستلمون',
        'recipients_description' => 'حدد المستخدمين الذين تصلهم إشعارات الإجراءات على اللوحة',
        'recipients' => 'المستلمون',
        'recipients_help' => 'أي مستخدم يمكن أن يكون مستلماً، وليس مديري النظام فقط: أضِف مستخدماً هنا ليصله جرس الإشعارات بكل إجراء يجري على اللوحة. اتركه فارغاً لإرسال الإشعارات إلى جميع مديري النظام.',
        'recipients_placeholder' => 'جميع مديري النظام',
        'no_role' => 'بدون دور',
        'grouping_section' => 'تجميع الإشعارات',
        'grouping_description' => 'دمج الإجراءات المتتابعة لنفس المستخدم في إشعار واحد',
        'group_window' => 'مدة التجميع (بالثواني)',
        'group_window_help' => 'مثال: 60 يعني دمج إضافات نفس المستخدم خلال دقيقة في إشعار واحد. الصفر يعني إرسال كل إجراء على حدة.',
        'saved' => 'تم حفظ إعدادات الإشعارات',
    ],

    // -----------------------------------------------------------------
    // Children -> Follow Up referral prompt
    // -----------------------------------------------------------------
    'referral' => [
        'title_sam' => '⚠️ تم اكتشاف حالة SAM',
        'title_mam' => '⚠️ تم اكتشاف حالة MAM',
        'child' => 'الطفل',
        'muac' => 'قياس منتصف العضد',
        'mm' => 'مم',
        'question' => 'هل تريد إحالة هذا الطفل إلى برنامج متابعة الأطفال؟',
        'confirm' => 'نعم، أحِله',
        'cancel' => 'إلغاء',
        'unnamed_child' => 'هذا الطفل',
        'declined' => 'لم تتم الإحالة إلى متابعة الأطفال. تم حفظ الزيارة في سجل الأطفال فقط.',
        'edit_referred_title' => 'تمت الإحالة إلى متابعة الأطفال',
        'edit_referred_body' => 'تم حفظ التعديل على سجل :name في الأطفال، وفُتح له ملف متابعة لأن القياس الجديد جاء :fi.',
        'already_open' => 'تم حفظ التعديل. لهذا الطفل ملف متابعة مفتوح بالفعل، فلم يُفتح ملف جديد.',
    ],

    // -----------------------------------------------------------------
    // SweetAlert dialogs shared by the whole panel
    // -----------------------------------------------------------------
    'alerts' => [
        'confirm_title' => 'تأكيد',
        'yes' => 'نعم',
        'cancel' => 'إلغاء',
        'failed' => 'تعذّر تنفيذ العملية',

        'session_expired' => [
            'title' => 'انتهت صلاحية الجلسة',
            'line_1' => 'لم يُحفظ ما أدخلته في هذه الصفحة بعد.',
            'line_2' => 'انسخ أي بيانات تحتاجها أولاً، ثم أعد تحميل الصفحة وسجّل الدخول من جديد.',
            'reload' => 'إعادة تحميل الصفحة',
            'stay' => 'البقاء في الصفحة',
        ],

        'duplicate_visit' => [
            'title' => 'تنبيه: البيانات مسجلة مسبقاً',
            'last_visit_date' => 'تاريخ آخر زيارة',
            'last_visit_type' => 'نوع الزيارة السابقة',
            'last_status_type' => 'حالة الأم السابقة',
            'confirm' => 'إضافة نفس البيانات',
            'skip' => 'تخطي',
        ],

        'group_session_duplicate' => [
            'title' => 'رقم الهوية مسجل مسبقاً',
            'last_session_date' => 'تاريخ آخر جلسة',
            'last_visit_type' => 'نوع الزيارة',
            'last_session_subject' => 'اسم الجلسة',
            'confirm' => 'جلب البيانات',
            'close' => 'إغلاق',
        ],

        'follow_up_discharge' => [
            'title' => 'القياس الأخير طبيعي',
            'child' => 'الطفل',
            'last_muac' => 'آخر قياس مواك',
            'mm' => 'ملم',
            'question' => 'هل تريد تخريج الطفل كحالة شفاء؟ سيتم إغلاق ملف المتابعة وإعادته إلى سجل الأطفال كزيارة جديدة.',
            'confirm' => 'تخريج كحالة شفاء',
            'keep' => 'إبقاؤه تحت المتابعة',
        ],
    ],

    // -----------------------------------------------------------------
    // Module names, shared by the trash, the notifications and the logs so
    // one module is never called two different things on two screens.
    // -----------------------------------------------------------------
    'modules' => [
        'child' => 'الأطفال',
        'pregnant_lactating_woman' => 'الحوامل والمرضعات',
        'group_session' => 'الجلسات الجماعية',
        'mother_to_mother' => 'جلسات أم لأم',
        'individual_counseling' => 'جلسات الإرشاد الفردي',
        'follow_up_child' => 'متابعة الأطفال',
        'follow_up_child_visit' => 'زيارات متابعة الأطفال',
        'user' => 'المستخدمون',
        'role' => 'الأدوار والصلاحيات',
    ],

    // -----------------------------------------------------------------
    // Validation messages the modules share word for word
    // -----------------------------------------------------------------
    'validation' => [
        'identity_required' => 'رقم الهوية مطلوب.',
        'identity_digits' => 'رقم الهوية يجب أن يتكون من 9 أرقام بالضبط.',
        'child_identity_required' => 'رقم هوية الطفل مطلوب.',
        'child_identity_digits' => 'رقم هوية الطفل يجب أن يتكون من 9 أرقام بالضبط.',
        'husband_identity_required' => 'رقم هوية الزوج مطلوب.',
        'husband_identity_digits' => 'رقم هوية الزوج يجب أن يتكون من 9 أرقام بالضبط.',
        'phone_required' => 'رقم الهاتف مطلوب.',
        'phone_digits' => 'رقم الهاتف يجب أن يتكون من 10 أرقام بالضبط.',
        'husband_phone_required' => 'رقم هاتف الزوج مطلوب.',
        'husband_phone_digits' => 'رقم هاتف الزوج يجب أن يتكون من 10 أرقام بالضبط.',
        'reporting_date_required' => 'تاريخ التقرير مطلوب.',
        'reporting_date_not_past' => 'لا يمكنك اختيار تاريخ قبل اليوم.',
        'muac_required' => 'منتصف العضد مطلوب.',
        'muac_integer' => 'منتصف العضد يجب أن يكون رقماً صحيحاً.',
        'muac_range' => 'منتصف العضد يجب أن يكون بين 1 و 200.',
        'email_required' => 'البريد الإلكتروني مطلوب.',
        'email_invalid' => 'يرجى إدخال بريد إلكتروني صحيح.',
        'email_taken' => 'البريد الإلكتروني مستخدم مسبقاً.',
        'role_name_taken' => 'اسم الدور مستخدم مسبقاً.',
    ],

    // Visit types, as they are shown inside an alert
    'visit_type' => [
        'new' => 'جديد',
        'follow_up' => 'متابعة',
    ],

    // The "already in the system" prompts each module raises
    'duplicate' => [
        'child_title' => 'هذا الطفل موجود مسبقاً في النظام',
        'child_confirm' => 'جلب البيانات وتحويل نوع الزيارة',
        'woman_title' => 'هذه السيدة موجودة مسبقاً في النظام',
        'woman_confirm' => 'جلب البيانات وتحويل الزيارة',
        'group_session_title' => 'رقم الهوية مسجل مسبقاً في لقاءات الندوات',
        'group_session_confirm' => 'جلب البيانات',
        'group_session_close' => 'إغلاق',
    ],

    // Tabs and sections shared across the data modules
    'tabs' => [
        'visit_and_location' => 'الزيارة والموقع',
        'child_and_parents' => 'بيانات الطفل والوالدين',
        'measurements_and_nutrition' => 'القياسات والتغذية',
        'family_and_special_cases' => 'الأسرة والحالات الخاصة',
        'personal_and_husband' => 'البيانات الشخصية والزوج',
        'nutrition_measurements' => 'القياسات التغذوية',
        'family_data' => 'بيانات الأسرة',
        'follow_up_child_data' => 'بيانات متابعة الطفل',
        'ongoing_visits' => 'الزيارات الجارية والمتابعة',
        'group_session_data' => 'بيانات لقاء الندوة',
        'participant_data' => 'بيانات المشارك/ة',
        'mother_to_mother_data' => 'بيانات جلسة من أم لأم',
        'female_participant_data' => 'بيانات المشاركة',
    ],

    'sections' => [
        'visit_data' => 'بيانات الزيارة',
        'personal_data' => 'البيانات الشخصية',
        'measurements' => 'القياسات',
        'location' => 'الموقع',
        'extra_data' => 'بيانات إضافية',
        'husband_data' => 'بيانات الزوج',
        'family_data' => 'بيانات الأسرة',
    ],

    // Marital status: the keys are what the database stores, these are only
    // what the screen shows.
    'marital' => [
        'married' => 'متزوجة',
        'divorced' => 'مطلقة',
        'widowed' => 'أرملة',
        'separated' => 'منفصلة',
        'husband_missing' => 'الزوج مفقود',
        'abandoned' => 'مهجورة',
        'suspended' => 'معلقة',
    ],

    // -----------------------------------------------------------------
    // Notification wording
    // -----------------------------------------------------------------
    'notifications' => [
        'view_record' => 'عرض السجل',
        'record_count' => ':count سجل',
        'body' => 'قام المستخدم (:actor - :role) :verb قسم [:module]',
        'body_with_label' => 'قام المستخدم (:actor - :role) :verb قسم [:module] — :label',
        'body_with_count' => 'قام المستخدم (:actor - :role) :verb قسم [:module] — :count سجلات',

        'actions' => [
            'create' => ['title' => 'إضافة سجل جديد', 'verb' => 'بإضافة سجل جديد في'],
            'update' => ['title' => 'تعديل سجل', 'verb' => 'بتعديل سجل في'],
            'delete' => ['title' => 'حذف سجل', 'verb' => 'بحذف سجل من'],
            'force_delete' => ['title' => 'حذف سجل نهائياً', 'verb' => 'بالحذف النهائي لسجل من'],
            'export' => ['title' => 'تصدير ملف Excel', 'verb' => 'بتصدير ملف Excel من'],
            'pdf_export' => ['title' => 'تصدير ملف PDF', 'verb' => 'بتصدير ملف PDF من'],
            'import' => ['title' => 'استيراد ملف Excel', 'verb' => 'باستيراد ملف Excel إلى'],
        ],
    ],

    // -----------------------------------------------------------------
    // The system pages
    // -----------------------------------------------------------------
    'notification_log' => [
        'title' => 'سجل الإشعارات',
        'datetime' => 'التاريخ والوقت',
        'action_type' => 'نوع الإجراء',
        'module' => 'القسم',
        'actor' => 'الفاعل',
        'role' => 'الدور',
        'record' => 'السجل',
        'record_count' => 'عدد السجلات',
        'priority' => 'الأولوية',
        'priority_high' => 'مرتفعة',
        'priority_medium' => 'متوسطة',
        'priority_low' => 'منخفضة',
        'status' => 'الحالة',
        'read' => 'مقروء',
        'unread' => 'غير مقروء',
        'date' => 'التاريخ',
        'from' => 'من تاريخ',
        'until' => 'إلى تاريخ',
        'export' => 'تصدير Excel',
        'link' => 'الرابط',
    ],

    'activity_log' => [
        'title' => 'سجل النشاطات',
        'log_name' => 'اسم السجل',
        'description' => 'الوصف',
        'subject_type' => 'نوع الكائن',
        'subject_id' => 'معرف الكائن',
        'causer' => 'الفاعل',
        'created_at' => 'تاريخ الإنشاء',
    ],

    'meal_report' => [
        'title' => 'تقرير MEAL الشهري',
    ],

    'cache' => [
        'clear_all_heading' => 'مسح كل أنواع الكاش دفعة واحدة',
        'clear_all_description' => 'ينفّذ جميع عمليات المسح بالتسلسل، بما فيها كاش الصلاحيات والأدوار.',
        'clear_all_button' => 'مسح كل شيء',
        'clear_all_hint' => 'استخدم هذا الزر بعد تشغيل أي Seeder أو بعد تعديل الأدوار والصلاحيات مباشرة من قاعدة البيانات، ليصبح التغيير سارياً فوراً على كل المستخدمين.',
        'clear_all_confirm' => [
            'title' => 'مسح كل أنواع الكاش',
            'text' => 'سيتم مسح كاش التطبيق والإعدادات والفيوهات والروابط والصلاحيات. هل تريد المتابعة؟',
            'confirm' => 'نعم، امسح الكل',
        ],
        'specific_heading' => 'مسح نوع محدد من الكاش',
        'specific_description' => 'اختر النوع الذي تريد مسحه فقط، دون التأثير على باقي الأنواع.',
        'clear_one_title' => 'مسح :label',
        'clear_one_text' => 'هل أنت متأكد من مسح :label؟',
        'clear_one_confirm' => 'نعم، امسح',
        'clear_button' => 'مسح',
        'title' => 'إدارة الكاش',
        'application' => ['label' => 'كاش التطبيق العام', 'description' => 'يمسح البيانات المخزَّنة مؤقتاً في نظام الكاش العام (cache:clear).'],
        'config' => ['label' => 'كاش الإعدادات', 'description' => 'يمسح ملف إعدادات النظام المجمَّع (config:clear).'],
        'view' => ['label' => 'كاش الفيوهات', 'description' => 'يمسح ملفات العرض المُصرَّفة مسبقاً (view:clear).'],
        'route' => ['label' => 'كاش الروابط', 'description' => 'يمسح ملف الروابط المجمَّع (route:clear).'],
        'permissions' => ['label' => 'كاش الصلاحيات والأدوار', 'description' => 'يجعل أي تعديل على الأدوار أو الصلاحيات ينعكس فوراً على المستخدمين دون انتظار انتهاء صلاحية الكاش.'],
        'unknown_title' => 'نوع كاش غير معروف',
        'unknown_body' => 'لا يمكن تنفيذ العملية المطلوبة.',
        'clear_failed' => 'فشل مسح :label',
        'cleared' => 'تم مسح :label بنجاح',
        'partial_title' => 'اكتمل المسح مع وجود أخطاء',
        'partial_cleared' => 'تم مسح: :list. ',
        'partial_failed' => 'تعذّر مسح: :list.',
        'all_cleared_title' => 'تم مسح كل أنواع الكاش بنجاح',
        'all_cleared_body' => 'تم مسح: :list.',
        'command_failed' => 'انتهى الأمر [:command] بحالة فشل.',
        'permission_still_cached' => 'ما زال كاش الصلاحيات موجوداً بعد محاولة المسح.',
        'permission_clear_failed' => 'تعذّر مسح كاش الصلاحيات والأدوار.',
        'separator' => '، ',
        'build_heading' => 'بناء الكاش (تسريع اللوحة)',
        'build_description' => 'يُجهّز الإعدادات والروابط وقوالب العرض مسبقاً بدل قراءتها مع كل طلب. شغّله مرة واحدة بعد كل رفع للملفات على السيرفر.',
        'build_now' => 'بناء الكاش الآن',
        'build_confirm' => [
            'title' => 'بناء الكاش',
            'text' => 'سيتم تجهيز الإعدادات والروابط وقوالب العرض مسبقاً. هذا يسرّع اللوحة بشكل ملحوظ. تذكّر إعادة تشغيله بعد أي تعديل على ملفات المشروع أو ملف .env.',
            'confirm' => 'نعم، ابنِ الكاش',
            'success' => 'تم بناء الكاش',
            'error' => 'تعذّر بناء الكاش',
        ],
        'build_config' => ['label' => 'كاش الإعدادات', 'description' => 'يجمع ملفات الإعدادات في ملف واحد بدل قراءتها كلها مع كل طلب.'],
        'build_route' => ['label' => 'كاش الروابط', 'description' => 'يجهّز جدول الروابط مسبقاً بدل إعادة تسجيله مع كل طلب.'],
        'build_view' => ['label' => 'كاش قوالب العرض', 'description' => 'يترجم قوالب Blade مسبقاً، فلا تدفع أول زيارة لكل صفحة ثمن الترجمة.'],
        'build_all_title' => 'تم بناء الكاش بنجاح',
        'build_all_body' => 'تم بناء: :list.',
        'build_failed_title' => 'فشل بناء الكاش',
        'build_failed_body' => 'تعذّر بناء :label، وتم التراجع عن كل ما بُني حتى الآن حتى تبقى اللوحة تعمل. التفاصيل: :message',
    ],

    'backups' => [
        'latest_heading' => 'أحدث نسخة احتياطية لقاعدة البيانات',
        'latest_description' => 'حفظ وتنزيل النسخة الاحتياطية المباشرة بصيغة SQL لحماية بيانات المركز من الضياع.',
        'download_latest' => 'تنزيل أحدث نسخة (:size)',
        'datetime' => 'التاريخ والتوقيت',
        'size' => 'حجم الملف',
        'filename' => 'اسم الملف',
        'none_yet' => 'لا توجد أي نسخة احتياطية حالياً. اضغط على الزر في الأعلى لإنشاء نسخة.',
        'archive_heading' => 'أرشيف النسخ الاحتياطية المحفوظة',
        'archive_description' => 'سجل متكامل بجميع الملفات المحفوظة للتنزيل أو الحذف النهائي.',
        'col_filename' => 'اسم ملف النسخة (SQL)',
        'col_created' => 'تاريخ الإنشاء والتوقيت',
        'col_size' => 'حجم الملف',
        'col_actions' => 'الإجراءات',
        'download' => 'تحميل',
        'delete' => 'حذف',
        'archive_empty' => 'لا توجد نسخ احتياطية محفوظة في الوقت الحالي.',
        'confirm_delete' => [
            'title' => 'حذف النسخة الاحتياطية',
            'text' => 'هل أنت متأكد من حذف هذه النسخة الاحتياطية؟ لا يمكن التراجع عن هذا الإجراء.',
            'confirm' => 'نعم، احذف',
            'success' => 'تم حذف النسخة الاحتياطية بنجاح',
            'error' => 'تعذّر حذف النسخة الاحتياطية',
        ],
        'navigation' => 'النسخ الاحتياطي',
        'title' => 'إدارة النسخ الاحتياطي',
        'create' => 'إنشاء وتحميل نسخة SQL مباشرة',
        'modal_heading' => 'إنشاء وتنزيل نسخة احتياطية',
        'modal_description' => 'سيتم إنشاء نسخة احتياطية من قاعدة البيانات بصيغة .sql وتنزيلها تلقائياً على جهازك. هل تريد المتابعة؟',
        'modal_submit' => 'نعم، أنشئ ونزّل النسخة',
        'created_title' => 'تم إنشاء النسخة الاحتياطية بنجاح',
        'created_body' => 'جاري تنزيل الملف على جهازك...',
        'failed_title' => 'فشل إنشاء النسخة الاحتياطية',
    ],

    'profile' => [
        'title' => 'ملفي الشخصي',
        'name' => 'الاسم',
        'avatar' => 'الصورة الشخصية',
        'updated' => 'تم تحديث الملف الشخصي بنجاح',
    ],

    'users' => [
        'singular' => 'مستخدم',
        'plural' => 'المستخدمون',
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'avatar' => 'الصورة الشخصية',
        'avatar_short' => 'الصورة',
        'roles' => 'الأدوار',
        'created_at' => 'تاريخ الإنشاء',
    ],

    'roles' => [
        'singular' => 'دور',
        'plural' => 'الأدوار',
        'name' => 'الاسم',
        'permissions' => 'الصلاحيات',
        'permissions_count' => 'عدد الصلاحيات',
        'users_count' => 'عدد المستخدمين',
        'created_at' => 'تاريخ الإنشاء',
    ],

    'settings' => [
        'navigation' => 'الإعدادات',
        'title' => 'الإعدادات العامة',
        'identity_section' => 'هوية النظام',
        'identity_description' => 'الاسم والشعار وسطر التعريف في صفحة الدخول',
        'site_name' => 'اسم النظام',
        'login_tagline' => 'سطر التعريف في صفحة الدخول',
        'login_tagline_help' => 'يظهر أسفل اسم النظام في شاشة تسجيل الدخول.',
        'logo' => 'شعار النظام',
        'logo_help' => 'يظهر في صفحة تسجيل الدخول وأعلى القائمة الجانبية. الصيغ المقبولة: SVG أو PNG أو JPG، وحجم أقصاه 2 ميجابايت.',
        'favicon' => 'أيقونة المتصفح (favicon)',
        'favicon_help' => 'الأيقونة الصغيرة في تبويب المتصفح. يُفضَّل ملف مربّع بصيغة SVG أو PNG.',
        'logo_path_help' => 'مسار الملف داخل مجلد public، مثل: images/logo.svg',
        'favicon_path' => 'مسار الأيقونة (favicon)',
        'appearance_section' => 'المظهر',
        'appearance_description' => 'ألوان اللوحة ووضع العرض الافتراضي',
        'primary_color' => 'اللون الأساسي',
        'secondary_color' => 'اللون الثانوي',
        'default_theme' => 'الوضع الافتراضي',
        'theme_system' => 'حسب إعدادات الجهاز',
        'theme_light' => 'فاتح',
        'theme_dark' => 'داكن',
        'runtime_section' => 'التشغيل',
        'runtime_description' => 'اللغة والمنطقة الزمنية وعدد السجلات في الصفحة',
        'default_locale' => 'اللغة الافتراضية',
        'timezone' => 'المنطقة الزمنية',
        'page_size' => 'عدد السجلات في الصفحة',
        'page_size_help' => 'يُطبَّق على جميع الجداول في اللوحة.',
        'support_section' => 'التواصل والدعم',
        'support_description' => 'تذييل الصفحة وبيانات التواصل',
        'support_email' => 'بريد الدعم',
        'support_phone' => 'هاتف الدعم',
        'footer_text' => 'نص التذييل',
        'contact_info' => 'معلومات التواصل',
        'save' => 'حفظ الإعدادات',
        'saved' => 'تم حفظ الإعدادات بنجاح',
    ],

    'timezones' => [
        'Asia/Gaza' => 'غزة (Asia/Gaza)',
        'Asia/Hebron' => 'الضفة الغربية (Asia/Hebron)',
        'Asia/Amman' => 'عمّان (Asia/Amman)',
        'Asia/Beirut' => 'بيروت (Asia/Beirut)',
        'Africa/Cairo' => 'القاهرة (Africa/Cairo)',
        'Asia/Riyadh' => 'الرياض (Asia/Riyadh)',
        'Europe/Istanbul' => 'إسطنبول (Europe/Istanbul)',
        'UTC' => 'التوقيت العالمي (UTC)',
    ],

    // Formatted ages, e.g. "27 شهر و 14 يوم"
    'age' => [
        'months' => ':count شهر',
        'days' => ':count يوم',
        'join' => ' و ',
        'zero' => '0 يوم',
    ],

];
