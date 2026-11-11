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
    'booking.intro.self' => 'du bist angemeldet für:',
    'booking.intro.someone_else' => 'jemand hat dich angemeldet für:',
    'booking.intro.admin' => 'das Orga-Team hat dich angemeldet für:',
    'booking.responsible' => 'Verantwortlich: {names}',
    'booking.notIntended' => 'Falls du damit nicht gerechnet hast oder nicht kannst, kannst du mit dem Link unten absagen.',
    'booking.linkIntro' => 'Um deine Anmeldung zu ändern oder abzusagen, öffne diesen Link:',
    'booking.linkInfo' => "Der Link zeigt unter „Meine Schichten“ alle deine Anmeldungen mit dieser\n"
        . "E-Mail-Adresse, auch spätere, in jedem Browser, in dem du ihn öffnest.\n"
        . 'Wer diesen Link hat, kann deine Anmeldungen ändern, also gib ihn bitte nicht weiter.',
    'booking.noFurtherMails' => "Wenn du dich mit dieser Adresse für weitere Veranstaltungen anmeldest, bekommst\n"
        . 'du keine weitere E-Mail: Heb diese E-Mail auf, ihr Link zeigt immer alle an.',

    'admin.subject' => 'Dein Admin-Link für die Helfer*innen-Tabelle',
    'admin.hello' => 'Hallo {name},',
    'admin.intro' => "du bist jetzt Admin der Helfer*innen-Tabelle. Um dich als Admin anzumelden,\n"
        . 'auch auf einem anderen Gerät oder in einem anderen Browser, öffne diesen Link:',
    'admin.keepSafe' => 'Wer diesen Link hat, kann alle Anmeldungen verwalten, also bewahre ihn bitte gut auf.',
];
