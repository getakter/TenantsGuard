{{-- Framework and Zero Trust tags for one template. Params: $t (template), $full (bool: show references) --}}
<div class="fw-tags">
    @foreach($t['zt'] ?? [] as $pillar)
        <span class="fw fw-zt" title="Zero Trust pillar">ZT · {{ $pillar }}</span>
    @endforeach
    @foreach($t['frameworks'] ?? [] as $fw => $ref)
        <span class="fw fw-{{ $fw }}" title="{{ \App\Services\Intune\IntuneCatalog::FRAMEWORKS[$fw] ?? $fw }}: {{ $ref }}">
            {{ ['nist80053' => 'NIST 800-53', 'nistcsf' => 'NIST CSF', 'cis' => 'CIS', 'cisv8' => 'CIS v8', 'hipaa' => 'HIPAA', 'iso27001' => 'ISO 27001', 'pci' => 'PCI DSS', 'e8' => 'E8', 'ce' => 'Cyber Essentials'][$fw] ?? $fw }}@if($full ?? false)<small> {{ $ref }}</small>@endif
        </span>
    @endforeach
    @foreach(\App\Services\Compliance\FrameworkRegistry::requirementsForControl($t['key']) as $x)
        @if(in_array($x['framework'], ['e8', 'asd_ism'], true))
            <span class="fw fw-{{ $x['framework'] === 'e8' ? 'e8' : 'asd_ism' }}" title="{{ $x['requirement']['title'] }}">{{ $x['framework'] === 'e8' ? 'E8 ML'.$x['requirement']['level'] : 'ASD ISM' }}@if($full ?? false)<small> {{ $x['requirement']['id'] }}</small>@endif</span>
        @endif
    @endforeach
</div>
