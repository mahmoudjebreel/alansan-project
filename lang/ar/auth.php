<?php

/**
 * Wording for the panel's sign-in screen.
 *
 * Laravel ships its own auth.php with the "failed"/"throttle" messages; this
 * file adds the branding strings the custom login page asks for and keeps the
 * framework keys alongside them so nothing falls back to English.
 *
 * @see \App\Filament\Pages\Auth\Login
 */
return [

    'failed' => 'بيانات الدخول هذه لا تطابق سجلاتنا.',
    'password' => 'كلمة المرور غير صحيحة.',
    'throttle' => 'عدد محاولات الدخول كبير جداً. الرجاء المحاولة بعد :seconds ثانية.',

    'login_title' => 'تسجيل الدخول',
    'login_heading' => 'مرحباً بعودتك',
    'login_subheading' => 'سجّل دخولك للمتابعة',
    'login_tagline' => 'نظام المسح والمتابعة التغذوية',
    'default_site_name' => 'أرض الإنسان - نظام المسح التغذوي',

];
