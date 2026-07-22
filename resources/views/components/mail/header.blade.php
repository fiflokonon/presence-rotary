@props(['clubSetting'])
<td style="background-color:{{ $clubSetting->primary_color }}; padding:24px; text-align:center;">
    <img src="{{ $clubSetting->logoUrl() }}" alt="{{ $clubSetting->name }}" width="140" style="display:block; height:auto; width:140px; margin:0 auto;">
    <p style="margin:16px 0 0; color:#ffffff; font-size:16px; font-weight:bold;">{{ $clubSetting->name }}</p>
    @if ($clubSetting->tagline)
        <p style="margin:4px 0 0; color:#F2B94D; font-size:11px; letter-spacing:0.05em; text-transform:uppercase;">{{ $clubSetting->tagline }}</p>
    @endif
</td>
