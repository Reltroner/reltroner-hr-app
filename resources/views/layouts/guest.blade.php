<!DOCTYPE html>
{{-- resources/views/layouts/guest.blade.php --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    <title>{{ $title ?? 'Reltroner HRM' }}</title>
    <meta name="description" content="Reltroner HRM — Laravel 12 Human Resource Manager. Built with Blade, Tailwind, Vite. Part of Reltroner Studio's digital infrastructure." />
    <meta name="keywords" content="Reltroner, HRM, Laravel, Tailwind, HR" />
    <meta name="author" content="Rei Reltroner / Reltroner Studio" />

    <meta property="og:title" content="{{ $title ?? 'Reltroner HRM' }}" />
    <meta property="og:description" content="Reltroner HRM — Laravel 12 Human Resource Management application." />
    <meta property="og:image" content="{{ asset('images/room-background.webp') }}" />
    <meta property="og:url" content="{{ url()->current() }}" />
    <meta property="og:type" content="website" />

    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    <link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
      html { font-family: 'Figtree', system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; }
    </style>
</head>

<body class="bg-gray-50 text-gray-900 antialiased min-h-screen flex flex-col relative">

    {{-- Floating top-right buttons --}}
    <div class="absolute top-4 right-4 flex items-center gap-3 z-50">
        <a href="https://github.com/Reltroner/reltroner-hr-app" target="_blank"
            class="p-2 rounded-md border bg-white hover:bg-gray-100 shadow-sm">
            <svg class="w-5 h-5 text-gray-700" viewBox="0 0 16 16" fill="currentColor">
                <path fill-rule="evenodd"
                    d="M8 0C3.58 0 0 3.58 0 8a8 8 0 005.47 7.59c.4.07.55-.17.55-.38 
                       0-.19-.01-.82-.01-1.49-2 .37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13
                       -.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 
                       1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07C3.56 11.8 
                       1.7 11.11 1.7 8.05c0-.87.31-1.58.82-2.14-.08-.2-.36-1.01.08-2.1 
                       0 0 .67-.22 2.2.82.64-.18 1.32-.27 2-.27.68 0 
                       1.36.09 2.01.27 1.53-1.04 2.2-.82 
                       2.2-.82.44 1.09.16 1.9.08 2.1.51.56.82 1.27.82 
                       2.14 0 3.06-1.87 3.75-3.65 3.95.29.25.54.74.54 
                       1.49 0 1.08-.01 1.95-.01 2.22 0 .21.15.46.55.38A8 
                       8 0 0016 8c0-4.42-3.58-8-8-8z" />
            </svg>
        </a>

        <button id="dark-toggle"
            class="p-2 rounded-md ring-1 ring-gray-200 bg-white hover:bg-gray-100 shadow-sm">
            <svg id="icon-sun" class="w-5 h-5 text-gray-700" viewBox="0 0 24 24" fill="none">
                <path d="M12 18a6 6 0 100-12 6 6 0 000 12z"
                      stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
            </svg>
        </button>
    </div>

    <main class="flex-1 flex items-start justify-center py-14 px-4 sm:px-6 lg:px-8">
        <div class="w-full max-w-2xl">

            {{-- Brand block ABOVE login form --}}
            <div class="text-center mb-6 mt-10">
                <img src="{{ asset('images/logo.png') }}"
                     alt="Reltroner HRM Logo" class="h-20 mx-auto mb-3" />
                <h1 class="text-2xl font-semibold text-indigo-700">Reltroner HRM</h1>
                <p class="text-sm text-gray-600">Human Resource Manager • Laravel 12</p>
            </div>

            {{-- Login Card / Main Slot --}}
            <div class="bg-white shadow rounded-lg p-6">
                {{ $slot }}
            </div>

            {{-- ABOUT CARD --}}
            <section class="mt-6">
                <div class="bg-white rounded-lg shadow p-5 space-y-4">
                    <h3 class="text-lg font-semibold text-gray-800">About Reltroner HRM</h3>

                    <p class="text-sm text-gray-600">
                        Reltroner HRM is a Laravel 12-based human resource management application
                        (Employee CRUD, Departments, Tasks, Attendance, Payroll, Leaves).
                        Part of Reltroner Studio’s digital worldbuilding & product ecosystem.
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="p-3 border rounded">
                            <div class="text-xs text-gray-500">License</div>
                            <div class="font-medium">MIT</div>
                        </div>
                        <div class="p-3 border rounded">
                            <div class="text-xs text-gray-500">Latest on repo</div>
                            <div class="font-medium">Public • master branch</div>
                        </div>
                        <div class="p-3 border rounded">
                            <div class="text-xs text-gray-500">Live demo</div>
                            <a href="https://hrm.reltroner.com"
                               class="text-indigo-600 underline">hrm.reltroner.com</a>
                        </div>
                    </div>

                    <div class="pt-1 border-t flex items-center justify-between text-sm text-gray-600">
                        Demo accounts:
                        <span class="font-medium">admin@example.com / developer@example.com</span>
                        <span>(password: <strong>password</strong>)</span>

                        <a href="https://github.com/Reltroner/reltroner-hr-app/blob/master/README.md"
                           target="_blank"
                           class="text-xs px-3 py-1 rounded border hover:bg-gray-50 ml-3">
                           View full README
                        </a>
                    </div>

                    <div class="text-xs text-gray-500">
                        NOTE: Refer to README for setup, installation, and module list.
                    </div>

                </div>
            </section>

        </div>
    </main>

    <footer class="bg-white border-t mt-6 w-full">
        <div
            class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex flex-col sm:flex-row 
                   items-start sm:items-center justify-between gap-4">
            <div class="text-sm text-gray-600">
                © {{ date('Y') }} Reltroner Studio — Reltroner HRM. Built with Laravel • MIT License.
            </div>
            <div class="flex items-center gap-3">
                <a href="https://github.com/Reltroner/reltroner-hr-app" class="hover:underline text-sm">GitHub</a>
                <a href="https://hrm.reltroner.com" class="hover:underline text-sm">Live Demo</a>
                <a href="https://reltroner.com" class="hover:underline text-sm">Reltroner Studio</a>
            </div>
        </div>
    </footer>

    <script>
      (function () {
        const root = document.documentElement;
        const stored = localStorage.getItem('reltroner-theme');
        if (stored === 'dark') root.classList.add('dark');

        document.getElementById('dark-toggle').addEventListener('click', () => {
          root.classList.toggle('dark');
          localStorage.setItem('reltroner-theme',
            root.classList.contains('dark') ? 'dark' : 'light'
          );
        });
      })();
    </script>

</body>
</html>
