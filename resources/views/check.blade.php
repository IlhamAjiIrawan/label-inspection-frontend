@extends('layouts.app')

@section('title', 'Checking Label — Label Inspection')

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
                <a href="{{ route('reference') }}" class="hover:text-sky-600 transition">Reference</a>
                <a href="{{ route('check') }}" class="text-sky-600 font-semibold">Check Label</a>
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
                <h1 class="text-2xl md:text-3xl font-bold text-[#212529]">Checking Label</h1>
                <p class="mt-1 text-sm text-[#6C757D]">Bandingkan label produksi dengan Reference acuan.</p>
            </div>
            <span id="check-ref-badge" class="hidden px-3 py-1 rounded-full bg-[#E9ECEF] border border-[#6C757D]/30 text-xs font-medium text-[#6C757D]"></span>
        </div>

        {{-- Notification Horizontal — Reference Acuan (simplified minimalist) --}}
        <div id="check-ref-card" class="hidden max-w-5xl mx-auto mt-8 relative">
            {{-- Bar (selalu terlihat, 1 baris) --}}
            <div id="check-notif-bar" class="flex items-center gap-3 bg-[#E9ECEF] border border-[#6C757D]/20 rounded-xl px-4 py-3.5 shadow-sm">
                <span id="check-notif-dot" class="w-2.5 h-2.5 rounded-full bg-emerald-500 flex-shrink-0"></span>
                <div class="flex-1 min-w-0">
                    <h2 class="text-sm font-bold text-[#212529] leading-none">Reference Acuan</h2>
                    <p id="check-notif-subtitle" class="text-xs text-[#6C757D] truncate">-</p>
                </div>
                <button id="check-notif-toggle" type="button" aria-expanded="false" aria-controls="check-notif-dropdown"
                        class="flex-shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-lg border border-[#6C757D]/20 bg-[#F8F9FA] hover:bg-white text-[#212529] transition focus:outline-none focus:ring-2 focus:ring-sky-300">
                    <svg id="check-notif-chevron" class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                    <span class="sr-only">Toggle details</span>
                </button>
            </div>

            {{-- Dropdown panel (hidden default, absolute) — gambar acuan hasil capture Reference (FRONT + BACK) --}}
            <div id="check-notif-dropdown" class="hidden absolute left-0 right-0 top-full mt-2 bg-[#F8F9FA] border border-[#6C757D]/20 rounded-xl shadow-lg p-4 z-20">
                <p class="text-xs font-medium text-[#6C757D]">Gambar Acuan <span class="text-[#6C757D]/70">· hasil capture menu Reference</span></p>
                <div id="check-notif-dual-grid" class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <figure id="check-notif-fig-front" class="min-w-0">
                        <span id="check-notif-badge-front" class="hidden mb-2 inline-block px-2 py-0.5 rounded-full text-[11px] font-bold border bg-[#0284C7] text-white border-[#0284C7]">FRONT</span>
                        <div class="grid grid-cols-2 gap-1.5">
                            <div class="relative overflow-hidden rounded-lg border-0 bg-white">
                                <div id="check-notif-image-loading" class="flex items-center justify-center gap-2 px-4 py-10 text-xs text-[#6C757D]">
                                    <span class="size-4 rounded-full border-2 border-[#6C757D]/30 border-t-sky-600 animate-spin" aria-hidden="true"></span>
                                    Memuat gambar acuan...
                                </div>
                                <p id="check-notif-image-empty" class="hidden px-4 py-10 text-center text-xs text-[#6C757D]">Gambar acuan tidak tersedia.</p>
                                <img id="check-notif-image" alt="Gambar acuan sisi depan" class="hidden max-h-72 w-full object-contain bg-white" />
                                <span class="absolute top-1 left-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F8F9FA] border border-[#6C757D]/20 text-[#212529]">1</span>
                            </div>
                            <div class="relative overflow-hidden rounded-lg border-0 bg-white">
                                <div id="check-notif-image-loading-front-2" class="flex items-center justify-center gap-2 px-4 py-10 text-xs text-[#6C757D]">
                                    <span class="size-4 rounded-full border-2 border-[#6C757D]/30 border-t-sky-600 animate-spin" aria-hidden="true"></span>
                                    Memuat gambar acuan...
                                </div>
                                <p id="check-notif-image-empty-front-2" class="hidden px-4 py-10 text-center text-xs text-[#6C757D]">Gambar acuan tidak tersedia.</p>
                                <img id="check-notif-image-front-2" alt="Gambar acuan sisi depan label 2" class="hidden max-h-72 w-full object-contain bg-white" />
                                <span class="absolute top-1 left-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F8F9FA] border border-[#6C757D]/20 text-[#212529]">2</span>
                            </div>
                        </div>
                        <figcaption id="check-notif-file-front" title="" class="hidden mt-1 text-[11px] text-[#6C757D] truncate">-</figcaption>
                    </figure>
                    <figure id="check-notif-fig-back" class="hidden min-w-0">
                        <span id="check-notif-badge-back" class="mb-2 inline-block px-2 py-0.5 rounded-full text-[11px] font-bold border bg-[#0284C7] text-white border-[#0284C7]">BACK</span>
                        <div class="grid grid-cols-2 gap-1.5">
                            <div class="relative overflow-hidden rounded-lg border-0 bg-white" role="status" aria-live="polite">
                                <div id="check-notif-image-loading-back" class="flex items-center justify-center gap-2 px-4 py-10 text-xs text-[#6C757D]">
                                    <span class="size-4 rounded-full border-2 border-[#6C757D]/30 border-t-sky-600 animate-spin" aria-hidden="true"></span>
                                    Memuat gambar BACK...
                                </div>
                                <p id="check-notif-image-empty-back" class="hidden px-4 py-10 text-center text-xs text-[#6C757D]">Gambar BACK tidak tersedia.</p>
                                <img id="check-notif-image-back" alt="Gambar acuan sisi belakang" class="hidden max-h-72 w-full object-contain bg-white" />
                                <span class="absolute top-1 left-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F8F9FA] border border-[#6C757D]/20 text-[#212529]">1</span>
                            </div>
                            <div class="relative overflow-hidden rounded-lg border-0 bg-white">
                                <div id="check-notif-image-loading-back-2" class="flex items-center justify-center gap-2 px-4 py-10 text-xs text-[#6C757D]">
                                    <span class="size-4 rounded-full border-2 border-[#6C757D]/30 border-t-sky-600 animate-spin" aria-hidden="true"></span>
                                    Memuat gambar BACK...
                                </div>
                                <p id="check-notif-image-empty-back-2" class="hidden px-4 py-10 text-center text-xs text-[#6C757D]">Gambar BACK tidak tersedia.</p>
                                <img id="check-notif-image-back-2" alt="Gambar acuan sisi belakang label 2" class="hidden max-h-72 w-full object-contain bg-white" />
                                <span class="absolute top-1 left-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-[#F8F9FA] border border-[#6C757D]/20 text-[#212529]">2</span>
                            </div>
                        </div>
                        <figcaption id="check-notif-file-back" title="" class="mt-1 text-[11px] text-[#6C757D] truncate">-</figcaption>
                    </figure>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-[#6C757D]">
                    <span>Template: <span id="check-notif-template" class="font-semibold text-[#212529]">-</span></span>
                    <span class="hidden sm:inline">·</span>
                    <span>Ukuran: <span id="check-notif-size" class="font-semibold text-[#212529]">-</span></span>
                    <span class="hidden">·</span>
                    <span class="hidden min-w-0 truncate">File: <span id="check-notif-file" class="font-semibold text-[#212529]">-</span></span>
                    <span id="check-notif-file-back-wrap" class="hidden min-w-0 truncate"><span class="hidden sm:inline">· </span>File Back: <span id="check-notif-file-back-meta" class="font-semibold text-[#212529]">-</span></span>
                </div>
                <a id="check-notif-ganti" href="/reference" class="mt-3 inline-flex text-xs font-semibold text-sky-600 hover:underline">Ganti Reference →</a>
            </div>
        </div>

        {{-- Information section tahapan sesi — mirror Reference dual-stepper, tampil saat Start Scanning Label --}}
        <div id="check-stage-stepper-wrap" class="hidden max-w-5xl mx-auto mt-8">
            <div id="check-stage-stepper" class="flex flex-wrap items-center gap-2 text-xs font-semibold">
                <span id="check-stage-step-1" class="px-3 py-1.5 rounded-full border bg-[#0284C7] text-white border-[#0284C7]">1 · Front (Depan)</span>
                <span aria-hidden="true" class="text-[#6C757D]/50">—</span>
                <span id="check-stage-step-2" class="px-3 py-1.5 rounded-full border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30">2 · Back (Belakang)</span>
                <span aria-hidden="true" class="text-[#6C757D]/50">—</span>
                <span id="check-stage-step-3" class="px-3 py-1.5 rounded-full border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30">Process</span>
            </div>
        </div>

        {{-- Loading & Error & Empty --}}
        <div id="check-loading" class="max-w-5xl mx-auto mt-8 flex items-center gap-2 text-sm text-[#6C757D]">
            <svg class="w-4 h-4 animate-spin text-[#E04438]" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            Memuat Reference acuan...
        </div>
        <p id="check-error" class="hidden max-w-5xl mx-auto mt-8 p-4 rounded-xl bg-[#E04438]/10 border border-[#E04438]/30 text-sm text-[#E04438]"></p>
        <div id="check-empty" class="hidden max-w-5xl mx-auto mt-8 flex flex-col items-center justify-center py-16 text-center bg-[#E9ECEF] rounded-2xl border border-[#6C757D]/30 shadow-sm">
            <span class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-[#F8F9FA] text-[#6C757D]/60 border border-[#6C757D]/20">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M16 11a5 5 0 11-10 0 5 5 0 0110 0zM8 9h.01M12 9h.01M16 9h.01"/>
                </svg>
            </span>
            <h3 class="mt-4 text-lg font-semibold text-[#212529]">Belum ada Reference</h3>
            <p class="mt-1 text-sm text-[#6C757D] max-w-sm">Buat Reference di menu Reference terlebih dahulu. Simpan Reference akan otomatis membawa data acuan ke halaman ini.</p>
            <a href="/reference" class="mt-6 inline-flex items-center px-5 py-2.5 rounded-xl bg-sky-600 text-white text-sm font-semibold shadow-sm hover:bg-sky-700 transition">Buat Reference</a>
        </div>

        {{-- Live Label Scan — 1 guide horizontal fixed 160×35 mm --}}
        <div id="check-slots" class="hidden max-w-5xl mx-auto mt-8">
            <div class="bg-[#E9ECEF] rounded-2xl border border-[#6C757D]/30 shadow-sm p-6 md:p-8">
                <div id="check-scan-header" class="flex flex-col sm:flex-row sm:items-center gap-4">
                    <span class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-[#F8F9FA] text-[#6C757D] border border-[#6C757D]/20">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9zm7 3a3 3 0 106 0 3 3 0 00-6 0zm4.5-2.5h.01"/>
                        </svg>
                    </span>
                    <div class="flex-1">
                        <h2 class="text-lg font-bold text-[#212529]">Live Label Scan</h2>
                        <p class="mt-1 text-sm text-[#6C757D]">Posisikan label di frame horizontal — panjang 160 mm, lebar 35 mm.</p>
                    </div>
                    <button id="check-start-scan-btn" type="button"
                            class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-sky-600 text-white text-sm font-semibold transition shadow-sm hover:bg-sky-700 disabled:opacity-60 disabled:cursor-not-allowed">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                        </svg>
                        Start Scanning Label
                    </button>
                </div>

                <div id="check-camera-card" class="hidden mt-6">
                    <div class="bg-[#212529] rounded-2xl overflow-hidden relative">
                        <video id="check-camera-preview" autoplay playsinline muted
                               class="w-full h-auto max-h-[70vh] object-contain bg-[#212529]"></video>
                        <div class="absolute top-3 left-3 flex items-center gap-2 bg-[#212529]/70 rounded-full px-3 py-1.5">
                            <span class="w-2 h-2 rounded-full bg-sky-500 animate-pulse"></span>
                            <span class="text-white text-xs font-semibold tracking-wide">LIVE</span>
                        </div>
                        <canvas id="check-guide-overlay" aria-hidden="true"
                                 class="pointer-events-none absolute inset-0 w-full h-full"></canvas>
                        {{-- Mini Front preview dihapus (Stage 2 minimalist): badge ukuran tetap satu-satunya di kanan atas --}}
                        {{-- Guide size badge (top-right) — shows template info + FRONT/BACK --}}
                        <span id="check-guide-size-badge"
                              class="absolute top-3 right-3 max-w-[60%] truncate bg-[#212529]/70 rounded-full px-3 py-1.5 text-white text-xs font-semibold tracking-wide"></span>
                        <div id="check-capture-flash"
                             class="pointer-events-none absolute inset-0 bg-white opacity-0 transition-opacity duration-150"></div>
                        {{-- Loading overlay sampai Tersimpan di FastAPI (CP-031) --}}
                        <div id="check-capture-loading" class="hidden absolute inset-0 bg-[#212529]/60 backdrop-blur-sm flex flex-col items-center justify-center gap-3 z-10 rounded-2xl">
                            <div class="w-8 h-8 border-[3px] border-white/30 border-t-white rounded-full animate-spin"></div>
                            <p class="text-white text-sm font-semibold">Mengirim &amp; menyimpan ke FastAPI...</p>
                            <p id="check-capture-loading-sub" class="text-white/80 text-xs">Menyiapkan 1 label...</p>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center gap-3">
                        <label for="check-guide-size-range" class="text-sm font-medium text-[#212529] whitespace-nowrap">Frame size</label>
                        <input id="check-guide-size-range" type="range" min="20" max="100" step="1" value="100"
                               class="flex-1 sm:flex-none sm:w-56 accent-sky-600 cursor-pointer" />
                        <span id="check-guide-size-value" class="text-xs font-semibold text-[#6C757D] w-10">100%</span>
                    </div>
                    <p class="mt-1 text-xs text-[#6C757D]">
                        Posisikan label aktual agar pas di dalam frame. Frame horizontal tetap 160×35 mm. Atur SEBELUM Capture — Retake untuk atur ulang.
                    </p>

                    <div class="mt-4 flex items-center justify-between flex-wrap gap-3">
                        <p id="check-camera-label" class="text-sm text-[#6C757D]">-</p>
                        <div class="flex items-center gap-3 flex-wrap">
                            <span id="check-capture-feedback" class="text-xs font-medium text-sky-600"></span>
                            <span id="check-upload-progress" class="hidden text-xs font-medium text-sky-600"></span>
                            <button id="check-capture-btn" type="button"
                                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-sky-600 text-white text-sm font-semibold transition shadow-sm hover:bg-sky-700 disabled:opacity-60 disabled:cursor-not-allowed">
                                <span id="check-capture-spinner" class="hidden w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                                <svg id="check-capture-icon" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9zm14 3a4 4 0 11-8 0 4 4 0 018 0z"/>
                                </svg>
                                <span id="check-capture-text">Capture Label</span>
                            </button>
                            <button id="check-stop-scan-btn" type="button"
                                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold transition hover:bg-[#F8F9FA] disabled:opacity-60 disabled:cursor-not-allowed">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                    <rect x="6" y="6" width="12" height="12" rx="2"/>
                                </svg>
                                Stop
                            </button>
                        </div>
                    </div>

                    {{-- Session badge — muncul setelah batch 201 --}}
                    <div id="check-session-badge" class="hidden mt-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-[#E9ECEF] border border-[#6C757D]/20 text-xs font-semibold text-[#212529]">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        Session #<span id="check-session-id">-</span> · <span id="check-session-code">-</span>
                    </div>
                    <p id="check-upload-hint" class="hidden mt-2 text-[11px] text-[#6C757D]">Mengirim ke server — jangan tutup halaman.</p>

                    {{-- External status — hidden (dihapus per request, tetap di DOM untuk compat JS tapi hidden) --}}
                    <div id="check-external-status" class="hidden"></div>

                    {{-- Strip preview 1 label — fallback/debug (radius luas +10mm tol, slot 2-4 hidden) --}}
                    <div id="check-slot-strip" class="hidden mt-6">
                        <p class="text-xs font-medium text-[#6C757D]">Preview 1 Label</p>
                        <div class="mt-3 grid grid-cols-1 gap-3">
                            <div class="aspect-[2/1] rounded-lg bg-[#F8F9FA] border border-dashed border-[#6C757D]/30 flex items-center justify-center overflow-hidden relative">
                                <span class="text-[11px] text-[#6C757D]/70">Slot 1</span>
                                <img id="check-slot-thumb-1" alt="Slot 1" class="hidden w-full h-full object-contain absolute inset-0" />
                            </div>
                            <div class="hidden aspect-[1/2] rounded-lg bg-[#F8F9FA] border border-dashed border-[#6C757D]/30 flex items-center justify-center overflow-hidden relative">
                                <span class="text-[11px] text-[#6C757D]/70">Slot 2</span>
                                <img id="check-slot-thumb-2" alt="Slot 2" class="hidden w-full h-full object-contain absolute inset-0" />
                            </div>
                            <div class="hidden aspect-[1/2] rounded-lg bg-[#F8F9FA] border border-dashed border-[#6C757D]/30 flex items-center justify-center overflow-hidden relative">
                                <span class="text-[11px] text-[#6C757D]/70">Slot 3</span>
                                <img id="check-slot-thumb-3" alt="Slot 3" class="hidden w-full h-full object-contain absolute inset-0" />
                            </div>
                            <div class="hidden aspect-[1/2] rounded-lg bg-[#F8F9FA] border border-dashed border-[#6C757D]/30 flex items-center justify-center overflow-hidden relative">
                                <span class="text-[11px] text-[#6C757D]/70">Slot 4</span>
                                <img id="check-slot-thumb-4" alt="Slot 4" class="hidden w-full h-full object-contain absolute inset-0" />
                            </div>
                            {{-- Slot 5 hidden compat between 4,5 --}}
                            <img id="check-slot-thumb-5" alt="Slot 5" class="hidden" />
                        </div>
                    </div>
                </div>

                {{-- Preview Sesi 1 — FRONT tersimpan sementara (mirror Reference dual-front-mini, UI saja) --}}
                <div id="check-session1-card" class="hidden mt-6 bg-[#F8F9FA] border border-[#6C757D]/20 rounded-2xl p-4">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        <h3 class="text-base font-bold text-[#212529]">Sisi Depan (Front) tersimpan sementara</h3>
                        <span class="ml-auto px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">FRONT</span>
                    </div>
                    <div id="check-session1-single" class="mt-4 grid grid-cols-1 sm:grid-cols-[200px_1fr] gap-4">
                        <div class="bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                            <img id="check-session1-img" alt="Hasil capture Sesi 1 sisi depan" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                        </div>
                        <div class="flex flex-col gap-2">
                            {{-- Set Rotation FRONT sementara — tiru Reference dual-front-adjust --}}
                            <div class="flex items-center gap-2 flex-wrap">
                                <button id="check-session1-rotate-left" type="button" aria-label="Putar Front 90 derajat ke kiri"
                                        class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-xs font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M4 9a8 8 0 0114-3"/>
                                    </svg>
                                    Rotate Kiri
                                </button>
                                <button id="check-session1-rotate-right" type="button" aria-label="Putar Front 90 derajat ke kanan"
                                        class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-xs font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                    Rotate Kanan
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 4v5h-5M20 9a8 8 0 00-14-3"/>
                                    </svg>
                                </button>
                                <span id="check-session1-rotate-deg" class="text-xs font-semibold text-[#6C757D] w-10">0°</span>
                                <button id="check-session1-crop" type="button" aria-label="Simpan master Front jadi 2 label"
                                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#0284C7] text-white text-xs font-semibold transition shadow-sm hover:bg-[#0369A1] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                    Simpan
                                </button>
                            </div>
                            <p class="text-xs text-[#6C757D]">Atur rotasi dulu, baru simpan.</p>
                            <p id="check-session1-meta" class="text-xs text-[#6C757D]">-</p>
                        </div>
                    </div>
                    <div id="check-session1-split" class="hidden mt-4">
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <div class="flex flex-col gap-2">
                                        <p class="text-xs font-semibold text-[#212529]">Review result capture</p>
                                        <div class="bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                                            <img id="check-session1-split-review" alt="Review result capture sisi depan" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                                        </div>
                                    </div>
                                    <div class="flex flex-col gap-2">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">Label 1</span>
                                            <span id="check-session1-split-badge-1" class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30">Label 1 · idle</span>
                                            <span id="check-result-back-split-cmp-1" class="hidden px-2.5 py-0.5 rounded-full text-xs font-semibold border"></span>
                                        </div>
                                        <div class="relative bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                                            <img id="check-session1-split-img-1" alt="Hasil crop Label 1 sisi depan" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                                            <span id="check-session1-split-check-1" class="hidden absolute top-2 right-2 inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-500 border border-white">
                                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                                </svg>
                                            </span>
                                        </div>
                                        <p id="check-session1-split-meta-1" class="text-xs text-[#6C757D] break-all">-</p>
                                    </div>
                                    <div class="flex flex-col gap-2">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">Label 2</span>
                                            <span id="check-session1-split-badge-2" class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30">Label 2 · idle</span>
                                            <span id="check-result-back-split-cmp-2" class="hidden px-2.5 py-0.5 rounded-full text-xs font-semibold border"></span>
                                        </div>
                                        <div class="relative bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                                            <img id="check-session1-split-img-2" alt="Hasil crop Label 2 sisi depan" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                                            <span id="check-session1-split-check-2" class="hidden absolute top-2 right-2 inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-500 border border-white">
                                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                                </svg>
                                            </span>
                                        </div>
                                        <p id="check-session1-split-meta-2" class="text-xs text-[#6C757D] break-all">-</p>
                                </div>
                            </div>
                        <div class="mt-3 bg-white border border-[#6C757D]/20 rounded-xl overflow-hidden">
                            <div class="flex items-center gap-2 flex-wrap p-2 border-b border-[#6C757D]/10 bg-[#F8F9FA]">
                                <span class="mr-auto text-xs font-semibold text-[#212529]">Hasil ekstraksi teks</span>
                                <button id="check-session1-tab-1" type="button" class="px-3.5 py-1.5 rounded-full text-xs font-semibold border bg-[#0284C7] text-white border-[#0284C7] transition focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">Label 1</button>
                                <button id="check-session1-tab-2" type="button" class="px-3.5 py-1.5 rounded-full text-xs font-semibold border border-[#6C757D]/40 text-[#212529] transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">Label 2</button>
                                <button id="check-session1-ocr-toggle" type="button" aria-expanded="false" aria-controls="check-session1-ocr-body" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border border-[#6C757D]/40 text-[#212529] transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7]">
                                    <svg class="w-3.5 h-3.5 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                    <span data-ocr-toggle-label>Lihat teks</span>
                                </button>
                            </div>
                            <div id="check-session1-ocr-body" class="hidden">
                                <pre id="check-session1-ocr-view" class="p-3 text-sm text-[#212529] whitespace-pre-wrap break-words min-h-[6rem] max-h-40 overflow-auto">Hasil ekstraksi akan tampil di sini setelah proses OCR (tahap berikutnya).</pre>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-3 flex-wrap">
                                <button id="check-session1-retake" type="button"
                                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7]">
                                    Retake Front
                                </button>
                                <button id="check-goto-session2-btn" type="button" disabled title="Atur rotasi lalu tekan Simpan (Auto Crop 2 label) untuk mengaktifkan"
                                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-[#0284C7] text-white text-sm font-semibold transition shadow-sm hover:bg-[#0369A1] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                    Lanjut ke Capture 2 (Back)
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                                    </svg>
                                </button>
                                <span id="check-session1-feedback" class="text-xs font-medium text-[#6C757D]"></span>
                                <span id="check-session1-ocr-loader" role="status" aria-live="polite" class="hidden items-center gap-2 text-xs font-medium text-[#6C757D]">
                                    <span aria-hidden="true" class="w-4 h-4 rounded-full border-2 border-[#6C757D]/30 border-t-[#0284C7] animate-spin"></span>
                                    <span id="check-session1-ocr-loader-text">Mengekstrak teks Front Label 1·2…</span>
                                </span>
                            </div>
                </div>

                {{-- Preview Sesi 2 — BACK tersimpan sementara (cermin Sesi 1 / Reference dual-back-mini) --}}
                <div id="check-session2-card" class="hidden mt-6 bg-[#F8F9FA] border border-[#6C757D]/20 rounded-2xl p-4">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        <h3 class="text-base font-bold text-[#212529]">Sisi Belakang (Back) tersimpan sementara</h3>
                        <span class="ml-auto px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">BACK</span>
                    </div>
                    <div id="check-session2-single" class="mt-4 grid grid-cols-1 sm:grid-cols-[200px_1fr] gap-4">
                        <div class="bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                            <img id="check-session2-img" alt="Hasil capture Sesi 2 sisi belakang" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <button id="check-session2-rotate-left" type="button" aria-label="Putar Back 90 derajat ke kiri"
                                        class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-xs font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M4 9a8 8 0 0114-3"/>
                                    </svg>
                                    Rotate Kiri
                                </button>
                                <button id="check-session2-rotate-right" type="button" aria-label="Putar Back 90 derajat ke kanan"
                                        class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-xs font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                    Rotate Kanan
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 4v5h-5M20 9a8 8 0 00-14-3"/>
                                    </svg>
                                </button>
                                <span id="check-session2-rotate-deg" class="text-xs font-semibold text-[#6C757D] w-10">0°</span>
                                <button id="check-session2-crop" type="button" aria-label="Simpan master Back jadi 2 label"
                                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#0284C7] text-white text-xs font-semibold transition shadow-sm hover:bg-[#0369A1] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                    Simpan
                                </button>
                            </div>
                            <p class="text-xs text-[#6C757D]">Atur rotasi dulu, baru simpan.</p>
                            <p id="check-session2-meta" class="text-xs text-[#6C757D]">-</p>
                        </div>
                    </div>
                    <div id="check-session2-split" class="hidden mt-4">
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <div class="flex flex-col gap-2">
                                        <p class="text-xs font-semibold text-[#212529]">Review result capture</p>
                                        <div class="bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                                            <img id="check-session2-split-review" alt="Review result capture sisi belakang" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                                        </div>
                                    </div>
                                    <div class="flex flex-col gap-2">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">Label 1</span>
                                            <span id="check-session2-split-badge-1" class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30">idle</span>
                                        </div>
                                        <div class="relative bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                                            <img id="check-session2-split-img-1" alt="Hasil crop Label 1 sisi belakang" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                                            <span id="check-session2-split-check-1" class="hidden absolute top-2 right-2 inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-500 border border-white">
                                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                                </svg>
                                            </span>
                                        </div>
                                        <p id="check-session2-split-meta-1" class="text-xs text-[#6C757D] break-all">-</p>
                                    </div>
                                    <div class="flex flex-col gap-2">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">Label 2</span>
                                            <span id="check-session2-split-badge-2" class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-[#E9ECEF] text-[#6C757D] border-[#6C757D]/30">idle</span>
                                        </div>
                                        <div class="relative bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[10rem] p-2">
                                            <img id="check-session2-split-img-2" alt="Hasil crop Label 2 sisi belakang" class="h-36 sm:h-40 w-auto max-w-full mx-auto object-contain" />
                                            <span id="check-session2-split-check-2" class="hidden absolute top-2 right-2 inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-500 border border-white">
                                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                                </svg>
                                            </span>
                                        </div>
                                        <p id="check-session2-split-meta-2" class="text-xs text-[#6C757D] break-all">-</p>
                                    </div>
                                </div>
                        <div class="mt-3 bg-white border border-[#6C757D]/20 rounded-xl overflow-hidden">
                            <div class="flex items-center gap-2 flex-wrap p-2 border-b border-[#6C757D]/10 bg-[#F8F9FA]">
                                <span class="mr-auto text-xs font-semibold text-[#212529]">Hasil ekstraksi teks</span>
                                <button id="check-session2-tab-1" type="button" class="px-3.5 py-1.5 rounded-full text-xs font-semibold border bg-[#0284C7] text-white border-[#0284C7] transition focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">Label 1</button>
                                <button id="check-session2-tab-2" type="button" class="px-3.5 py-1.5 rounded-full text-xs font-semibold border border-[#6C757D]/40 text-[#212529] transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">Label 2</button>
                                <button id="check-session2-ocr-toggle" type="button" aria-expanded="false" aria-controls="check-session2-ocr-body" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border border-[#6C757D]/40 text-[#212529] transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7]">
                                    <svg class="w-3.5 h-3.5 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                    <span data-ocr-toggle-label>Lihat teks</span>
                                </button>
                            </div>
                            <div id="check-session2-ocr-body" class="hidden">
                                <pre id="check-session2-ocr-view" class="p-3 text-sm text-[#212529] whitespace-pre-wrap break-words min-h-[6rem] max-h-40 overflow-auto">Hasil ekstraksi akan tampil di sini setelah proses OCR (tahap berikutnya).</pre>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-3 flex-wrap">
                                <button id="check-session2-retake" type="button"
                                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7]">
                                    Retake Back
                                </button>
                                <button id="check-goto-result-btn" type="button" disabled title="Atur rotasi lalu tekan Simpan (Auto Crop 2 label) untuk mengaktifkan"
                                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-[#0284C7] text-white text-sm font-semibold transition shadow-sm hover:bg-[#0369A1] focus:outline-none focus:ring-2 focus:ring-[#0284C7] disabled:opacity-50 disabled:cursor-not-allowed">
                                    Lihat Hasil 2 Sisi
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                                    </svg>
                                </button>
                                <span id="check-session2-feedback" class="text-xs font-medium text-[#6C757D]"></span>
                                <span id="check-session2-ocr-loader" role="status" aria-live="polite" class="hidden items-center gap-2 text-xs font-medium text-[#6C757D]">
                                    <span aria-hidden="true" class="w-4 h-4 rounded-full border-2 border-[#6C757D]/30 border-t-[#0284C7] animate-spin"></span>
                                    <span id="check-session2-ocr-loader-text">Mengekstrak teks Back Label 1·2…</span>
                                </span>
                            </div>
                </div>

                {{-- Preview gabungan Sesi 1+2 — FRONT + BACK (mirror Reference dual-result-card, UI saja) --}}
                <div id="check-dual-result-card" class="hidden mt-6">
                    <div class="flex items-center gap-2 flex-wrap">
                        <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        <h3 class="text-base font-bold text-[#212529]">Capture Result (2 sisi)</h3>
                        <span class="ml-auto text-xs font-medium text-[#6C757D]">Front + Back — 1 set Check</span>
                    </div>

                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-[#F8F9FA] rounded-2xl border border-[#6C757D]/20 p-3 flex flex-col gap-2">
                            <div class="flex items-center gap-2">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">FRONT</span>
                                <span id="check-result-front-badge" class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-white text-[#212529] border-emerald-300">success</span>
                            </div>
                            <div id="check-result-front-split" class="mt-2">
                                <div class="grid grid-cols-2 gap-2">
                                    <div class="flex flex-col gap-2">
                                        <div class="relative bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[7rem] p-2">
                                            <img id="check-result-front-split-img-1" alt="Hasil crop Label 1 sisi depan" class="h-28 sm:h-32 w-auto max-w-full mx-auto object-contain" />
                                            <span id="check-result-front-split-check-1" class="hidden absolute top-2 right-2 inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-500 border border-white">
                                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                            </span>
                                        </div>
                                        <p id="check-result-front-split-meta-1" class="text-xs text-[#6C757D] break-all">-</p>
                                        <pre id="check-result-front-split-text-1" class="bg-white border border-[#6C757D]/20 rounded-lg p-3 text-sm text-[#212529] whitespace-pre-wrap break-words min-h-[5rem] max-h-40 overflow-auto">-</pre>
                                    </div>
                                    <div class="flex flex-col gap-2">
                                        <div class="relative bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[7rem] p-2">
                                            <img id="check-result-front-split-img-2" alt="Hasil crop Label 2 sisi depan" class="h-28 sm:h-32 w-auto max-w-full mx-auto object-contain" />
                                            <span id="check-result-front-split-check-2" class="hidden absolute top-2 right-2 inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-500 border border-white">
                                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                            </span>
                                        </div>
                                        <p id="check-result-front-split-meta-2" class="text-xs text-[#6C757D] break-all">-</p>
                                        <pre id="check-result-front-split-text-2" class="bg-white border border-[#6C757D]/20 rounded-lg p-3 text-sm text-[#212529] whitespace-pre-wrap break-words min-h-[5rem] max-h-40 overflow-auto">-</pre>
                                    </div>
                                </div>
                            </div>
                            <button id="check-retake-front" type="button"
                                    class="hidden self-start inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7]">
                                Retake Depan
                            </button>
                        </div>
                        <div class="bg-[#F8F9FA] rounded-2xl border border-[#6C757D]/20 p-3 flex flex-col gap-2">
                            <div class="flex items-center gap-2">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border bg-[#0284C7] text-white border-[#0284C7]">BACK</span>
                                <span id="check-result-back-badge" class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-white text-[#212529] border-emerald-300">success</span>
                            </div>
                            <div id="check-result-back-split" class="mt-2">
                                <div class="grid grid-cols-2 gap-2">
                                    <div class="flex flex-col gap-2">
                                        <div class="relative bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[7rem] p-2">
                                            <img id="check-result-back-split-img-1" alt="Hasil crop Label 1 sisi belakang" class="h-28 sm:h-32 w-auto max-w-full mx-auto object-contain" />
                                        </div>
                                        <p id="check-result-back-split-meta-1" class="text-xs text-[#6C757D] break-all">-</p>
                                        <pre id="check-result-back-split-text-1" class="bg-white border border-[#6C757D]/20 rounded-lg p-3 text-sm text-[#212529] whitespace-pre-wrap break-words min-h-[5rem] max-h-40 overflow-auto">-</pre>
                                    </div>
                                    <div class="flex flex-col gap-2">
                                        <div class="relative bg-[#212529] rounded-xl overflow-hidden flex items-center justify-center min-h-[7rem] p-2">
                                            <img id="check-result-back-split-img-2" alt="Hasil crop Label 2 sisi belakang" class="h-28 sm:h-32 w-auto max-w-full mx-auto object-contain" />
                                        </div>
                                        <p id="check-result-back-split-meta-2" class="text-xs text-[#6C757D] break-all">-</p>
                                        <pre id="check-result-back-split-text-2" class="bg-white border border-[#6C757D]/20 rounded-lg p-3 text-sm text-[#212529] whitespace-pre-wrap break-words min-h-[5rem] max-h-40 overflow-auto">-</pre>
                                    </div>
                                </div>
                            </div>
                            <button id="check-retake-back" type="button"
                                    class="hidden self-start inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-[#6C757D]/40 text-[#212529] text-sm font-semibold transition hover:bg-[#E9ECEF] focus:outline-none focus:ring-2 focus:ring-[#0284C7]">
                                Retake Belakang
                            </button>
                        </div>
                    </div>

                    <p id="check-dual-result-meta" class="mt-3 text-xs text-[#6C757D]">-</p>
                </div>

                {{-- Aksi preview gabungan — Process tahap berikutnya (inert, UI saja) --}}
                <div id="check-dual-result-actions" class="hidden mt-3 flex flex-col sm:flex-row sm:items-center gap-3">
                    <button id="check-proceed-check" type="button" title="Compare similarity tahap berikutnya (belum aktif)"
                            class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-[#0284C7] text-white text-sm font-semibold transition shadow-sm hover:bg-[#0369A1] focus:outline-none focus:ring-2 focus:ring-[#0284C7]">
                        Process
                    </button>
                    <span id="check-dual-process-feedback" class="text-xs font-medium text-[#6C757D]"></span>
                </div>
                <p id="check-dual-process-help" class="hidden mt-2 text-[11px] text-[#6C757D]">Process tahap berikutnya — compare similarity Front-vs-Front dan Back-vs-Back (belum aktif, UI saja).</p>
            </div>
        </div>

        {{-- Process Result — 1 section card hasil compare + 4 capture (FRONT 1·2 + BACK 1·2) CP-066 --}}
        <div id="check-preview-card" class="hidden max-w-5xl mx-auto mt-8 bg-[#F8F9FA] border border-[#6C757D]/20 rounded-2xl shadow-sm p-6 md:p-8">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-[#212529]">Process Result</h2>
                    <p class="mt-1 text-sm text-[#6C757D]">Front Label 1·2 + Back Label 1·2 — hasil compare</p>
                </div>
                <div class="flex items-center gap-2">
                    <span id="check-preview-session-badge" class="hidden inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-[#E9ECEF] border border-[#6C757D]/20 text-xs font-semibold text-[#212529]">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        Session #<span id="check-preview-session-id">-</span> · <span id="check-preview-session-code">-</span>
                    </span>
                </div>
            </div>

            {{-- Notifikasi external — hidden (dihapus) --}}
            <div id="check-preview-external-status" class="hidden"></div>

            {{-- OCR Status — simple 'processing success' saja (CP-034b) --}}
            <div id="check-ocr-status" class="hidden mt-4 p-3 rounded-xl border bg-emerald-50 border-emerald-200 text-sm">
                <p id="check-ocr-title" class="font-semibold text-emerald-700 text-center">processing success</p>
            </div>

            {{-- Grid 4 capture — FRONT 1·2 + BACK 1·2 sejajar 1 baris di desktop (lg:4), 2x2 di HP/tablet --}}
            <div class="mt-6 grid grid-cols-2 lg:grid-cols-4 gap-2 md:gap-3">
                <div id="check-preview-slot-1" data-slot="1" data-side="front" class="flex flex-col gap-2 rounded-xl border border-[#6C757D]/20 bg-white p-2 md:p-3 transition-colors">
                    <p class="flex items-center gap-1.5 text-[11px] font-semibold text-[#212529]"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold border bg-[#0284C7] text-white border-[#0284C7]">FRONT</span> Label 1</p>
                    <span id="check-preview-status-1" role="status" aria-live="polite" class="hidden items-center justify-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold truncate"></span>
                    <div class="aspect-[16/10] min-h-[120px] sm:min-h-[140px] lg:min-h-[150px] overflow-hidden flex items-center justify-center relative rounded-lg bg-[#F8F9FA] border border-[#6C757D]/10">
                        <span class="text-[11px] text-[#6C757D]/70">Front · Label 1</span>
                        <img id="check-preview-thumb-1" alt="Front Label 1" class="hidden w-full h-full object-contain absolute inset-0" />
                    </div>
                </div>
                <div id="check-preview-slot-2" data-slot="2" data-side="front" class="flex flex-col gap-2 rounded-xl border border-[#6C757D]/20 bg-white p-2 md:p-3 transition-colors">
                    <p class="flex items-center gap-1.5 text-[11px] font-semibold text-[#212529]"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold border bg-[#0284C7] text-white border-[#0284C7]">FRONT</span> Label 2</p>
                    <span id="check-preview-status-2" role="status" aria-live="polite" class="hidden items-center justify-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold truncate"></span>
                    <div class="aspect-[16/10] min-h-[120px] sm:min-h-[140px] lg:min-h-[150px] overflow-hidden flex items-center justify-center relative rounded-lg bg-[#F8F9FA] border border-[#6C757D]/10">
                        <span class="text-[11px] text-[#6C757D]/70">Front · Label 2</span>
                        <img id="check-preview-thumb-2" alt="Front Label 2" class="hidden w-full h-full object-contain absolute inset-0" />
                    </div>
                </div>
                <div id="check-preview-slot-3" data-slot="3" data-side="back" class="flex flex-col gap-2 rounded-xl border border-[#6C757D]/20 bg-white p-2 md:p-3 transition-colors">
                    <p class="flex items-center gap-1.5 text-[11px] font-semibold text-[#212529]"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold border bg-[#0284C7] text-white border-[#0284C7]">BACK</span> Label 1</p>
                    <span id="check-preview-status-3" role="status" aria-live="polite" class="hidden items-center justify-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold truncate"></span>
                    <div class="aspect-[16/10] min-h-[120px] sm:min-h-[140px] lg:min-h-[150px] overflow-hidden flex items-center justify-center relative rounded-lg bg-[#F8F9FA] border border-[#6C757D]/10">
                        <span class="text-[11px] text-[#6C757D]/70">Back · Label 1</span>
                        <img id="check-preview-thumb-3" alt="Back Label 1" class="hidden w-full h-full object-contain absolute inset-0" />
                    </div>
                </div>
                <div id="check-preview-slot-4" data-slot="4" data-side="back" class="flex flex-col gap-2 rounded-xl border border-[#6C757D]/20 bg-white p-2 md:p-3 transition-colors">
                    <p class="flex items-center gap-1.5 text-[11px] font-semibold text-[#212529]"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold border bg-[#0284C7] text-white border-[#0284C7]">BACK</span> Label 2</p>
                    <span id="check-preview-status-4" role="status" aria-live="polite" class="hidden items-center justify-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold truncate"></span>
                    <div class="aspect-[16/10] min-h-[120px] sm:min-h-[140px] lg:min-h-[150px] overflow-hidden flex items-center justify-center relative rounded-lg bg-[#F8F9FA] border border-[#6C757D]/10">
                        <span class="text-[11px] text-[#6C757D]/70">Back · Label 2</span>
                        <img id="check-preview-thumb-4" alt="Back Label 2" class="hidden w-full h-full object-contain absolute inset-0" />
                    </div>
                </div>
                {{-- Slot 5 hidden compat --}}
                <img id="check-preview-thumb-5" alt="Slot 5" class="hidden" />
            </div>

            {{-- Tombol Retake & Process (inert) --}}
            <div class="mt-6 flex flex-col sm:flex-row gap-3">
                <button id="check-retake-btn" type="button" disabled title="Coming soon — Retake akan reset Live Scan"
                        class="flex-1 px-5 py-3 rounded-xl border border-[#6C757D]/40 bg-white text-[#212529] text-sm font-semibold transition hover:bg-[#E9ECEF] disabled:opacity-50 disabled:cursor-not-allowed">
                    <span class="inline-flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Retake
                    </span>
                </button>
                <button id="check-process-btn" type="button" disabled title="Process akan kirim 1 label ke OCR"
                        class="flex-1 px-5 py-3 rounded-xl bg-sky-600 text-white text-sm font-semibold shadow-sm transition hover:bg-sky-700 disabled:opacity-50 disabled:cursor-not-allowed">
                    <span id="check-process-spinner" class="hidden w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin inline-block align-middle mr-2"></span>
                    <span id="check-process-text">Process</span>
                </button>
            </div>
            <p class="mt-2 text-center text-[11px] text-[#6C757D]">Process → OCR 4 label (Front 1·2 + Back 1·2) — hasil di console log (pail)</p>
        </div>

        {{-- Process overlay — popup blur saat Process → auto-direct ke Process Result --}}
        <div id="check-process-overlay" class="hidden fixed inset-0 z-50 items-center justify-center bg-[#212529]/40 backdrop-blur-sm p-4" role="dialog" aria-modal="true" aria-labelledby="check-process-overlay-title">
            <div class="w-full max-w-sm rounded-2xl border border-[#6C757D]/20 bg-white p-6 text-center shadow-lg">
                <span aria-hidden="true" class="mx-auto block w-10 h-10 rounded-full border-[3px] border-[#6C757D]/30 border-t-[#0284C7] animate-spin"></span>
                <p id="check-process-overlay-title" class="mt-4 text-sm font-bold text-[#212529]">Memproses 4 label…</p>
                <p class="mt-1 text-xs text-[#6C757D]">Front 1·2 + Back 1·2 — menyiapkan Process Result</p>
            </div>
        </div>
    </div>
</main>
@endsection

@push('scripts')
<style>#check-dual-front-mini,#check-front-mini-canvas{display:none!important;visibility:hidden!important;pointer-events:none!important;}</style>
<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
<script src="{{ asset('js/check.js') }}?v={{ filemtime(public_path('js/check.js')) }}"></script>
@endpush
