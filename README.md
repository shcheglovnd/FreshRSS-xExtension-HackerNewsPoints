# Hacker News Points — FreshRSS extension

Follow the stories on [Hacker News](https://news.ycombinator.com) that reach a number of points, 500 by default,
in [FreshRSS](https://freshrss.org), dated when they reach it rather than when they were posted.

## Why

* A story reaches a threshold hours after it was posted, often a day or more. A feed of the stories over 500 points
  from [hnrss.org](https://hnrss.org) received 17 stories between 30 September and 3 October 2026. In the median they
  had been posted 21 hours earlier, and one 46 hours earlier.
* Such feeds date a story when it was posted, as Hacker News does. So in a reader sorted by date, a story that just
  reached the threshold lands a day or two back, below the stories already read, and is easily missed.
* No API says when a story reached a number of points. A feed has to watch the stories and note when each reaches it.

## What it does

* **Subscribe to `https://news.ycombinator.com/`.** The feed lists the stories of the last 7 days that have at least
  the minimum of points. The minimum and the dates are settings, and a feed can have its own (see below).
* **Stories arrive when they reach the minimum, and are dated then**, at the refresh that first lists them, so they
  come first in a reader sorted by date. On the feed’s first refresh, the stories already over the minimum are dated
  when they were posted instead, rather than all at once. Or, as a setting, all stories are dated when they were
  posted, as on Hacker News.
* **Each story shows** its points, a link to its discussion with the number of comments, the site, and when it was
  posted, in your time zone; and the text of an Ask HN or other text post. The entry links to the article, or to the
  discussion for a text post. The author is the entry’s author.
* **Points and comments stay current** while the story is in the last 7 days. FreshRSS updates the entry, which keeps
  its date and its read state.
* An entry’s ID is the story’s discussion, as on hnrss.org, so a feed from there can be switched to this one without
  getting its stories twice.

The stories come from the [HN Search API by Algolia](https://hn.algolia.com/api), which hnrss.org uses too, and whose
points follow Hacker News’s within minutes:
`https://hn.algolia.com/api/v1/search_by_date?tags=story&numericFilters=points>=500,created_at_i>=…&hitsPerPage=1000`.
A refresh takes one request, for at most 1000 stories: 31 stories had 500 points or more in the 7 days to 3 October
2026, and 253 had 100. FreshRSS keeps the answer for 13 minutes. A story is dated within a refresh interval of reaching
the minimum, an hour by default in FreshRSS, which a feed’s settings can shorten.

## Settings

Under *Settings → Extensions → Hacker News Points*:

* **Minimum points**, 500 by default. In the 7 days to 3 October 2026, 31 stories had 500 points or more, about 4 a
  day, 115 had 250 and 253 had 100. When you raise it, the next refresh deletes the stories of the feed that are below
  it, except starred ones and ones with labels. When you lower it, the stories now over it arrive together, as new
  ones.
* **Date of a story**: when it reaches the minimum (the default), or when it was posted. Either way, a story’s text
  gives when it was posted. A change applies to the stories that arrive after it; the others keep their dates.
* **Points at the start of the titles**, off by default: “▲529 · Apple Pass Designer”. At a feed’s next download,
  FreshRSS updates the titles of the stories of the last 7 days, keeping their dates and read state, and then as their
  points change. Older stories keep the title they have, as do all stories when you switch it off.

A refresh applies changed settings at once: the extension drops SimplePie’s cached copy of the feed, which would
otherwise answer refreshes for 13 minutes. Only the API answers may be up to 13 minutes old.

A story stays in a feed once it is in, even if it later loses a few points and falls below the minimum. Only a higher
minimum removes stories, by the points they had when last listed.

These are the defaults. **A feed can have its own** in its address, so you can follow several thresholds:

```text
https://news.ycombinator.com/?min_points=500
https://news.ycombinator.com/?min_points=250&title_points=1
https://news.ycombinator.com/?min_points=1000&date=posted
```

`date` is `reached` or `posted`. `title_points` takes `1` or `0` (also `true`/`false`, `yes`/`no`, `on`/`off`).
`points` works too, as on hnrss.org. *Add a feed* with such an address; `/news` and `/best` count as the home page,
but other pages, such as `/rss` or `/newest`, are left alone. Other parameters are dropped, and out-of-range values
are brought within bounds. A story over two thresholds is in both feeds, as separate entries. A feed is named after
its minimum when added, e.g. “Hacker News (500+ points)”, and FreshRSS doesn’t rename it later.

To switch a feed from hnrss.org, e.g. `https://hnrss.org/newest?points=500`, change its address to
`https://news.ycombinator.com/?min_points=500` in its settings. Its stories of the last 7 days get this extension’s
content at the next refresh and keep their dates.

## Installation

1. Put this repository in FreshRSS’s `extensions/` folder, e.g. as
   `extensions/xExtension-HackerNewsPoints`:

   ```sh
   cd /path/to/FreshRSS/extensions
   git clone https://github.com/shcheglovnd/FreshRSS-xExtension-HackerNewsPoints.git xExtension-HackerNewsPoints
   ```

2. Enable **Hacker News Points** under *Settings → Extensions*.
3. *Add a feed* with `https://news.ycombinator.com/`, or an address with its own settings.

Requires FreshRSS 1.28 or newer.

## Development

```sh
php tests/stories.php                    # parsing, filtering and RSS, without FreshRSS or network
php tests/preview.php [500]              # what the feed would list now, from the API, for a minimum of points
php tests/preview.php 1000 --rss         # the RSS itself; add --posted for the dates of posting
```

## License

[MIT](LICENSE)
