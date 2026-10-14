# WPU SMTP

Gratis plugin från [wordpressutveckling.se](https://wordpressutveckling.se/). Koden förklaras rad för rad i guiden: **[https://wordpressutveckling.se/wordpress-skickar-inte-e-post/](https://wordpressutveckling.se/wordpress-skickar-inte-e-post/)**

Byggt av [Kristoffer Pihlfelt](https://pihlfelt.se) på [Pihlfelt & Vänner](https://pihlfelt.se). Licens: GPL-2.0-or-later.

- **Bidragsgivare:** Pihlfelt & Vänner
- **Kräver WordPress:** 6.7
- **Kräver PHP:** 8.2
- **Version:** 1.0.0
- **Licens:** GPL-2.0-or-later

## Vad det gör

Skickar all e-post från WordPress (formulär, ordermejl, lösenordsåterställning) via din e-postleverantörs SMTP-server i stället för webbserverns egen mejlfunktion. Det är det som gör att mejlen hamnar i inkorgen i stället för skräpposten. Samma plugin som wordpressutveckling.se använder.

* Inställningssida under Inställningar > SMTP med server, port, kryptering, användare, lösenord och avsändare.
* Knapp för testmejl, och det senaste felmeddelandet visas på sidan.
* Lösenordet sparas krypterat (libsodium) med nycklar från sajtens wp-config.php, så det syns inte i klartext i databasen eller i en databasexport.
* Gör ingenting förrän server, användare och lösenord är ifyllda.

## Låsa inställningar (valfritt)

Allt ställs in på inställningssidan. Vill du låsa ett värde, till exempel på en kundsajt, kan det sättas som konstant i wp-config.php. Konstanten går då före inställningssidan och fältet blir låst:

define( 'WPU_SMTP_HOST', 'mailcluster.loopia.se' );
define( 'WPU_SMTP_PORT', 587 );
define( 'WPU_SMTP_SECURE', 'tls' );
define( 'WPU_SMTP_USER', 'info@dindoman.se' );
define( 'WPU_SMTP_PASSWORD', 'lösenordet' );
define( 'WPU_SMTP_FROM', 'info@dindoman.se' );
define( 'WPU_SMTP_FROM_NAME', 'Ditt företag' );

## Glöm inte DNS

SMTP hjälper bara om domänens SPF-post tillåter leverantörens servrar (hos Loopia: v=spf1 include:spf.loopia.se -all) och helst DKIM. Se guiden "WordPress skickar inte e-post".

## Ändringslogg

### 1.0.0
* Första versionen.
