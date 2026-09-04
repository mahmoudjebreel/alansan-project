{{--
    The brand panel above the sign-in form.

    Rendered through the AUTH_LOGIN_FORM_BEFORE hook rather than by overriding
    Filament's login template, so the form itself stays entirely Filament's.

    @param string      $siteName
    @param string      $tagline  Editable on the settings page; falls back to
                                 the translated default when it has been
                                 cleared.
    @param string|null $logoUrl  The logo uploaded on the settings page. When
                                 there is none the built-in mark is drawn, so
                                 a fresh install is never blank here.
--}}
<div class="login-brand">
    @if (filled($logoUrl ?? null))
        <img
            class="login-brand__logo"
            src="{{ $logoUrl }}"
            alt="{{ $siteName }}"
        >
    @else
        <div class="login-brand__mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 21c-4.97-3.36-8-6.71-8-10.5A4.5 4.5 0 0 1 12 7a4.5 4.5 0 0 1 8 3.5c0 3.79-3.03 7.14-8 10.5z" />
                <path d="M9 11.5h6" />
                <path d="M12 8.5v6" />
            </svg>
        </div>
    @endif

    <p class="login-brand__name">{{ $siteName }}</p>
    <p class="login-brand__tagline">{{ filled($tagline ?? null) ? $tagline : __('auth.login_tagline') }}</p>
</div>
