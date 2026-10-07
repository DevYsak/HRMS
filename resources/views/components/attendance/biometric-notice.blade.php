{{-- How to read a punch, as a slim scrolling notice at the top of attendance
     screens. Pauses on hover/focus and stands still for reduced motion. --}}
@php
    $items = [
        ['icon' => 'face-smile', 'text' => 'Face = Biometric IN'],
        ['icon' => 'identification', 'text' => 'ID Card = Biometric OUT'],
        ['icon' => 'pencil-square', 'text' => 'Regularised punches are manually corrected attendance entries'],
    ];
@endphp

@once
    <style>
        .bio-notice { position: relative; display: flex; align-items: center; gap: .5rem; overflow: hidden; border-radius: .75rem; border: 1px solid #fed7aa; background: #fff7ed; padding: .3rem .6rem; font-size: 11px; line-height: 1.2; color: #9a3412; }
        .dark .bio-notice { border-color: rgb(249 115 22 / .25); background: rgb(249 115 22 / .08); color: #fdba74; }
        .bio-notice-tag { flex-shrink: 0; display: inline-flex; align-items: center; gap: .25rem; border-radius: 9999px; background: #f97316; padding: .1rem .5rem; font-size: 9px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: #fff; }
        .bio-notice-viewport { position: relative; flex: 1; min-width: 0; overflow: hidden; mask-image: linear-gradient(90deg, transparent, #000 4%, #000 96%, transparent); }
        .bio-notice-track { display: flex; width: max-content; animation: bio-notice-scroll 32s linear infinite; }
        .bio-notice:hover .bio-notice-track, .bio-notice:focus-within .bio-notice-track { animation-play-state: paused; }
        .bio-notice-group { display: flex; flex-shrink: 0; align-items: center; gap: 1.75rem; padding-right: 1.75rem; white-space: nowrap; font-weight: 600; }
        .bio-notice-item { display: inline-flex; align-items: center; gap: .3rem; }
        @keyframes bio-notice-scroll { from { transform: translateX(0); } to { transform: translateX(-50%); } }
        @media (prefers-reduced-motion: reduce) {
            .bio-notice-track { animation: none; width: auto; }
            .bio-notice-group { flex-wrap: wrap; gap: .25rem 1rem; white-space: normal; }
            .bio-notice-group[aria-hidden="true"] { display: none; }
            .bio-notice-viewport { mask-image: none; }
        }
    </style>
@endonce

<div {{ $attributes->merge(['class' => 'bio-notice']) }} role="note" aria-label="Face = Biometric IN. ID Card = Biometric OUT. Regularised punches are manually corrected attendance entries." data-biometric-notice>
    <span class="bio-notice-tag"><flux:icon.information-circle class="size-3" /> Punch guide</span>
    <div class="bio-notice-viewport">
        <div class="bio-notice-track">
            @foreach ([false, true] as $copy)
                <div class="bio-notice-group" @if($copy) aria-hidden="true" @endif>
                    @foreach ($items as $item)
                        <span class="bio-notice-item"><flux:icon :icon="$item['icon']" class="size-3.5" /> {{ $item['text'] }}</span>
                        <span aria-hidden="true" class="opacity-40">|</span>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
</div>
