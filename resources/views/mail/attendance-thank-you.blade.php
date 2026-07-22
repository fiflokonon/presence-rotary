@php $clubSetting = \App\Models\ClubSetting::current(); @endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Merci pour votre présence</title>
</head>
<body style="margin:0; padding:0; background-color:#F5F3EE; font-family: Arial, Helvetica, sans-serif; color:#12213D;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F3EE; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:12px; overflow:hidden;">
                    <tr>
                        <x-mail.header :club-setting="$clubSetting" />
                    </tr>
                    <tr>
                        <td style="padding:32px 24px;">
                            <p style="margin:0 0 16px; font-size:16px;">Bonjour {{ $attendance->name }},</p>
                            <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
                                Merci pour votre présence à <strong>{{ $meetingSession->title }}</strong>
                                du {{ $meetingSession->date->translatedFormat('d F Y') }}.
                            </p>
                            <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
                                Au plaisir de vous revoir lors de notre prochaine réunion !
                            </p>
                            @if ($nextSessionDate)
                                <p style="margin:0 0 16px; font-size:15px; line-height:1.6; padding:12px 16px; background-color:#F5F3EE; border-radius:8px;">
                                    @if ($nextSessionTitle)
                                        Notre prochaine séance, <strong>{{ $nextSessionTitle }}</strong>, aura lieu le
                                        {{ $nextSessionDate->translatedFormat('d F Y') }}.
                                    @else
                                        Notre prochaine séance aura lieu le {{ $nextSessionDate->translatedFormat('d F Y') }}.
                                    @endif
                                </p>
                            @endif
                            <p style="margin:24px 0 0; font-size:15px;">À bientôt,<br>{{ $clubSetting->name }}</p>
                        </td>
                    </tr>
                    <x-mail.footer :club-setting="$clubSetting" />
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
