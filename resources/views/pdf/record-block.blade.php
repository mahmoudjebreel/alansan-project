{{--
    One record's block: the grey name bar, the two-column field grid, and - for
    the modules that keep them - the numbered table of repeated sessions.

    Its own file so a large export can be written to mPDF one record at a time.
    Handing mPDF the whole document as a single string made WriteHTML() fail
    outright once the report grew past PCRE's backtrack limit, and the size at
    which that happened depended on the server's php.ini rather than on
    anything the report could see.

    @param array $record ['title' => .., 'pairs' => [..], 'section' => null|[..]]
--}}
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
