@extends('layouts.app')

@section('title', 'Sign In — Label Inspection')

@section('content')
<style>
  @keyframes shake{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-4px)}40%,80%{transform:translateX(4px)}}
  .animate-shake{animation:shake 320ms ease}
</style>
<div class="flex min-h-[calc(100vh-3.5rem)] flex-col lg:grid lg:grid-cols-2 lg:min-h-[calc(100vh-3.5rem)]">
    {{-- Panel branding — desktop only | AOI: #212529 dark + #0284C7 accent (≈10%) --}}
    <div class="hidden lg:flex relative flex-col justify-between overflow-hidden bg-[#212529] p-10 xl:p-12">
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/[0.06] via-transparent to-[#0284C7]/[0.08]"></div>
        <div class="pointer-events-none absolute -right-24 -bottom-24 size-80 rounded-full bg-[#0284C7]/10 blur-3xl"></div>
        <div class="pointer-events-none absolute -left-16 -top-16 size-64 rounded-full bg-white/[0.04] blur-2xl"></div>

        <a href="{{ route('home') }}" class="relative inline-flex items-center gap-2 self-start rounded-xl bg-white px-2.5 py-1.5 shadow-sm">
            <img src="{{ asset('images/logo1.png') }}" alt="Label Inspection logo" width="159" height="36" class="h-8 w-auto" loading="eager" decoding="async" />
        </a>

        <div class="relative">
            <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-medium tracking-wide text-white/90 backdrop-blur">
                <span class="size-2 rounded-full bg-[#0284C7] animate-pulse"></span>
                Trusted QC Platform
            </span>
            <h1 class="mt-6 text-4xl xl:text-[2.55rem] font-bold leading-[1.08] tracking-tight text-white">
                Precision in every<br>
                <span class="font-extrabold text-white">label that passes QC.</span>
            </h1>
            <p class="mt-4 max-w-md text-sm leading-relaxed text-white/60">
                Sign in to manage references, run 5-slot inspections, and store results securely &amp; encrypted.
            </p>

            <div class="mt-8 flex flex-wrap items-center gap-2 text-xs text-white/55">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-2.5 py-1">
                    <span class="size-1.5 rounded-full bg-[#0284C7]"></span> 60-min Session
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-2.5 py-1">
                    <span class="size-1.5 rounded-full bg-[#0284C7]"></span> Encrypted
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-2.5 py-1">5-slot inspection</span>
            </div>

            <div class="mt-10 grid grid-cols-3 gap-3 max-w-md">
                <div class="rounded-2xl border border-white/10 bg-white/[0.06] p-3 backdrop-blur">
                    <p class="text-lg font-bold leading-none text-white">Auto</p>
                    <p class="mt-1 text-xs leading-tight text-white/55">Consistent label detection</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/[0.06] p-3 backdrop-blur">
                    <p class="text-lg font-bold leading-none text-white">Realtime</p>
                    <p class="mt-1 text-xs leading-tight text-white/55">Results instantly</p>
                </div>
                <div class="rounded-2xl border border-[#0284C7]/30 bg-[#0284C7]/15 p-3 backdrop-blur">
                    <p class="text-lg font-bold leading-none text-white">Secure</p>
                    <p class="mt-1 text-xs leading-tight text-white/70">Bearer Token</p>
                </div>
            </div>
        </div>

        <div class="relative flex items-center justify-between text-xs text-white/35">
            <p>&copy; {{ date('Y') }} Label Inspection</p>
            <p class="hidden xl:block tracking-wide">Operation Excellence</p>
        </div>
    </div>

    {{-- Form login — minimalist card --}}
    <div class="flex flex-1 items-center justify-center bg-[#F8F9FA] px-4 py-8 sm:px-6 sm:py-10">
        <div class="w-full max-w-[420px]">
            <div class="relative overflow-hidden rounded-[20px] border border-[#E9ECEF] bg-white shadow-[0_8px_30px_rgba(33,37,41,0.06)]">
                <div class="h-[3px] w-full bg-[#0284C7]"></div>
                <div class="p-6 sm:p-8">
                    {{-- Logo centered on card (requested) --}}
                    <div class="flex justify-center mb-6">
                        <a href="{{ route('home') }}" class="inline-flex items-center justify-center rounded-xl bg-white">
                            <img src="{{ asset('images/logo1.png') }}" alt="Label Inspection logo" width="159" height="36" class="h-9 w-auto" loading="eager" decoding="async" />
                        </a>
                    </div>

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-[1.6rem] font-bold tracking-tight text-[#212529]">Sign In</h2>
                            <p class="mt-1.5 text-sm leading-relaxed text-[#6C757D]">Use your 9-digit Employee NIK to continue to the dashboard.</p>
                        </div>
                        <span class="hidden sm:inline-flex size-10 items-center justify-center rounded-xl border border-[#E9ECEF] bg-[#F8F9FA] text-[#6C757D]">
                            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V7.5a4.5 4.5 0 00-9 0v3m9 0H7.5a1.5 1.5 0 00-1.5 1.5v6a1.5 1.5 0 001.5 1.5h9a1.5 1.5 0 001.5-1.5v-6a1.5 1.5 0 00-1.5-1.5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 14.25v1.5"/></svg>
                        </span>
                    </div>

                    <div id="error-box" class="hidden mt-5 items-start gap-2.5 rounded-xl border border-[#0284C7]/20 bg-[#0284C7]/[0.08] px-3.5 py-3 text-sm leading-relaxed text-[#0284C7]" role="alert" aria-live="polite">
                        <svg class="mt-0.5 size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                        <span id="error-text"></span>
                    </div>

                    <form id="login-form" class="mt-6 flex flex-col gap-5" novalidate>
                        <div class="flex flex-col gap-1.5" data-field="nik">
                            <label for="nik" class="text-sm font-medium text-[#212529]">Employee NIK <span class="font-normal text-[#0284C7]">*</span></label>
                            <div class="relative group">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-[#6C757D]/60 transition group-focus-within:text-[#0284C7]">
                                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v9a2 2 0 002 2h12a2 2 0 002-2V8a2 2 0 00-2-2h-4"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 13a3 3 0 100-6 3 3 0 000 6z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 14l2 2"/></svg>
                                </span>
                                <input type="text" id="nik" name="nik" inputmode="numeric" pattern="[0-9]*" maxlength="9" autocomplete="off" autocapitalize="none" spellcheck="false" required aria-describedby="nik-hint nik-counter" placeholder="000000000"
                                       class="w-full rounded-xl border border-[#E9ECEF] bg-white py-2.5 pl-10 pr-4 text-sm tracking-widest text-[#212529] placeholder:text-[#6C757D]/45 placeholder:tracking-normal outline-none transition focus:border-[#0284C7] focus:ring-2 focus:ring-[#0284C7]/15">
                            </div>
                            <div class="flex items-center justify-between">
                                <p id="nik-hint" class="text-xs text-[#6C757D]">9-digit Employee NIK. Numbers only.</p>
                                <span id="nik-counter" class="text-xs tabular-nums text-[#6C757D]/70">0/9</span>
                            </div>
                        </div>

                        <button type="submit" id="submit-btn"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#0284C7] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#0369A1] active:bg-[#075985] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0284C7]/30 focus-visible:ring-offset-2 focus-visible:ring-offset-white disabled:opacity-60 disabled:cursor-not-allowed">
                            <svg id="btn-spinner" class="hidden size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                            <span id="btn-text">Sign In</span>
                        </button>
                    </form>

                    <div class="mt-6 flex items-center gap-3">
                        <div class="h-px flex-1 bg-[#E9ECEF]"></div>
                        <span class="text-xs text-[#6C757D]">or</span>
                        <div class="h-px flex-1 bg-[#E9ECEF]"></div>
                    </div>

                    <a href="{{ route('home') }}" class="mt-6 flex w-full items-center justify-center gap-1.5 rounded-xl border border-[#E9ECEF] bg-[#F8F9FA] px-4 py-2.5 text-sm font-medium text-[#212529] transition hover:border-[#6C757D]/20 hover:bg-white">
                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
                        Back to home
                    </a>

                    <p class="mt-4 text-center text-xs leading-relaxed text-[#6C757D]">
                        Need access? <span class="font-medium text-[#212529]">Contact admin</span> to create an account.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/login.js') }}"></script>
@endpush
