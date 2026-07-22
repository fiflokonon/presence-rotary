@props(['clubSetting'])
@if ($clubSetting->hasContactInfo() || $clubSetting->hasSocialLinks())
    <tr>
        <td style="padding:16px 24px; text-align:center; font-size:11px; color:#6B6558; border-top:1px solid #EDEAE2;">
            @if ($clubSetting->address)
                {{ $clubSetting->address }}<br>
            @endif
            @if ($clubSetting->phone || $clubSetting->email)
                {{ collect([$clubSetting->phone, $clubSetting->email])->filter()->join(' · ') }}
            @endif
            @if ($clubSetting->hasSocialLinks())
                <br>
                @php
                    $links = collect([
                        $clubSetting->website ? ['label' => $clubSetting->website, 'url' => $clubSetting->website] : null,
                        $clubSetting->facebook_url ? ['label' => 'Facebook', 'url' => $clubSetting->facebook_url] : null,
                        $clubSetting->instagram_url ? ['label' => 'Instagram', 'url' => $clubSetting->instagram_url] : null,
                    ])->filter();
                @endphp
                @foreach ($links as $link)
                    <a href="{{ $link['url'] }}" style="color:#6B6558;">{{ $link['label'] }}</a>@if (! $loop->last) &middot; @endif
                @endforeach
            @endif
        </td>
    </tr>
@endif
