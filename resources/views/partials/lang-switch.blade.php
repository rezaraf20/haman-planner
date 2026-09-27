{{-- Language switcher: current language first, e.g. «فارسی | English» / "English | فارسی" --}}
<span class="lang-switch" aria-label="{{ __('common.language') }}">
@foreach ([app()->getLocale(), \App\Support\Locales::other()] as $i => $loc)
@if ($i)<span class="sep" aria-hidden="true">|</span>@endif
@if ($loc === app()->getLocale())<strong lang="{{ $loc }}">{{ \App\Support\Locales::label($loc) }}</strong>@else<a href="{{ \App\Support\LocaleUrls::switchTo($loc) }}" hreflang="{{ $loc }}" lang="{{ $loc }}" rel="alternate">{{ \App\Support\Locales::label($loc) }}</a>@endif
@endforeach
</span>
