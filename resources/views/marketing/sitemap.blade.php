{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($urls as $u)
@foreach (['fa', 'en'] as $lang)
<url>
<loc>{{ $u[$lang] }}</loc>
<xhtml:link rel="alternate" hreflang="fa" href="{{ $u['fa'] }}"/>
<xhtml:link rel="alternate" hreflang="en" href="{{ $u['en'] }}"/>
<xhtml:link rel="alternate" hreflang="x-default" href="{{ $u['en'] }}"/>
</url>
@endforeach
@endforeach
</urlset>
