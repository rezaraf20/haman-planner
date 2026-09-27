@extends('layouts.page')
@section('title', __('admin.system_title'))
@section('content')
<h1>{{ __('admin.system_title') }}</h1>
<div class="card tbl"><table>
@foreach ($health as $key => [$state, $value])
<tr><td>{{ __('admin.health.'.$key) }}</td><td><span class="pill {{ $state === 'ok' ? 'g' : ($state === 'warn' ? 'y' : 'r') }}">{{ __('admin.'.$state) }}</span></td><td>{{ $value }}</td></tr>
@endforeach
</table></div>
<div class="card tbl">
<h2>{{ __('admin.failed_jobs_title') }}</h2>
<table>
@forelse ($failedJobs as $j)
<tr><td class="muted">#{{ $j['id'] }}</td><td>{{ $j['queue'] }}</td><td>{{ \App\Support\LocalDate::dateTime($j['failed_at']) }}</td><td class="ltr" style="font-size:12px">{{ $j['error'] }}</td></tr>
@empty<tr><td class="muted">{{ __('admin.no_failed_jobs') }}</td></tr>@endforelse
</table></div>
<div class="card tbl">
<h2>{{ __('admin.errors_title') }}</h2>
<p class="help">{{ __('admin.errors_note') }}</p>
<table>
@forelse ($logErrors as $e)
<tr><td class="ltr" style="white-space:nowrap;font-size:12px">{{ $e['at'] }}</td><td><span class="pill r">{{ $e['level'] }}</span></td><td class="ltr" style="font-size:12px">{{ $e['message'] }}</td></tr>
@empty<tr><td class="muted">{{ __('admin.no_errors') }}</td></tr>@endforelse
</table></div>
@endsection
