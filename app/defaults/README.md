# Defaults

This directory contains application defaults and example data. Both are read
by the application; neither is meant to be edited.

## Application defaults

These files are required for the whole lifecycle of the application:

- `config.php`: the complete default configuration, read before `config/config.php`
- `accounts.json`, `roles.json`, `access.json`: the built-in accounts, roles
  and permissions

The local `config/config.php` is managed by **Administration → Configuration** and contains only the
settings that differ from `config.php`. Do not put defaults there.

## Seed data

The `seed/` directory is only used once, when the configured recipe storage
does not exist yet:

- `seed/recipes.json`: the sample recipes
- `seed/images/`: their photos

On the first request the `Seeder` saves these recipes into the new storage file
(`var/state/recipes.json` or `recipes.sqlite`) and copies the images to
`public/images/` (existing files are never overwritten). Delete the
storage file to start over with the seed data.
