@extends('layouts.app')
@section('title', 'Help')
@section('content')
<div class="lg:grid lg:grid-cols-[200px_1fr] lg:gap-10">
    <aside class="mb-6 lg:mb-0">
        <nav class="lg:sticky lg:top-20" aria-label="Guide contents">
            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">In this guide</div>
            <ol class="flex gap-2 overflow-x-auto pb-2 text-sm lg:block lg:space-y-1 lg:overflow-visible lg:pb-0">
                @foreach ($toc as $item)
                    <li class="shrink-0"><a href="#{{ $item['id'] }}" class="block whitespace-nowrap rounded-md px-2 py-1 text-slate-600 hover:bg-slate-100 hover:text-slate-900 lg:whitespace-normal">{{ $item['title'] }}</a></li>
                @endforeach
            </ol>
        </nav>
    </aside>
    <article class="guide card min-w-0 p-6 sm:p-8">{!! $html !!}</article>
</div>
@endsection
