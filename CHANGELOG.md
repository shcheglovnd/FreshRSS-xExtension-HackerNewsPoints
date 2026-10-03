# Changelog

## 0.1.0 — 2026-10-03

First release.

* `https://news.ycombinator.com/` as a feed of the stories on Hacker News with at least a minimum of points, 500
  by default, among the stories of the last 7 days. Read from the HN Search API by Algolia, one request per refresh.
* Stories are dated when they reach the minimum, at the refresh that first lists them; on a feed’s first refresh,
  when they were posted. Or, as a setting, always when they were posted. Updates of their points keep their date.
* Each story shows its points, a link to its discussion with the number of comments, the site, when it was posted,
  in the user’s time zone, and the text of a text post. Entry IDs are the discussions, as on hnrss.org.
* Points at the start of the titles, e.g. “▲529 · Apple Pass Designer”: a setting, off by default.
* A feed’s own settings in its address, e.g. `https://news.ycombinator.com/?min_points=1000&date=posted&title_points=1`,
  so that several feeds can follow different thresholds. The extension’s settings are the defaults.
* Raising a feed’s minimum deletes its stories below it at the next refresh, except starred ones and ones with
  labels. A story that loses a few points once in the feed stays.
* A refresh applies changed settings at once. API answers are cached for 13 minutes.
* Works on FreshRSS 1.28 and newer.
