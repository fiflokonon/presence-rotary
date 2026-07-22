@php
    $clubSetting = \App\Models\ClubSetting::current();
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; color: #12213D; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        p.subtitle { color: #6B6558; margin-top: 0; }
        h2 { font-size: 13px; margin-top: 18px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #EDEAE2; font-size: 11px; }
        .footer { margin-top: 18px; font-size: 10px; color: #6B6558; border-top: 1px solid #EDEAE2; padding-top: 8px; }
    </style>
</head>
<body>
    <h1>{{ $meetingSession->title }}</h1>
    <p class="subtitle">{{ $meetingSession->date->translatedFormat('d F Y') }} — {{ $clubSetting->name }}{{ $clubSetting->tagline ? ', '.$clubSetting->tagline : '' }}</p>

    @foreach ($groupLabels as $groupLabel)
        @php $groupAttendances = $attendances->filter(fn ($attendance) => $attendance->groupLabel === $groupLabel); @endphp
        @if ($groupAttendances->isNotEmpty())
            <h2>{{ $groupLabel }} ({{ $groupAttendances->count() }})</h2>
            <table>
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Organisation</th>
                        <th>Club</th>
                        <th>Téléphone</th>
                        <th>Présent</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groupAttendances as $attendance)
                        <tr>
                            <td>{{ $attendance->name }}</td>
                            <td>{{ $attendance->title->name }}{{ $attendance->position ? ' — '.$attendance->position->name : '' }}</td>
                            <td>{{ $attendance->club }}</td>
                            <td>{{ $attendance->phone }}</td>
                            <td>{{ $attendance->present ? 'Oui' : 'Non' }}{{ $attendance->is_late ? ' (retard)' : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    @if ($clubSetting->hasContactInfo() || $clubSetting->hasSocialLinks())
        <div class="footer">
            @if ($clubSetting->address){{ $clubSetting->address }}@endif
            @if ($clubSetting->address && ($clubSetting->phone || $clubSetting->email)) &middot; @endif
            {{ collect([$clubSetting->phone, $clubSetting->email])->filter()->join(' · ') }}
            @if ($clubSetting->hasSocialLinks())
                <br>
                {{ collect([$clubSetting->website, $clubSetting->facebook_url, $clubSetting->instagram_url])->filter()->join(' · ') }}
            @endif
        </div>
    @endif
</body>
</html>
