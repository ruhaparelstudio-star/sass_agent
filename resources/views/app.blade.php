<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        @if (!app()->runningUnitTests())
            @vite(['resources/css/app.css', 'resources/js/app.jsx'])
        @endif
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
