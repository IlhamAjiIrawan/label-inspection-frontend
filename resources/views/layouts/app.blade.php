<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Label Inspection')</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('images/labelIns1.ico') }}">
    <link rel="shortcut icon" type="image/x-icon" href="{{ asset('images/labelIns1.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/labelIns1.ico') }}">
    {{-- Tailwind Play CDN di-vendor lokal agar tetap terender saat offline (tanpa internet publik).
         File: public/js/tailwind-cdn.js — disalin dari https://cdn.tailwindcss.com --}}
    <script src="{{ asset('js/tailwind-cdn.js') }}"></script>
</head>
<body class="font-sans antialiased bg-[#F8F9FA] text-[#6C757D]">
    @yield('content')

    {{-- Spacer anti-tertutup-footer (footer bersifat fixed/out-of-flow) --}}
    <div class="h-14" aria-hidden="true"></div>

    {{-- Footer global semua halaman --}}
    <footer class="fixed bottom-0 inset-x-0 z-40 bg-[#212529] border-t border-white/10">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <p class="py-2.5 text-xs sm:text-sm text-[#6C757D] text-center">
                &copy; 2026 Label Inspection - Operation Excellence
            </p>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>