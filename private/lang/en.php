<?php
// English texts for emails. Other languages (e.g. de.php) use the same keys;
// texts missing there fall back to these. {name} etc. are placeholders.
return [
    'locale' => [
        // Date format in emails, see vt_format_event_time().
        'days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        'months' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        'date' => '{day} {d} {month} {year}',
    ],

    'booking.subject' => 'Your registration: {event}',
    'booking.hello' => 'Hello {name},',
    'booking.intro.someone_else' => 'someone has registered you for:',
    'booking.intro.admin' => 'an organizer has registered you for:',
    'booking.responsible' => 'Responsible: {names}',
    'booking.notIntended' => "If you didn't expect this or can't make it, you can cancel with the link below.",
    'booking.linkIntro' => 'To change or cancel your registration, open this link:',
    'booking.linkInfo' => "The link shows all your registrations made with this email address under\n"
        . "\"My events\", also later ones, in every browser where you open it.\n"
        . "Anyone with this link can change your registrations, so please don't share it.",

    // The mail for registering yourself: sent once per address (and on request),
    // about the page and the personal link, with the first registration below.
    'page.defaultTitle' => 'VolunteerTable',
    'personal.subject' => 'Your personal link for {page}',
    'personal.intro' => "thanks for signing up at {page}! This is your personal link to all your\n"
        . 'registrations:',
    'personal.keep' => "Please keep this email. The link shows everything you have registered for with\n"
        . "this email address under \"My events\", including later registrations, and lets\n"
        . "you change or cancel them, in every browser where you open it. You won't get\n"
        . 'another email when you register for more events in the same browser.',
    'personal.firstRegistration' => 'Your first registration:',
    'personal.askedFor' => 'You asked for the link for:',
    // Sent when the address is used in a browser that doesn't show its other registrations yet.
    'personal.introNewBrowser' => "you just registered for \"{event}\" in a browser (or on a device) that doesn't\n"
        . "show your other registrations yet. To see and manage all your registrations\n"
        . 'with this email address there, open your personal link in that browser:',
    'personal.newRegistration' => 'Your new registration:',
    'personal.dontShare' => "Anyone with this link can change your registrations, so please don't share it.",

    'admin.subject' => 'Your admin link for the volunteer table',
    'admin.hello' => 'Hello {name},',
    'admin.intro' => "you are now the admin of the volunteer table. To log in as admin, also on\n"
        . 'another device or browser, open this link:',
    'admin.keepSafe' => 'Anyone with this link can manage all registrations, so please keep it safe.',
];
