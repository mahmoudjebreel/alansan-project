@props([
    'class' => '',
])

<form {{ $attributes->class([$class]) }}>
    {{ $slot }}
</form>
