<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 16px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; }
        h1 { font-size: 14px; margin: 0 0 12px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #555; padding: 4px; text-align: left; vertical-align: top; }
        th { background: #e5e7eb; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <table>
        <thead>
            <tr>
                @foreach ($headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $value)
                        <td>{{ $value }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
