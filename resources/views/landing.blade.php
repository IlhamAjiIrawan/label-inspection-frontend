@extends('layouts.app')

@section('title', 'Label Inspection — Inspeksi Label Produk')

@section('content')
{{-- Navbar --}}
<nav class="sticky top-0 z-50 bg-[#F8F9FA]/90 backdrop-blur border-b border-[#6C757D]/20">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <a href="{{ route('home') }}" class="flex items-center gap-2">
                <img src="{{ asset('images/logo1.png') }}" alt="Label Inspection logo"
                     width="159" height="36" class="h-9 w-auto" />
            </a>

            <div class="hidden md:flex items-center gap-8 text-sm font-medium">
                <a href="#fitur" class="hover:text-sky-600 transition">Fitur</a>
                <a href="#cara-kerja" class="hover:text-sky-600 transition">Cara Kerja</a>
                <a href="#kontak" class="hover:text-sky-600 transition">Kontak</a>
            </div>

            <div class="flex items-center gap-3">
                <a href="{{ route('login') }}" class="hidden md:inline-flex items-center px-4 py-2 rounded-xl bg-sky-600 text-white text-sm font-semibold hover:bg-sky-700 transition shadow-sm">
                    Masuk
                </a>
                <button id="hamburger" class="md:hidden p-2 rounded-lg text-[#6C757D] hover:bg-[#E9ECEF]" aria-label="Menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div id="mobile-menu" class="hidden md:hidden border-t border-[#6C757D]/20 bg-[#F8F9FA]">
        <div class="px-4 py-3 space-y-3 text-sm font-medium">
            <a href="#fitur" class="block hover:text-sky-600 transition">Fitur</a>
            <a href="#cara-kerja" class="block hover:text-sky-600 transition">Cara Kerja</a>
            <a href="#kontak" class="block hover:text-sky-600 transition">Kontak</a>
            <a href="{{ route('login') }}" class="block text-center px-4 py-2 rounded-xl bg-sky-600 text-white font-semibold hover:bg-sky-700 transition">
                Masuk
            </a>
        </div>
    </div>
</nav>

{{-- Hero --}}
<header class="relative overflow-hidden">
    <div class="absolute inset-0 bg-gradient-to-br from-sky-50 via-[#F8F9FA] to-sky-50"></div>
    <div class="relative max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20 md:py-28 text-center">
        <span class="inline-block px-4 py-1.5 rounded-full bg-sky-100 text-sky-700 text-xs font-semibold uppercase tracking-wider">Platform Inspeksi Label</span>
        <h1 class="mt-6 text-3xl md:text-5xl font-bold text-[#212529] leading-tight max-w-3xl mx-auto">
            Inspeksi Label Produk, <span class="text-sky-600">Cepat dan Akurat</span>
        </h1>
        <p class="mt-5 text-base md:text-lg text-[#6C757D] max-w-2xl mx-auto">
            Pastikan setiap label produk Anda sesuai standar. Pemeriksaan otomatis, laporan realtime, dan data aman dalam satu platform.
        </p>
        <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
            <a href="{{ route('login') }}" class="w-full sm:w-auto px-6 py-3 rounded-xl bg-sky-600 text-white font-semibold hover:bg-sky-700 transition shadow-md">
                Coba Sekarang
            </a>
            <a href="#cara-kerja" class="w-full sm:w-auto px-6 py-3 rounded-xl border border-[#6C757D]/40 bg-[#E9ECEF] text-[#212529] font-semibold hover:border-sky-400 hover:text-sky-600 transition">
                Pelajari Lebih Lanjut
            </a>
        </div>
    </div>
</header>

{{-- Fitur --}}
<section id="fitur" class="py-16 md:py-20">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center max-w-2xl mx-auto">
            <h2 class="text-2xl md:text-3xl font-bold text-[#212529]">Fitur Unggulan</h2>
            <p class="mt-3 text-[#6C757D]">Semua yang Anda butuhkan untuk memastikan label produk Anda sempurna.</p>
        </div>
        <div class="mt-12 grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="p-6 rounded-2xl bg-[#E9ECEF] border border-[#6C757D]/30 shadow-sm">
                <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-[#F8F9FA] text-[#6C757D] border border-[#6C757D]/20">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </span>
                <h3 class="mt-4 text-lg font-semibold text-[#212529]">Pemeriksaan Otomatis</h3>
                <p class="mt-2 text-sm text-[#6C757D]">Deteksi kesalahan pada label secara otomatis dan konsisten, tanpa terlewat satu detail pun.</p>
            </div>
            <div class="p-6 rounded-2xl bg-[#E9ECEF] border border-[#6C757D]/30 shadow-sm">
                <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-[#F8F9FA] text-[#6C757D] border border-[#6C757D]/20">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                </span>
                <h3 class="mt-4 text-lg font-semibold text-[#212529]">Laporan Realtime</h3>
                <p class="mt-2 text-sm text-[#6C757D]">Hasil inspeksi tersedia secara realtime dengan visualisasi yang mudah dibaca.</p>
            </div>
            <div class="p-6 rounded-2xl bg-[#E9ECEF] border border-[#6C757D]/30 shadow-sm">
                <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-[#F8F9FA] text-[#6C757D] border border-[#6C757D]/20">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </span>
                <h3 class="mt-4 text-lg font-semibold text-[#212529]">Data Aman & Terenkripsi</h3>
                <p class="mt-2 text-sm text-[#6C757D]">Login aman dengan token berlapis dan data tersimpan terenkripsi sesuai standar.</p>
            </div>
        </div>
    </div>
</section>

{{-- Cara Kerja --}}
<section id="cara-kerja" class="py-16 md:py-20 bg-[#E9ECEF] border-y border-[#6C757D]/20">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center max-w-2xl mx-auto">
            <h2 class="text-2xl md:text-3xl font-bold text-[#212529]">Cara Kerja</h2>
            <p class="mt-3 text-[#6C757D]">Mulai dalam tiga langkah sederhana.</p>
        </div>
        <div class="mt-12 grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="text-center p-6">
                <span class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-sky-600 text-white font-bold text-lg">1</span>
                <h3 class="mt-4 text-lg font-semibold text-[#212529]">Login ke Akun</h3>
                <p class="mt-2 text-sm text-[#6C757D]">Masuk dengan username dan password Anda secara aman.</p>
            </div>
            <div class="text-center p-6">
                <span class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-sky-600 text-white font-bold text-lg">2</span>
                <h3 class="mt-4 text-lg font-semibold text-[#212529]">Upload Label</h3>
                <p class="mt-2 text-sm text-[#6C757D]">Unggah label produk yang ingin Anda periksa.</p>
            </div>
            <div class="text-center p-6">
                <span class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-sky-600 text-white font-bold text-lg">3</span>
                <h3 class="mt-4 text-lg font-semibold text-[#212529]">Lihat Hasil Inspeksi</h3>
                <p class="mt-2 text-sm text-[#6C757D]">Dapatkan hasil pemeriksaan lengkap beserta rekomendasinya.</p>
            </div>
        </div>
    </div>
</section>

{{-- CTA --}}
<section id="kontak" class="py-16 md:py-20 scroll-mt-20">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="rounded-3xl bg-gradient-to-br from-sky-600 to-sky-800 px-6 py-12 md:p-14 text-center text-white shadow-lg">
            <h2 class="text-2xl md:text-3xl font-bold">Siap Memastikan Label Anda Benar?</h2>
            <p class="mt-3 text-sky-100">Gabung sekarang dan mulai inspeksi label produk Anda hari ini.</p>
            <a href="{{ route('login') }}" class="mt-8 inline-flex px-8 py-3 rounded-xl bg-white text-sky-700 font-semibold hover:bg-sky-50 transition shadow-md">
                Masuk Sekarang
            </a>
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script>
    const hamburger = document.getElementById('hamburger');
    const mobileMenu = document.getElementById('mobile-menu');
    hamburger.addEventListener('click', () => {
        mobileMenu.classList.toggle('hidden');
    });
</script>
@endpush
