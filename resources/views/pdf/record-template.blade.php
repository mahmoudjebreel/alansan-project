{{--
    The one PDF layout every module reports through.

    A record prints as a block: the module title once at the top of the file,
    then per record a grey name bar, a two-column label/value grid of that
    module's own fields, and - only for the modules that actually keep them -
    a numbered table of their repeated sessions or visits.

    The stylesheet and the record block are separate partials, which is what
    lets PdfExport hand mPDF the report a record at a time. This file is the
    whole document in one string, which is what PdfExport::render() returns for
    tests and for anything that wants to look at the markup.

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
    @include('pdf.record-styles')
</head>
<body>
    <h1>{{ $title }}</h1>

    @forelse ($records as $record)
        @include('pdf.record-block', ['record' => $record])
    @empty
        <p class="empty">{{ $empty }}</p>
    @endforelse
</body>
</html>
