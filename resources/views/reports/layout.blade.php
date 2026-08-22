{{-- Print stylesheet for the admin PDF exports. Colours match the Spring reports. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 26px 26px 30px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9pt; color: #111; }
        h1 { font-size: 18pt; color: #2b6cb0; margin: 0 0 4px; }
        h2 { font-size: 12pt; margin: 14px 0 6px; }
        .generated { font-size: 9pt; color: #777; margin: 0 0 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th { background: #2b6cb0; color: #fff; font-size: 10pt; text-align: left; padding: 6px; }
        td { padding: 5px 6px; border-bottom: 1px solid #dcdcdc; vertical-align: top; }
        tr { page-break-inside: avoid; }
        .empty { color: #777; font-style: italic; padding: 10px 0; }
    </style>
</head>
<body>
<h1>{{ $title }}</h1>
<p class="generated">ScholarZim &bull; Generated {{ now()->format('d M Y H:i') }}</p>

@yield('body')
</body>
</html>
