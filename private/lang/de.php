<?php
// Deutsche Texte für E-Mails (German). Same keys as en.php; missing texts fall back to English.
return [
    'locale' => [
        'days' => ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'],
        'months' => ['Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sept.', 'Okt.', 'Nov.', 'Dez.'],
        'date' => '{day}, {d}. {month} {year}',
    ],

    'booking.subject' => 'Deine Anmeldung: {event}',
    'booking.hello' => 'Hallo {name},',
    'booking.intro.someone_else' => 'jemand hat dich angemeldet für:',
    'booking.intro.admin' => 'das Orga-Team hat dich angemeldet für:',
    'booking.responsible' => 'Verantwortlich: {names}',
    'booking.notIntended' => 'Falls du damit nicht gerechnet hast oder nicht kannst, kannst du mit dem Link unten absagen.',
    'booking.linkIntro' => 'Um deine Anmeldung zu ändern oder abzusagen, öffne diesen Link:',
    'booking.linkInfo' => "Der Link zeigt unter „Meine Schichten“ alle deine Anmeldungen mit dieser\n"
        . "E-Mail-Adresse, auch spätere, in jedem Browser, in dem du ihn öffnest.\n"
        . 'Wer diesen Link hat, kann deine Anmeldungen ändern, also gib ihn bitte nicht weiter.',

    // Die E-Mail beim eigenen Anmelden: einmal pro Adresse (und auf Wunsch), über die
    // Seite und den persönlichen Link, darunter die erste Anmeldung.
    'page.defaultTitle' => 'VolunteerTable',
    'personal.subject' => 'Dein persönlicher Link für {page}',
    'personal.intro' => "danke, dass du bei {page} mitmachst! Das ist dein persönlicher Link zu allen\n"
        . 'deinen Anmeldungen:',
    'personal.keep' => "Bitte heb diese E-Mail auf. Der Link zeigt unter „Meine Schichten“ alles, wofür\n"
        . "du dich mit dieser E-Mail-Adresse angemeldet hast, auch spätere Anmeldungen, und\n"
        . "du kannst sie dort ändern oder absagen, in jedem Browser, in dem du ihn öffnest.\n"
        . 'Für weitere Anmeldungen bekommst du keine weitere E-Mail.',
    'personal.firstRegistration' => 'Deine erste Anmeldung:',
    'personal.askedFor' => 'Du hast den Link angefordert für:',
    'personal.dontShare' => 'Wer diesen Link hat, kann deine Anmeldungen ändern, also gib ihn bitte nicht weiter.',

    'admin.subject' => 'Dein Admin-Link für die Helfer*innen-Tabelle',
    'admin.hello' => 'Hallo {name},',
    'admin.intro' => "du bist jetzt Admin der Helfer*innen-Tabelle. Um dich als Admin anzumelden,\n"
        . 'auch auf einem anderen Gerät oder in einem anderen Browser, öffne diesen Link:',
    'admin.keepSafe' => 'Wer diesen Link hat, kann alle Anmeldungen verwalten, also bewahre ihn bitte gut auf.',
];
