# Defaults

Seed data for a fresh installation.

- `recipes.json`: the sample recipes
- `images/`: their photos

On the first request, when the configured storage file (`var/state/recipes.json` or `recipes.sqlite`) does not exist
yet, the app saves these recipes into it and copies the images to `public/images/` (existing files are never
overwritten). Delete the storage file to start over with the defaults.
