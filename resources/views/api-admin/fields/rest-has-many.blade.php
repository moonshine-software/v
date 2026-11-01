@props([
    'component',
    'label' => '',
    'isCreatable' => false,
    'createButton' => null,
])
<div x-id="['rest-has-many']"
     :id="$id('rest-has-many')"
>
    @if($label !== '')
        <x-moonshine::heading>{{ $label }}</x-moonshine::heading>
    @endif

    @if($isCreatable && $createButton)
        <x-moonshine::layout.flex justify-align="between" items-align="start">
            {!! $createButton !!}
        </x-moonshine::layout.flex>

        <x-moonshine::layout.line-break />
    @endif

    {!! $component !!}
</div>
