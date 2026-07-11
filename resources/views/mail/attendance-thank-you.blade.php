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
                        <td style="background-color:#12213D; padding:24px; text-align:center;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto; background-color:#ffffff; border-radius:12px;">
                                <tr>
                                    <td style="padding:8px 16px;">
                                        <img src="{{ asset('assets/rotary-nexus-logo.png') }}" alt="RC Cotonou Nexus" width="180" style="display:block; height:auto; width:180px;">
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:16px 0 0; color:#ffffff; font-size:16px; font-weight:bold;">RC Cotonou Nexus</p>
                            <p style="margin:4px 0 0; color:#F2B94D; font-size:11px; letter-spacing:0.05em; text-transform:uppercase;">District 9103</p>
                        </td>
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
                            <p style="margin:24px 0 0; font-size:15px;">À bientôt,<br>RC Cotonou Nexus</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
