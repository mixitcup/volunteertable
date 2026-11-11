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
    'booking.intro.self' => 'you are registered for:',
    'booking.intro.someone_else' => 'someone has registered you for:',
    'booking.intro.admin' => 'an organizer has registered you for:',
    'booking.responsible' => 'Responsible: {names}',
    'booking.notIntended' => "If you didn't expect this or can't make it, you can cancel with the link below.",
    'booking.linkIntro' => 'To change or cancel your registration, open this link:',
    'booking.linkInfo' => "The link shows all your registrations made with this email address under\n"
        . "\"My events\", also later ones, in every browser where you open it.\n"
        . "Anyone with this link can change your registrations, so please don't share it.",
    'booking.noFurtherMails' => "You won't get another email when you register for more events with this\n"
        . 'address: keep this email, its link always shows all of them.',

    'admin.subject' => 'Your admin link for the volunteer table',
    'admin.hello' => 'Hello {name},',
    'admin.intro' => "you are now the admin of the volunteer table. To log in as admin, also on\n"
        . 'another device or browser, open this link:',
    'admin.keepSafe' => 'Anyone with this link can manage all registrations, so please keep it safe.',
];
