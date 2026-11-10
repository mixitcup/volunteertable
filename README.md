VolunteerTable
===

There are a few volunteer web tools, but we wanted something that is both very easy to use and has a timeline view and the option to provide details to events. This tool fulfills that.

It can also be used as a booking tool for other events.

## Setup

The project has two folders:

- `public/` – the web page and API. Only this folder should be reachable from the web.
- `private/` – code, configuration, example data and (with SQLite) the database.

To deploy, symlink `public/` into your web server's folder and keep `private/` next to the real `public/` folder:

```sh
ln -s /path/to/volunteertable/public /var/www/html/volunteers
```

The API finds `private/` through the symlink. If you copy `public/` instead, set the environment variable `VOLUNTEERTABLE_PRIVATE_DIR` to the `private/` folder.

Copy `private/config.example.php` to `private/config.php` and adjust the database, `base_url` and mail settings. Requires PHP 7.4 or newer with PDO.

For local testing:

```sh
php -S localhost:8000 -t public
```

Then open http://localhost:8000/index.htm. The first visitor becomes admin and gets the invitation link for volunteers on the Admin tab.

## Thanks

This tool is initially based on https://github.com/Grible/timetable.js and extended using GitHub Copilot and Claude Code.