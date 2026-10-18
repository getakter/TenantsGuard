{{-- Microsoft-branded popup button. Params: $href, $label, $disabled (bool), $block (bool), $title --}}
<a href="{{ $href }}" @if($popup ?? true) data-ms-popup @endif class="btn btn-ms {{ ($block ?? false) ? 'btn-block' : '' }}"
   @if($disabled ?? false) aria-disabled="true" onclick="return false" @endif
   @if(! empty($title)) title="{{ $title }}" @endif>
    <span class="ms-logo" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
    {{ $label }}
</a>
