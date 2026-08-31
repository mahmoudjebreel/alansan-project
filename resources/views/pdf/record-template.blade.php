{{--
    The one PDF layout every module reports through.

    A record prints as a block: the module title once at the top of the file,
    then per record a grey name bar, a two-column label/value grid of that
    module's own fields, and - only for the modules that actually keep them -
    a numbered table of their repeated sessions or visits.

    Callers pass:
      $title    module title (string)
      $records  list of ['title' => name bar text,
                         'pairs' => [['label' => .., 'value' => ..], ..],
                         'section' => null | ['title' => .., 'columns' => [..],
                                              'rows' => [[..], ..], 'empty' => ..]]
      $empty    text shown when the export matched no records
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; }
        h1 { font-size: 15px; margin: 0 0 14px; }
        h2 { font-size: 11px; margin: 0 0 6px; padding: 5px 6px; background: #e5e7eb; }
        h3 { font-size: 10px; margin: 10px 0 4px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #555; padding: 4px 5px; text-align: start; vertical-align: top; }
        th { background: #f3f4f6; font-weight: bold; }
        /* Each record is one block; keep it whole on a page where it fits. */
        .record { margin-bottom: 14px; page-break-inside: avoid; }
        .label { width: 26%; background: #fafafa; font-weight: bold; }
        .session-no { width: 8%; text-align: center; }
        .session-date { width: 18%; }
        .empty { color: #9ca3af; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>

    @forelse ($records as $record)
        <div class="record">
            <h2>{{ $record['title'] }}</h2>

            <table>
                @foreach (array_chunk($record['pairs'], 2) as $pair)
                    <tr>
                        @foreach ($pair as $cell)
                            <td class="label">{{ $cell['label'] }}</td>
                            <td>{{ $cell['value'] }}</td>
                        @endforeach

                        {{-- An odd number of fields leaves the last row half full. --}}
                        @if (count($pair) === 1)
                            <td class="label"></td>
                            <td></td>
                        @endif
                    </tr>
                @endforeach
            </table>

            {{-- Only the modules that keep repeated sessions or visits pass a
                 section; the flat ones never show an empty heading. --}}
            @if (($record['section'] ?? null) !== null)
                <h3>{{ $record['section']['title'] }}</h3>

                @if (count($record['section']['rows']) > 0)
                    <table>
                        <thead>
                            <tr>
                                <th class="session-no">#</th>
                                @foreach ($record['section']['columns'] as $i => $column)
                                    <th @class(['session-date' => $i === 0])>{{ $column }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            {{-- A field the session never carried prints as an empty
                                 cell, never as "null" or a stand-in date. --}}
                            @foreach ($record['section']['rows'] as $index => $row)
                                <tr>
                                    <td class="session-no">{{ $index + 1 }}</td>
                                    @foreach ($row as $value)
                                        <td>{{ $value }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="empty">{{ $record['section']['empty'] }}</p>
                @endif
            @endif
        </div>
    @empty
        <p class="empty">{{ $empty }}</p>
    @endforelse
</body>
</html>
