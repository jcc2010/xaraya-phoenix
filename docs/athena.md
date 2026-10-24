# Mirroring an Athena blog, and moving off Athena

Phoenix serves the same JSON Feed as Athena, including the `_athenana` extensions, at
`/blog/<handle>/feed.json`, `/blog/<handle>/feed.xml` and `/s/<id>.json`. A site that reads an Athena blog can switch
to Phoenix by changing only the host. Moving over happens in two steps: mirror first, then cut over when you're
ready.

## 1. Mirror

```sh
bin/xar module:enable blog
bin/xar blog:create wyome --mode=mirror --format=athena \
  --source=https://athenana.com/blog/wyome/feed.json \
  --post-url='https://www.wyome.com/blog/post.html?id={id}'
bin/xar blog:sync wyome --full
```

How a mirror behaves:

- **It's read-only.** In the admin, posts and tags can't be edited, and a sync would overwrite anything changed by
  hand. The admin's **Sync now** button runs a quick sync.
- **Ids are kept.** Every post keeps its Athena id, so `/s/{id}` links, feed ids and readers' read state all carry
  over.
- **Keep it fresh with cron.** A quick sync stops at the first page with no changes. A full sync also catches edits to
  old posts and deletions:

  ```
  */10 * * * * cd /srv/phoenix && timeout 20m bin/xar blog:sync --all
  15 3 * * *   cd /srv/phoenix && timeout 2h bin/xar blog:sync --all --full
  ```

  With Docker, run the same commands from the host's cron through
  `docker compose exec -T app runuser -u www-data -- php bin/xar …`.
- **Media can come along.** Athena refuses image requests that come from another site, so Phoenix requests every image
  with no `Referer`. To hold copies yourself, run `bin/xar media:localize wyome`, which downloads the blog's media and
  serves it from `/media/…`.

## 2. Check

Compare what Phoenix serves with Athena before anything depends on it:

- `/blog/wyome/feed.json` should list the same items as Athena's feed, with the same ids, dates and tags.
- `bin/xar blog:list` shows the live post count.
- The blog's pages are at `/blog/wyome` (home, posts, tags, search) if you want Phoenix to serve the blog itself too.

## 3. Cut over

When you stop writing in Athena:

```sh
bin/xar blog:sync wyome --full     # pick up the last changes
bin/xar blog:mode wyome native     # stop mirroring; nothing about the posts changes
bin/xar blog:owner wyome --user=<your handle> --dry-run
bin/xar blog:owner wyome --user=<your handle>
```

After the switch:

- Remove the `blog:sync` cron lines.
- Write in the admin (`/admin/blog`), through the JSON API, or from any Micropub client (see the README's *Writing
  natively* section).
- Post ids and URLs don't change, so links and feed readers carry on as before.
- Tags become editable on the blog's **Tags** screen.

`blog:mode wyome mirror --source=… --format=athena` switches back. It refuses when the blog has posts written in
Phoenix, unless you add `--force`, because the next full sync would delete them.
