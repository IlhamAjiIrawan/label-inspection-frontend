@extends('layouts.app')

@section('title', 'Reference — Label Inspection')

@section('content')
<nav class="sticky top-0 z-50 bg-[#F8F9FA] border-b border-[#6C757D]/20">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                <img src="{{ asset('images/logo1.png') }}" alt="Label Inspection logo"
                     width="159" height="36" class="h-9 w-auto" />
            </a>

            <div class="hidden md:flex items-center gap-8 text-sm font-medium text-[#6C757D]">
                <a href="{{ route('dashboard') }}" class="hover:text-sky-600 transition">Dashboard</a>
                <a href="{{ route('reference') }}" class="text-sky-600 font-semibold">Reference</a>
                <a href="{{ route('check') }}" class="hover:text-sky-600 transition">Check Label</a>
            </div>

            <button id="logout-btn" class="px-4 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold hover:bg-[#E9ECEF] transition disabled:opacity-60 disabled:cursor-not-allowed">
                Logout
            </button>
        </div>
    </div>
</nav>

<main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10 md:py-14">
    <div class="max-w-5xl mx-auto">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold text-[#212529]">Reference</h1>
                <p class="mt-1 text-sm text-[#6C757D]">Manage the reference labels (original/valid labels) as the comparison standard.</p>
            </div>
            <span id="step-indicator" class="self-start md:self-auto px-3 py-1 rounded-full bg-[#E9ECEF] border border-[#6C757D]/30 text-xs font-medium text-[#6C757D]">
                Start
            </span>
        </div>

        {{-- ===================== MENU: Choose vs Create New (Layout Only) ===================== --}}
        <div id="reference-menu-step" class="mt-8">
            <h2 class="text-lg font-bold text-[#212529]">What do you want to do?</h2>
            <p class="mt-1 text-sm text-[#6C757D]">Choose an existing reference or create a new one.</p>

            <div class="mt-6 grid gap-6 md:grid-cols-2">
                <button id="btn-choose-reference" type="button" aria-label="Choose an existing label reference"
                        class="group flex h-full w-full flex-col bg-[#E9ECEF] rounded-2xl border border-[#6C757D]/30 shadow-sm p-6 md:p-8 text-left transition hover:border-[#6C757D]/50 hover:shadow-md cursor-pointer focus:outline-none focus:ring-2 focus:ring-[#0284C7]">
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

                    <h3 class="mt-5 text-lg font-bold text-[#212529]">Choose Label Reference</h3>
                    <p class="mt-1 text-sm text-[#6C757D]">Use a saved reference template without scanning again.</p>

                    <span class="mt-4 inline-block self-start px-2.5 py-1 rounded-full bg-[#F8F9FA] text-[#6C757D] border border-[#6C757D]/20 text-xs font-medium">Saved Templates</span>

                    <span class="mt-6 inline-flex items-center px-4 py-2.5 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold transition group-hover:bg-[#F8F9FA]">
                        Browse References
                        <svg class="ml-2 w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </span>
                </button>

                <button id="btn-create-new-reference" type="button" aria-label="Create a new label reference"
                        class="group flex h-full w-full flex-col bg-[#E9ECEF] rounded-2xl border border-[#6C757D]/30 shadow-sm p-6 md:p-8 text-left transition hover:border-[#0284C7]/50 hover:shadow-md cursor-pointer focus:outline-none focus:ring-2 focus:ring-[#0284C7]">
                    <div class="flex items-start justify-between">
                        <span class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-[#F8F9FA] text-[#6C757D] border border-[#6C757D]/20">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9zm7 3a3 3 0 106 0 3 3 0 00-6 0zm4.5-2.5h.01"/>
                            </svg>
                        </span>
                        <svg class="w-5 h-5 text-[#6C757D]/40 transition group-hover:translate-x-1 group-hover:text-[#0284C7]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </div>

                    <h3 class="mt-5 text-lg font-bold text-[#212529]">Create New Label Reference</h3>
                    <p class="mt-1 text-sm text-[#6C757D]">Scan a new label: select size template, live scan, then save as reference.</p>

                    <span class="mt-4 inline-block self-start px-2.5 py-1 rounded-full bg-[#F8F9FA] text-[#6C757D] border border-[#6C757D]/20 text-xs font-medium">New Scan</span>

                    <span class="mt-6 inline-flex items-center px-4 py-2.5 rounded-xl bg-[#0284C7] text-white text-sm font-semibold transition shadow-sm group-hover:bg-[#0369A1]">
                        Create New
                        <svg class="ml-2 w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </span>
                </button>
            </div>

        </div>

        {{-- ===================== STEP: Choose Label Reference (Jalur B — tanpa scan ulang) ===================== --}}
        <div id="choose-panel" class="hidden mt-8">
            <button id="back-to-menu-from-choose" type="button"
                    class="px-4 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold hover:bg-[#F8F9FA] transition">
                &larr; Back to Menu
            </button>
            <h2 class="mt-4 text-lg font-bold text-[#212529]">Choose Label Reference</h2>
            <p class="mt-1 text-sm text-[#6C757D]">Pakai reference tersimpan tanpa scan ulang — hemat waktu &amp; OCR.</p>

            <p class="mt-4 text-xs text-[#6C757D]">Daftar label reference tersimpan di Library — pilih untuk dipakai tanpa scan ulang.</p>

            <div class="mt-4">
                <input id="choose-search" type="search" autocomplete="off"
                       placeholder="Cari nama / template / filename..."
                       class="w-full px-4 py-2.5 rounded-xl bg-[#F8F9FA] border border-[#6C757D]/30 text-sm text-[#212529] placeholder-[#6C757D]/60 focus:outline-none focus:ring-2 focus:ring-sky-300" />
            </div>

            <div id="choose-loading" class="hidden mt-6 flex items-center gap-2 text-sm text-[#6C757D]">
                <svg class="w-4 h-4 animate-spin text-sky-600" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Memuat daftar reference...
            </div>
            <p id="choose-error" class="hidden mt-6 p-4 rounded-xl bg-[#E04438]/10 border border-[#E04438]/30 text-sm text-[#E04438]"></p>

            <div id="choose-grid" class="mt-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3"></div>

            <p id="choose-empty" class="hidden mt-4 text-sm text-[#6C757D] bg-[#E9ECEF] border border-[#6C757D]/20 rounded-xl px-4 py-3">
                Belum ada reference. Buat baru lewat <span class="font-semibold text-[#212529]">Create New Label Reference</span>.
            </p>

            <div class="mt-4 flex items-center justify-between gap-3">
                <button id="choose-prev" type="button"
                        class="px-4 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold hover:bg-[#F8F9FA] transition disabled:opacity-50 disabled:cursor-not-allowed">
                    &larr; Prev
                </button>
                <span id="choose-page-info" class="text-xs font-medium text-[#6C757D]">-</span>
                <button id="choose-next" type="button"
                        class="px-4 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold hover:bg-[#F8F9FA] transition disabled:opacity-50 disabled:cursor-not-allowed">
                    Next &rarr;
                </button>
            </div>

            {{-- Modal konfirmasi hapus card Choose (popup modern-minimalist, tanpa halaman baru) --}}
            <div id="delete-modal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4">
                <div id="delete-modal-backdrop" class="absolute inset-0 bg-[#212529]/50"></div>
                <div role="dialog" aria-modal="true" aria-labelledby="delete-modal-title"
                     class="relative w-full max-w-sm bg-[#F8F9FA] rounded-2xl border border-[#6C757D]/20 shadow-xl p-5">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-[#E04438]/10 border border-[#E04438]/30 shrink-0">
                            <svg class="w-5 h-5 text-[#E04438]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <h3 id="delete-modal-title" class="text-base font-bold text-[#212529]">Hapus capture ini?</h3>
                            <p class="mt-0.5 text-xs text-[#6C757D]">Gambar terhapus dari daftar — riwayat Check yang pernah memakainya tetap tersimpan (tanpa acuan). Tidak bisa dibatalkan.</p>
                        </div>
                    </div>
                    <ul id="delete-modal-files" class="mt-4 flex flex-col gap-1.5 max-h-40 overflow-auto"></ul>
                    <p id="delete-modal-error" class="hidden mt-3 text-xs font-medium text-[#E04438]"></p>
                    <div class="mt-5 flex items-center gap-2">
                        <button id="delete-modal-cancel" type="button"
                                class="flex-1 px-4 py-2.5 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold hover:bg-[#E9ECEF] transition disabled:opacity-50 disabled:cursor-not-allowed">
                            Batal
                        </button>
                        <button id="delete-modal-confirm" type="button"
                                class="flex-1 px-4 py-2.5 rounded-xl bg-[#E04438] text-white text-sm font-semibold transition shadow-sm hover:brightness-95 disabled:opacity-50 disabled:cursor-not-allowed">
                            Ya, Hapus
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ===================== STEP 1: Template Size Selection ===================== --}}
        <div id="template-step" class="hidden mt-8">
            <button id="back-to-menu-from-template" type="button"
                    class="px-4 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold hover:bg-[#F8F9FA] transition">
                &larr; Back to Menu
            </button>
            <h2 class="mt-4 text-lg font-bold text-[#212529]">Select Label Size Template</h2>
            <p class="mt-1 text-sm text-[#6C757D]">Choose the label size as a reference before scanning.</p>

            <div id="template-grid" class="mt-6 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4"></div>

            <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-3">
                <p class="text-sm text-[#6C757D]">Select one template to continue.</p>
                <button id="continue-btn" disabled
                        class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-sky-600 text-white text-sm font-semibold shadow-sm transition hover:bg-sky-700 disabled:opacity-50 disabled:cursor-not-allowed">
                    Continue to Live Scan
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                    </svg>
                </button>
            </div>
        </div>

        {{-- ===================== STEP 2: Live Label Scan ===================== --}}
        <div id="scan-step" class="hidden mt-8 space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3 bg-[#E9ECEF] border border-[#6C757D]/30 rounded-2xl px-4 py-3 text-sm">
                <p class="flex items-center gap-2 text-[#212529]">
                    <svg class="w-4 h-4 text-sky-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span id="template-info" class="font-medium">-</span>
                </p>
                <button id="change-template-btn"
                        class="px-4 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold hover:bg-[#F8F9FA] transition">
                    Change template
                </button>
            </div>

            <div id="dual-stepper" class="flex flex-wrap items-center gap-2 text-xs font-semibold">
                <span id="dual-step-1" class="px-3 py-1.5 rounded-full border bg-[#0284C7] text-white border-[#0284C7]">1 &middot; Front (Depan)</span>
                <span aria-hidden="true" class="text-[#6C757D]/50">&mdash;</span>
                <span id="dual-step-2" class="px-3 py-1.5 rounded-full border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30">2 &middot; Back (Belakang)</span>
                <span aria-hidden="true" class="text-[#6C757D]/50">&mdash;</span>
                <span id="dual-step-save" class="px-3 py-1.5 rounded-full border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30">Simpan 2 Label</span>
            </div>

            <p id="dual-stage-info" role="status" aria-live="polite" class="bg-[#E9ECEF] border border-[#6C757D]/30 rounded-2xl px-4 py-3 text-sm text-[#212529]">Tahap 1/2 &mdash; Posisikan <span class="font-bold">SISI DEPAN (Front)</span> di dalam bingkai, lalu tekan <span class="font-bold">Capture Label</span>.</p>

            <div class="bg-[#E9ECEF] border border-[#6C757D]/30 text-[#212529] rounded-2xl p-4 text-sm leading-relaxed">
                <p class="font-semibold">How to scan (2 tahap)</p>
                <p class="mt-1">1. Tap <strong>Start Scanning Label</strong> &rarr; pindai <strong>SISI DEPAN (Front)</strong> &rarr; <strong>Capture Label</strong> &middot; 2. Tekan <strong>Lanjut ke Capture 2</strong> &rarr; pindai <strong>SISI BELAKANG (Back)</strong> &rarr; <strong>Capture Label 2</strong> &middot; 3. Periksa 2 hasil &rarr; <strong>Simpan 2 Label</strong>.</p>
            </div>

            <div class="bg-[#E9ECEF] rounded-2xl border border-[#6C757D]/30 shadow-sm p-6 md:p-8">
                <div id="scan-header" class="flex flex-col sm:flex-row sm:items-center gap-4">
                    <span class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-[#F8F9FA] text-[#6C757D] border border-[#6C757D]/20">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9zm7 3a3 3 0 106 0 3 3 0 00-6 0zm4.5-2.5h.01"/>
                        </svg>
                    </span>
                    <div class="flex-1">
                        <h2 class="text-lg font-bold text-[#212529]">Live Label Scan</h2>
                        <p class="mt-1 text-sm text-[#6C757D]">Open your device camera to preview the label before scanning.</p>
                    </div>
                    <button id="start-scan-btn"
                            class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-sky-600 text-white text-sm font-semibold transition shadow-sm hover:bg-sky-700 disabled:opacity-60 disabled:cursor-not-allowed">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                        </svg>
                        Start Scanning Label
                    </button>
                </div>

                <div id="camera-status" class="hidden"></div>

                <div id="camera-card" class="hidden mt-6">
                    {{-- Camera selector & Live resolution toolbar --}}
                    <div class="mb-3 p-3 bg-[#F8F9FA] rounded-xl border border-[#6C757D]/20 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-2 flex-1 min-w-[240px]">
                            <label for="camera-select" class="text-xs font-semibold text-[#212529] whitespace-nowrap flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-sky-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                </svg>
                                Pilih Kamera:
                            </label>
                            <select id="camera-select" class="bg-white border border-[#6C757D]/30 text-[#212529] text-xs font-medium rounded-lg focus:ring-sky-500 focus:border-sky-500 block w-full p-2">
                                <option value="">Mencari kamera...</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-2">
                            <span id="camera-res-badge" class="hidden px-2.5 py-1 text-xs font-semibold bg-emerald-100 text-emerald-800 rounded-full border border-emerald-300">
                                -
                            </span>
                        </div>
                    </div>

                    <div class="bg-[#212529] rounded-2xl overflow-hidden relative">
                        <video id="camera-preview" autoplay playsinline muted
                               class="w-full h-auto max-h-[70vh] object-contain bg-[#212529]"></video>
                        <div class="absolute top-3 left-3 flex items-center gap-2 bg-[#212529]/70 rounded-full px-3 py-1.5">
                            <span class="w-2 h-2 rounded-full bg-sky-500 animate-pulse"></span>
                            <span class="text-white text-xs font-semibold tracking-wide">LIVE</span>
                        </div>

                        <canvas id="guide-overlay" aria-hidden="true"
                                class="pointer-events-none absolute inset-0 w-full h-full"></canvas>

                        <span id="guide-size-badge"
                              class="absolute top-3 right-3 bg-[#212529]/70 rounded-full px-3 py-1.5 text-white text-xs font-semibold tracking-wide">
                        </span>

                        <span id="dual-side-badge"
                              class="absolute top-12 right-3 bg-[#0284C7] rounded-full px-3 py-1.5 text-white text-xs font-bold tracking-wide">
                            FRONT
                        </span>

                        <div id="capture-flash"
                             class="pointer-events-none absolute inset-0 bg-white opacity-0 transition-opacity duration-150"></div>
                    </div>

                    <div class="mt-4 flex items-center gap-3">
                        <label for="guide-size-range" class="text-sm font-medium text-[#212529] whitespace-nowrap">Frame size</label>
                        <input id="guide-size-range" type="range" min="20" max="100" step="1" value="100"
                               class="flex-1 sm:flex-none sm:w-56 accent-sky-600 cursor-pointer" />
                        <span id="guide-size-value" class="text-xs font-semibold text-[#6C757D] w-10">100%</span>
                    </div>
                    <p class="mt-1 text-xs text-[#6C757D]">
                        Adjust the horizontal frame and align it with a real ruler or object so the cm values match the physical label (frame fits both sides side by side). Set BEFORE Capture — Retake to adjust again.
                    </p>

                    <div class="mt-4 flex items-center justify-between flex-wrap gap-3">
                        <p id="camera-label" class="text-sm text-[#6C757D]">-</p>
                        <div class="flex items-center gap-3 flex-wrap">
                            <span id="capture-feedback" class="text-xs font-medium text-sky-600"></span>
                            <button id="capture-btn" data-side="front"
                                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-sky-600 text-white text-sm font-semibold transition shadow-sm hover:bg-sky-700 disabled:opacity-60 disabled:cursor-not-allowed">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9zm14 3a4 4 0 11-8 0 4 4 0 018 0z"/>
                                </svg>
                                <span id="capture-btn-text">Capture Label</span>
                            </button>
                            <button id="stop-scan-btn"
                                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold transition hover:bg-[#F8F9FA] disabled:opacity-60 disabled:cursor-not-allowed">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                    <rect x="6" y="6" width="12" height="12" rx="2"/>
                                </svg>
                                Stop
                            </button>
                        </div>
                    </div>
                </div>

                <div id="dual-front-mini" class="hidden mt-6 bg-[#F8F9FA] border border-[#6C757D]/20 rounded-2xl p-4">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        <h3 class="text-base font-bold text-[#212529]">Sisi Depan (Front) tersimpan sementara</h3>
                        <span class="ml-auto px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">FRONT</span>
                    </div>
                    <div id="dual-front-single" class="mt-4 grid grid-cols-1 sm:grid-cols-[200px_1fr] gap-4">
                        <div class="bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                            <img id="dual-front-thumb" alt="Hasil capture sisi depan" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <div id="dual-front-adjust" class="flex flex-col gap-2">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <button id="dual-rotate-front-left" type="button"
                                            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-xs font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M4 9a8 8 0 0114-3"/>
                                        </svg>
                                        Rotate Kiri
                                    </button>
                                    <button id="dual-rotate-front-right" type="button"
                                            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-xs font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                        Rotate Kanan
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 4v5h-5M20 9a8 8 0 00-14-3"/>
                                        </svg>
                                    </button>
                                    <span id="dual-rotate-front-deg" class="text-xs font-semibold text-[#6C757D] w-10">0°</span>
                                    <button id="dual-process-front" type="button"
                                            class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#0284C7] text-white text-xs font-semibold transition shadow-sm hover:bg-[#0369A1] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                        Simpan
                                    </button>
                                </div>
                                <p class="text-xs text-[#6C757D]">Atur rotasi dulu, baru proses OCR.</p>
                                <pre id="dual-front-ocr" class="bg-white border border-[#6C757D]/20 rounded-lg p-3 text-sm text-[#212529] whitespace-pre-wrap break-words min-h-[3rem] max-h-32 overflow-auto">Menunggu OCR...</pre>
                            </div>
                        </div>
                    </div>
                    <div id="dual-front-split" class="hidden mt-4">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs font-semibold text-[#212529]">Review result capture</p>
                            <div class="bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                                <img id="dual-front-split-review" alt="Review result capture sisi depan" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                            </div>
                        </div>
                        <div class="flex flex-col gap-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">Label 1</span>
                                <span id="dual-front-split-badge-1" class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30">idle</span>
                            </div>
                            <div class="relative bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                                <img id="dual-front-split-img-1" alt="Hasil crop Label 1 sisi depan" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                                <span id="dual-front-check-1" class="hidden absolute top-2 right-2 inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-500 border border-white">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </span>
                            </div>
                            <p id="dual-front-split-meta-1" class="text-xs text-[#6C757D] break-all">-</p>
                            <pre id="dual-front-split-ocr-1" class="hidden">Menunggu split...</pre>
                        </div>
                        <div class="flex flex-col gap-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">Label 2</span>
                                <span id="dual-front-split-badge-2" class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30">idle</span>
                            </div>
                            <div class="relative bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                                <img id="dual-front-split-img-2" alt="Hasil crop Label 2 sisi depan" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                                <span id="dual-front-check-2" class="hidden absolute top-2 right-2 inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-500 border border-white">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </span>
                            </div>
                            <p id="dual-front-split-meta-2" class="text-xs text-[#6C757D] break-all">-</p>
                            <pre id="dual-front-split-ocr-2" class="hidden">Menunggu split...</pre>
                        </div>
                        </div>
                        <div class="mt-3 bg-white border border-[#6C757D]/20 rounded-xl overflow-hidden">
                            <div class="flex items-center gap-2 flex-wrap p-2 border-b border-[#6C757D]/10 bg-[#F8F9FA]">
                                <span class="mr-auto text-xs font-semibold text-[#212529]">Hasil ekstraksi teks</span>
                                <button id="dual-front-tab-1" type="button" class="px-3.5 py-1.5 rounded-full text-xs font-semibold border bg-[#0284C7] text-white border-[#0284C7] transition focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">Label 1</button>
                                <button id="dual-front-tab-2" type="button" class="px-3.5 py-1.5 rounded-full text-xs font-semibold border border-[#6C757D]/40 text-[#212529] transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">Label 2</button>
                                <button id="dual-front-ocr-toggle" type="button" aria-expanded="false" aria-controls="dual-front-ocr-body" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border border-[#6C757D]/40 text-[#212529] transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7]">
                                    <svg class="w-3.5 h-3.5 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                    <span data-ocr-toggle-label>Lihat teks</span>
                                </button>
                            </div>
                            <div id="dual-front-ocr-body" class="hidden">
                                <pre id="dual-front-ocr-view" class="p-3 text-sm text-[#212529] whitespace-pre-wrap break-words min-h-[6rem] max-h-40 overflow-auto">Pilih Label 1 / Label 2 untuk melihat hasil ekstraksi.</pre>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-3 flex-wrap">
                        <button id="dual-retake-front" type="button"
                                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                            Retake Front
                        </button>
                        <button id="dual-next-to-back" type="button" disabled
                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-[#0284C7] text-white text-sm font-semibold transition shadow-sm hover:bg-[#0369A1] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                            Lanjut ke Capture 2 (Back)
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                            </svg>
                        </button>
                        <span id="dual-mini-feedback" class="text-xs font-medium text-[#6C757D]"></span>
                    </div>
                </div>

                <div id="dual-back-mini" class="hidden mt-6 bg-[#F8F9FA] border border-[#6C757D]/20 rounded-2xl p-4">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        <h3 class="text-base font-bold text-[#212529]">Sisi Belakang (Back) tersimpan sementara</h3>
                        <span class="ml-auto px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">BACK</span>
                    </div>
                    <div id="dual-back-single" class="mt-4 grid grid-cols-1 sm:grid-cols-[200px_1fr] gap-4">
                        <div class="bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                            <img id="dual-back-preview" alt="Hasil capture sisi belakang" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <div id="dual-back-adjust" class="flex flex-col gap-2">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <button id="dual-rotate-back-left" type="button"
                                            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-xs font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M4 9a8 8 0 0114-3"/>
                                        </svg>
                                        Rotate Kiri
                                    </button>
                                    <button id="dual-rotate-back-right" type="button"
                                            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-xs font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                        Rotate Kanan
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 4v5h-5M20 9a8 8 0 00-14-3"/>
                                        </svg>
                                    </button>
                                    <span id="dual-rotate-back-deg" class="text-xs font-semibold text-[#6C757D] w-10">0°</span>
                                    <button id="dual-process-back" type="button"
                                            class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#0284C7] text-white text-xs font-semibold transition shadow-sm hover:bg-[#0369A1] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                        Simpan
                                    </button>
                                </div>
                                <p class="text-xs text-[#6C757D]">Atur rotasi dulu, baru proses OCR.</p>
                                <pre id="dual-back-ocr" class="bg-white border border-[#6C757D]/20 rounded-lg p-3 text-sm text-[#212529] whitespace-pre-wrap break-words min-h-[3rem] max-h-32 overflow-auto">Menunggu OCR...</pre>
                            </div>
                        </div>
                    </div>
                    <div id="dual-back-split" class="hidden mt-4">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs font-semibold text-[#212529]">Review result capture</p>
                            <div class="bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                                <img id="dual-back-split-review" alt="Review result capture sisi belakang" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                            </div>
                        </div>
                        <div class="flex flex-col gap-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">Label 1</span>
                                <span id="dual-back-split-badge-1" class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30">idle</span>
                            </div>
                            <div class="relative bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                                <img id="dual-back-split-img-1" alt="Hasil crop Label 1 sisi belakang" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                                <span id="dual-back-check-1" class="hidden absolute top-2 right-2 inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-500 border border-white">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </span>
                            </div>
                            <p id="dual-back-split-meta-1" class="text-xs text-[#6C757D] break-all">-</p>
                            <pre id="dual-back-split-ocr-1" class="hidden">Menunggu split...</pre>
                        </div>
                        <div class="flex flex-col gap-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">Label 2</span>
                                <span id="dual-back-split-badge-2" class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30">idle</span>
                            </div>
                            <div class="relative bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                                <img id="dual-back-split-img-2" alt="Hasil crop Label 2 sisi belakang" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                                <span id="dual-back-check-2" class="hidden absolute top-2 right-2 inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-500 border border-white">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </span>
                            </div>
                            <p id="dual-back-split-meta-2" class="text-xs text-[#6C757D] break-all">-</p>
                            <pre id="dual-back-split-ocr-2" class="hidden">Menunggu split...</pre>
                        </div>
                        </div>
                        <div class="mt-3 bg-white border border-[#6C757D]/20 rounded-xl overflow-hidden">
                            <div class="flex items-center gap-2 flex-wrap p-2 border-b border-[#6C757D]/10 bg-[#F8F9FA]">
                                <span class="mr-auto text-xs font-semibold text-[#212529]">Hasil ekstraksi teks</span>
                                <button id="dual-back-tab-1" type="button" class="px-3.5 py-1.5 rounded-full text-xs font-semibold border bg-[#0284C7] text-white border-[#0284C7] transition focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">Label 1</button>
                                <button id="dual-back-tab-2" type="button" class="px-3.5 py-1.5 rounded-full text-xs font-semibold border border-[#6C757D]/40 text-[#212529] transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">Label 2</button>
                                <button id="dual-back-ocr-toggle" type="button" aria-expanded="false" aria-controls="dual-back-ocr-body" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border border-[#6C757D]/40 text-[#212529] transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7]">
                                    <svg class="w-3.5 h-3.5 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                    <span data-ocr-toggle-label>Lihat teks</span>
                                </button>
                            </div>
                            <div id="dual-back-ocr-body" class="hidden">
                                <pre id="dual-back-ocr-view" class="p-3 text-sm text-[#212529] whitespace-pre-wrap break-words min-h-[6rem] max-h-40 overflow-auto">Pilih Label 1 / Label 2 untuk melihat hasil ekstraksi.</pre>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-3 flex-wrap">
                        <button id="dual-retake-back" type="button"
                                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                            Retake Back
                        </button>
                        <button id="dual-next-to-result" type="button" disabled aria-label="Lanjut ke hasil 2 sisi"
                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-[#0284C7] text-white text-sm font-semibold transition shadow-sm hover:bg-[#0369A1] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                            Next
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                            </svg>
                        </button>
                        <span id="dual-back-feedback" class="text-xs font-medium text-[#6C757D]"></span>
                    </div>
                </div>

                <div id="dual-back-loading" role="status" aria-live="polite" class="hidden mt-6 bg-[#F8F9FA] border border-[#6C757D]/20 rounded-2xl p-4">
                    <div class="flex items-center gap-3 flex-wrap">
                        <svg class="w-5 h-5 animate-spin text-[#0284C7]" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <h3 class="text-base font-bold text-[#212529]">Memproses Sisi Belakang (Back)... <span id="dual-back-loading-seconds" class="text-sm font-semibold text-[#6C757D]">0s</span></h3>
                        <span class="ml-auto flex items-center gap-2">
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#F8F9FA] text-[#212529] border-emerald-300">FRONT ✓</span>
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">BACK …</span>
                        </span>
                    </div>
                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-[200px_1fr] gap-4">
                        <div class="bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                            <img id="dual-back-thumb" alt="Hasil capture sisi belakang sedang diproses" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                        </div>
                        <div class="flex flex-col gap-2 justify-center">
                            <p class="text-sm text-[#212529]">Label ke-2 <span class="font-bold">Sisi Belakang (Back)</span> sedang di-SCAN + OCR. Mohon tunggu — jangan tutup halaman.</p>
                            <p id="dual-back-loading-file" class="text-xs text-[#6C757D] break-all">-</p>
                            <p class="text-xs text-[#6C757D]">Sisi Depan sudah tersimpan sementara ✓. Hasil Back akan tampil di kartu Back — lalu tekan Next untuk lihat hasil 2 sisi.</p>
                        </div>
                    </div>
                </div>

                <div id="dual-result-card" class="hidden mt-6">
                    <div class="flex items-center gap-2 flex-wrap">
                        <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        <h3 class="text-base font-bold text-[#212529]">Capture Result (2 sisi)</h3>
                        <span class="ml-auto text-xs font-medium text-[#6C757D]">Front + Back — 1 set Reference</span>
                    </div>

                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-[#F8F9FA] rounded-2xl border border-[#6C757D]/20 p-3 flex flex-col gap-2">
                            <div class="flex items-center gap-2">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">FRONT</span>
                                <span id="dual-front-badge" class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30">idle</span>
                            </div>
                            <div class="bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center">
                                <img id="dual-result-front-img" alt="Hasil capture sisi depan" class="hidden w-full max-h-[32vh] sm:max-h-[40vh] object-contain" />
                            </div>
                            <div class="grid grid-cols-1 min-[420px]:grid-cols-2 gap-2">
                                <div class="flex flex-col gap-1.5">
                                    <div class="bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[9rem] p-2">
                                        <img id="dual-res-front-img-1" alt="Crop Label 1 sisi depan" class="h-32 sm:h-36 w-auto max-w-full mx-auto object-contain" />
                                    </div>
                                    <pre id="dual-res-front-text-1" class="bg-white border border-[#6C757D]/20 rounded-lg p-3 text-sm text-[#212529] whitespace-pre-wrap break-words min-h-[5rem] max-h-40 overflow-auto">-</pre>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <div class="bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[9rem] p-2">
                                        <img id="dual-res-front-img-2" alt="Crop Label 2 sisi depan" class="h-32 sm:h-36 w-auto max-w-full mx-auto object-contain" />
                                    </div>
                                    <pre id="dual-res-front-text-2" class="bg-white border border-[#6C757D]/20 rounded-lg p-3 text-sm text-[#212529] whitespace-pre-wrap break-words min-h-[5rem] max-h-40 overflow-auto">-</pre>
                                </div>
                            </div>
                            <pre id="dual-result-front-text" class="hidden bg-white border border-[#6C757D]/20 rounded-lg p-3 text-sm text-[#212529] whitespace-pre-wrap break-words max-h-32 overflow-auto">-</pre>
                            <div class="flex items-center gap-2 flex-wrap">
                                <button id="dual-retake-front-2" type="button"
                                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                    Retake Front
                                </button>
                            </div>
                        </div>
                        <div class="bg-[#F8F9FA] rounded-2xl border border-[#6C757D]/20 p-3 flex flex-col gap-2">
                            <div class="flex items-center gap-2">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">BACK</span>
                                <span id="dual-back-badge" class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30">idle</span>
                            </div>
                            <div class="bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center">
                                <img id="dual-result-back-img" alt="Hasil capture sisi belakang" class="hidden w-full max-h-[32vh] sm:max-h-[40vh] object-contain" />
                            </div>
                            <div class="grid grid-cols-1 min-[420px]:grid-cols-2 gap-2">
                                <div class="flex flex-col gap-1.5">
                                    <div class="bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[9rem] p-2">
                                        <img id="dual-res-back-img-1" alt="Crop Label 1 sisi belakang" class="h-32 sm:h-36 w-auto max-w-full mx-auto object-contain" />
                                    </div>
                                    <pre id="dual-res-back-text-1" class="bg-white border border-[#6C757D]/20 rounded-lg p-3 text-sm text-[#212529] whitespace-pre-wrap break-words min-h-[5rem] max-h-40 overflow-auto">-</pre>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <div class="bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[9rem] p-2">
                                        <img id="dual-res-back-img-2" alt="Crop Label 2 sisi belakang" class="h-32 sm:h-36 w-auto max-w-full mx-auto object-contain" />
                                    </div>
                                    <pre id="dual-res-back-text-2" class="bg-white border border-[#6C757D]/20 rounded-lg p-3 text-sm text-[#212529] whitespace-pre-wrap break-words min-h-[5rem] max-h-40 overflow-auto">-</pre>
                                </div>
                            </div>
                            <pre id="dual-result-back-text" class="hidden bg-white border border-[#6C757D]/20 rounded-lg p-3 text-sm text-[#212529] whitespace-pre-wrap break-words max-h-32 overflow-auto">-</pre>
                            <div class="flex items-center gap-2 flex-wrap">
                                <button id="dual-retake-back-2" type="button"
                                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                    Retake Back
                                </button>
                            </div>
                        </div>
                    </div>

                    <p id="dual-result-meta" class="mt-3 text-xs text-[#6C757D] break-all">-</p>

                    <div class="mt-3 flex items-center gap-3 flex-wrap flex-col sm:flex-row">
                        <button id="dual-save-btn" type="button" disabled
                                class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-[#0284C7] text-white text-sm font-semibold transition shadow-sm hover:bg-[#0369A1] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/>
                            </svg>
                            <span id="dual-save-btn-text">Simpan 4 Label</span>
                        </button>
                        <span id="dual-save-feedback" class="text-xs font-medium text-[#6C757D]"></span>
                    </div>
                    <p id="dual-save-help" class="mt-3 text-xs text-[#6C757D]">Periksa 4 hasil (Front Label 1 + 2, Back Label 1 + 2) + teks tiap belahan &mdash; lalu tekan <span class="font-semibold text-[#212529]">Simpan 4 Label</span> sebagai 1 set (nama file kembar beda -front-1/-front-2/-back-1/-back-2), lalu lanjut ke Check Label. Retake per sisi tidak menghapus sisi lainnya.</p>
                </div>

                <div id="result-card" class="hidden mt-6">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        <h3 class="text-base font-bold text-[#212529]">Capture Result</h3>
                    </div>

                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-[#F8F9FA] rounded-2xl overflow-hidden flex flex-col items-center justify-center p-3 gap-3">
                            <img id="result-image" alt="Captured label"
                                 class="max-h-[50vh] max-w-full w-auto" />
                            <div class="flex items-center gap-2 flex-wrap justify-center">
                                <button id="result-rotate-left" type="button"
                                        class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-xs font-semibold transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                    Rotate Kiri
                                </button>
                                <button id="result-rotate-right" type="button"
                                        class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-xs font-semibold transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                    Rotate Kanan
                                </button>
                                <span id="result-rotate-deg" class="text-xs font-semibold text-[#6C757D] w-10">0°</span>
                            </div>
                            <p class="text-xs text-[#6C757D]">Atur rotasi dulu, baru proses OCR.</p>
                        </div>

                        <div class="flex flex-col">
                            <dl class="text-sm">
                                <div class="flex items-center justify-between gap-4 py-2 border-b border-[#6C757D]/10">
                                    <dt class="text-[#6C757D]">Template</dt>
                                    <dd id="result-info-template" class="font-semibold text-[#212529] text-right">-</dd>
                                </div>
                                <div class="flex items-center justify-between gap-4 py-2 border-b border-[#6C757D]/10">
                                    <dt class="text-[#6C757D]">Size</dt>
                                    <dd id="result-info-size" class="font-semibold text-[#212529] text-right">-</dd>
                                </div>
                                <div class="flex items-center justify-between gap-4 py-2 border-b border-[#6C757D]/10">
                                    <dt class="text-[#6C757D]">Captured at</dt>
                                    <dd id="result-info-time" class="font-semibold text-[#212529] text-right">-</dd>
                                </div>
                                <div class="flex items-center justify-between gap-4 py-2 border-b border-[#6C757D]/10">
                                    <dt class="text-[#6C757D]">Resolution</dt>
                                    <dd id="result-info-resolution" class="font-semibold text-[#212529] text-right">-</dd>
                                </div>
                                <div class="flex items-center justify-between gap-4 py-2 border-b border-[#6C757D]/10">
                                    <dt class="text-[#6C757D]">Filename</dt>
                                    <dd id="result-info-filename" class="font-medium text-[#212529] text-right break-all">-</dd>
                                </div>
                                <div class="flex items-center justify-between gap-4 py-2">
                                    <dt class="text-[#6C757D]">File size</dt>
                                    <dd id="result-info-filesize" class="font-semibold text-[#212529] text-right">-</dd>
                                </div>
                            </dl>

                            {{-- ===== OCR Panel — kanan, di bawah dl, di atas tombol ===== --}}
                            <div id="ocr-panel" data-filename="" class="hidden mt-4">
                                <div class="bg-[#F8F9FA] border border-[#6C757D]/20 rounded-xl p-4">
                                    <div class="flex items-center justify-between">
                                        <h4 class="text-sm font-bold text-[#212529]">OCR Result</h4>
                                        <span id="ocr-status-badge" class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30">idle</span>
                                    </div>
                                    <div id="ocr-loading" class="hidden mt-3 flex items-center gap-2 text-sm text-[#6C757D]">
                                        <svg class="w-4 h-4 animate-spin text-[#E04438]" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                        </svg>
                                        <span>Processing OCR... <span id="ocr-loading-seconds">0s</span></span>
                                    </div>
                                    <div id="ocr-cleaned-wrapper" class="hidden mt-3">
                                        <p class="text-xs font-medium text-[#6C757D]">Cleaned Text</p>
                                        <pre id="ocr-cleaned-text" class="mt-1 bg-white border border-[#6C757D]/20 rounded-lg p-3 text-sm text-[#212529] whitespace-pre-wrap break-words max-h-48 overflow-auto">-</pre>
                                    </div>
                                    <details id="ocr-raw-wrapper" class="hidden mt-3">
                                        <summary class="text-xs font-medium text-[#6C757D] cursor-pointer hover:text-[#212529]">Raw Text (debug)</summary>
                                        <pre id="ocr-raw-text" class="mt-1 bg-white border border-[#6C757D]/20 rounded-lg p-3 text-xs text-[#6C757D] whitespace-pre-wrap break-words">-</pre>
                                    </details>
                                    <div id="ocr-meta" class="hidden mt-3 grid grid-cols-2 gap-2 text-xs">
                                        <div><span class="text-[#6C757D]">Engine:</span> <span id="ocr-engine" class="font-semibold text-[#212529]">-</span></div>
                                        <div><span class="text-[#6C757D]">Time:</span> <span id="ocr-time" class="font-semibold text-[#212529]">-</span></div>
                                        <div><span class="text-[#6C757D]">Processed:</span> <span id="ocr-processed-at" class="font-semibold text-[#212529]">-</span></div>
                                        <div><span class="text-[#6C757D]">Lines:</span> <span id="ocr-lines-count" class="font-semibold text-[#212529]">-</span></div>
                                    </div>
                                    <p id="ocr-error" class="hidden mt-3 text-xs font-medium text-[#E04438]"></p>
                                </div>
                            </div>

                            <div class="mt-auto pt-4 flex items-center gap-3 flex-wrap">
                                <button id="result-process-ocr" type="button"
                                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-[#0284C7] text-white text-sm font-semibold transition shadow-sm hover:bg-[#0369A1] disabled:opacity-50 disabled:cursor-not-allowed">
                                    Proses OCR
                                </button>
                                <button id="save-capture-btn" disabled
                                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-sky-600 text-white text-sm font-semibold transition shadow-sm hover:bg-sky-700 disabled:opacity-50 disabled:cursor-not-allowed">
                                    <svg id="save-btn-icon" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/>
                                    </svg>
                                    <span id="save-btn-text">Simpan Reference</span>
                                </button>
                                <button id="retake-btn"
                                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold transition hover:bg-[#F8F9FA] disabled:opacity-60 disabled:cursor-not-allowed">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0114-3m2 9a8 8 0 01-14 3"/>
                                    </svg>
                                    Retake
                                </button>
                                <span id="result-feedback" class="text-xs font-medium text-sky-600"></span>
                            </div>

                            <p class="mt-3 text-xs text-[#6C757D]" id="result-help-text">
                                Atur rotasi lalu tekan <span class="font-semibold text-[#212529]">Proses OCR</span> — hasil raw teks tampil di panel <span class="font-semibold text-[#212529]">OCR Result</span> + console browser (F12). <span class="font-semibold text-[#212529]">Simpan Reference</span> menyimpan gambar + raw teks sebagai Reference Acuan (otomatis masuk Library Global anti-duplikat, jadi label yang sama cukup dipilih lain kali), lalu lanjut ke Check Label. Retake discards and reopens camera.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div id="loading-overlay" class="hidden fixed inset-0 z-[100] bg-[#212529]/40 flex items-center justify-center">
                <div class="bg-white rounded-2xl px-8 py-6 shadow-xl flex flex-col items-center gap-3">
                    <svg class="w-8 h-8 text-sky-600 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p id="loading-text" class="text-sm font-semibold text-[#212529]">Menyimpan reference...</p>
                </div>
            </div>

            <div id="success-overlay" class="hidden fixed inset-0 z-[100] bg-[#F8F9FA] flex items-center justify-center">
                <div class="text-center max-w-md mx-auto px-6">
                    <div class="mx-auto w-16 h-16 rounded-full bg-emerald-50 border border-emerald-200 flex items-center justify-center mb-4">
                        <svg class="w-8 h-8 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <h2 id="success-title" class="text-xl font-bold text-[#212529]">Reference Tersimpan</h2>
                    <p id="success-desc" class="mt-2 text-sm text-[#6C757D]">Data label capture &amp; raw teks tersimpan sebagai Reference Acuan.</p>
                    <button id="back-to-scan-btn"
                            class="mt-6 inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-sky-600 text-white text-sm font-semibold shadow-sm transition hover:bg-sky-700">
                        Continue ke Check Label
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </button>
                    <p class="mt-3 text-xs text-[#6C757D]">Otomatis pindah dalam 2–3 detik bila tidak diklik.</p>
                </div>
            </div>

            {{-- Modal nama reference label (STAGE-B: fungsional — konfirmasi menyimpan via POST /api/references + kolom name) --}}
            <div id="refname-modal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4">
                <div id="refname-modal-backdrop" class="absolute inset-0 bg-[#212529]/50"></div>
                <div role="dialog" aria-modal="true" aria-labelledby="refname-modal-title"
                     class="relative w-full max-w-sm bg-[#F8F9FA] rounded-2xl border border-[#6C757D]/20 shadow-xl p-5">
                    <h3 id="refname-modal-title" class="text-base font-bold text-[#212529]">Beri Nama Reference Label</h3>
                    <p class="mt-0.5 text-xs text-[#6C757D]">Nama dipakai sebagai identitas 1 set Front + Back ini di daftar dan Check Label. Tersimpan di database (3–80 karakter).</p>
                    <label for="refname-input" class="mt-4 block text-xs font-semibold text-[#212529]">Nama reference label</label>
                    <input id="refname-input" type="text" maxlength="80" minlength="3" autocomplete="off" placeholder="Test 1"
                           class="mt-1.5 w-full px-4 py-2.5 rounded-xl bg-white border border-[#6C757D]/30 text-base text-[#212529] placeholder-[#6C757D]/60 focus:outline-none focus:ring-2 focus:ring-[#0284C7]" />
                    <p id="refname-error" class="hidden mt-2 text-xs font-medium text-[#E04438]"></p>
                    <div class="mt-5 flex items-center gap-2">
                        <button id="refname-cancel" type="button"
                                class="flex-1 px-4 py-2.5 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold hover:bg-[#E9ECEF] transition disabled:opacity-50 disabled:cursor-not-allowed">
                            Batal
                        </button>
                        <button id="refname-confirm" type="button"
                                class="flex-1 px-4 py-2.5 rounded-xl bg-[#0284C7] text-white text-sm font-semibold transition shadow-sm hover:bg-[#0369A1] disabled:opacity-50 disabled:cursor-not-allowed">
                            Simpan Label
                        </button>
                    </div>
                </div>
            </div>

            <p class="text-center text-xs text-[#6C757D]">Camera preview only &mdash; the scanning engine will be added in the next stage.</p>
        </div>
    </div>
</main>
@endsection

@push('scripts')
<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
<script src="{{ asset('js/reference.js') }}?v={{ filemtime(public_path('js/reference.js')) }}"></script>
@endpush
