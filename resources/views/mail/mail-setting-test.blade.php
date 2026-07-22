@php $clubSetting = \App\Models\ClubSetting::current(); @endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test de configuration mail</title>
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
                            <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
                                Ceci est un mail de test envoyé depuis les paramètres mail de l'administration {{ $clubSetting->name }}.
                            </p>
                            <p style="margin:0; font-size:15px; line-height:1.6;">
                                Si vous le recevez, la configuration fonctionne.
                            </p>
                        </td>
                    </tr>
                    <x-mail.footer :club-setting="$clubSetting" />
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
