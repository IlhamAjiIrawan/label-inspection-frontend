@extends('layouts.app')

@section('title', 'Dashboard — Label Inspection')

@section('content')
<nav class="sticky top-0 z-50 bg-[#F8F9FA] border-b border-[#6C757D]/20">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                <img src="{{ asset('images/logo1.png') }}" alt="Label Inspection logo"
                     width="159" height="36" class="h-9 w-auto" />
            </a>

            <div class="hidden md:flex items-center gap-8 text-sm font-medium text-[#6C757D]">
                <a href="{{ route('dashboard') }}" class="text-[#0284C7] font-semibold">Dashboard</a>
                <a href="{{ route('reference') }}" class="hover:text-[#0284C7] transition">Reference</a>
                <a href="{{ route('check') }}" class="hover:text-[#0284C7] transition">Check Label</a>
            </div>

            <div class="flex items-center gap-3">
                <div id="user-panel" class="hidden sm:flex items-center gap-3">
                    <div class="text-right hidden md:block">
                        <p id="user-name" class="text-sm font-semibold leading-none text-[#212529]"></p>
                        <p id="user-nik" class="text-xs leading-none text-[#6C757D] tabular-nums"></p>
                    </div>
                    <span id="user-initial" class="hidden sm:inline-flex size-8 items-center justify-center rounded-full bg-[#0284C7] text-white text-sm font-bold"></span>
                </div>
                <button id="logout-btn" class="px-4 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold hover:bg-[#E9ECEF] transition disabled:opacity-60 disabled:cursor-not-allowed">
                    Logout
                </button>
            </div>
        </div>
    </div>
</nav>

<main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10 md:py-14">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
        <div>
            <h1 class="text-2xl md:text-3xl font-bold text-[#212529]">Dashboard</h1>
            <p class="mt-1 text-sm text-[#6C757D]">Manage reference labels and inspect your production labels.</p>
        </div>
        <p id="today-date" class="text-sm text-[#6C757D]">-</p>
    </div>

    <div class="mt-8 grid gap-6 md:grid-cols-2">
        <a href="{{ route('reference') }}"
           class="group bg-[#E9ECEF] rounded-2xl border border-[#6C757D]/30 shadow-sm p-6 md:p-8 transition hover:border-[#6C757D]/50 hover:shadow-md">
            <div class="flex items-start justify-between">
                <span class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-[#F8F9FA] text-[#6C757D] border border-[#6C757D]/20">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </span>
                <svg class="w-5 h-5 text-[#6C757D]/40 transition group-hover:translate-x-1 group-hover:text-[#0284C7]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
            </div>

            <h2 class="mt-5 text-lg font-bold text-[#212529]">Reference</h2>
            <p class="mt-1 text-sm text-[#6C757D]">Set the reference label parameters (original/valid label) as the comparison standard.</p>

            <span class="mt-4 inline-block px-2.5 py-1 rounded-full bg-[#F8F9FA] text-[#6C757D] border border-[#6C757D]/20 text-xs font-medium">Reference Label</span>

            <span class="mt-6 inline-flex items-center px-4 py-2.5 rounded-xl bg-[#0284C7] text-white text-sm font-semibold transition shadow-sm group-hover:bg-[#0369A1]">
                Open Reference
                <svg class="ml-2 w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
            </span>
        </a>

        <a href="{{ route('check') }}"
           class="group bg-[#E9ECEF] rounded-2xl border border-[#6C757D]/30 shadow-sm p-6 md:p-8 transition hover:border-[#6C757D]/50 hover:shadow-md">
            <div class="flex items-start justify-between">
                <span class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-[#F8F9FA] text-[#6C757D] border border-[#6C757D]/20">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M16 11a5 5 0 11-10 0 5 5 0 0110 0zM8 9h.01M12 9h.01M16 9h.01"/>
                    </svg>
                </span>
                <svg class="w-5 h-5 text-[#6C757D]/40 transition group-hover:translate-x-1 group-hover:text-[#0284C7]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
            </div>

            <h2 class="mt-5 text-lg font-bold text-[#212529]">Checking Label</h2>
            <p class="mt-1 text-sm text-[#6C757D]">Inspect your production label scan results and see how they match the reference label.</p>

            <span class="mt-4 inline-block px-2.5 py-1 rounded-full bg-[#F8F9FA] text-[#6C757D] border border-[#6C757D]/20 text-xs font-medium">Inspection</span>

            <span class="mt-6 inline-flex items-center px-4 py-2.5 rounded-xl bg-[#0284C7] text-white text-sm font-semibold transition shadow-sm group-hover:bg-[#0369A1]">
                Open Checking
                <svg class="ml-2 w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
            </span>
        </a>
    </div>

    <p class="mt-10 text-center text-sm text-[#6C757D]">Reference and Checking Label features will be available in the next stage.</p>
</main>
@endsection

@push('scripts')
<script src="{{ asset('js/app.js') }}"></script>
<script src="{{ asset('js/dashboard.js') }}"></script>
@endpush
