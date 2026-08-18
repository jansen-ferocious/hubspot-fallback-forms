# Releasing updates

Installed sites check the **`main` branch** of this repo (via the bundled
Plugin Update Checker) and compare its `Version:` header to what they have
installed. A higher version = "update available" + one-click update in
`Plugins → Installed Plugins` on every site.

## To ship an update

1. Make your code changes.
2. Bump the version in **`hubspot-fallback-forms.php`** header, e.g.
   `* Version:           1.0.1`
   (Use semantic versioning: patch for fixes, minor for features.)
3. Commit and push to `main`:
   ```bash
   git add -A
   git commit -m "1.0.1 – describe the change"
   git push
   ```

That's it. Within ~12 hours each site shows the update; admins can force an
immediate check via **Dashboard → Updates → Check again**.

## Notes

- **The version header is the trigger.** If you push without bumping it,
  nothing shows as an update.
- The repo is **public**, so no token is needed. If it's ever made private,
  define `HFF_GITHUB_TOKEN` in each site's `wp-config.php` (a GitHub token
  with `repo` read access) — the plugin picks it up automatically.
- The bundled `plugin-update-checker/` directory (including its `vendor/`
  folder) must stay committed — it is the update mechanism.
- Optional: you can also cut a tagged GitHub Release (`git tag v1.0.1 &&
  git push --tags`) for a clean history, but it is not required with the
  current branch-based setup.
