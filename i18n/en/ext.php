<?php

return [
	'hacker_news_points' => [
		'intro' => 'Subscribe with “Add a feed” and <code>https://news.ycombinator.com/</code>. The feed lists the stories of the last 7 days that have enough points on Hacker News. A story arrives when it reaches the minimum, often a day after it was posted, and is dated then, unless you choose the date it was posted.',
		'min_points' => 'Minimum points',
		'min_points_help' => 'In the 7 days to 3 October 2026, 31 stories had 500 points or more, about 4 a day, 115 had 250 and 253 had 100. Raising it deletes the stories of a feed that are below it at the next refresh, except starred ones and ones with labels. Lowering it brings back the stories now over it, as new ones.',
		'date' => 'Date of a story',
		'dates' => [
			'reached' => 'When it reaches the minimum',
			'posted' => 'When it was posted',
		],
		'date_help' => 'When it reaches the minimum: at the refresh that first lists it, so a story that reaches the minimum a day after it was posted comes first in a reader sorted by date. The stories already over it when a feed is added are dated when they were posted. When it was posted: as on Hacker News and hnrss.org, so such a story arrives a day back. Either way, a story’s text gives when it was posted. A change applies to the stories that arrive after it.',
		'title_points' => 'Points at the start of the titles',
		'title_points_help' => 'E.g. “▲529 · Apple Pass Designer”. At the next download of a feed, FreshRSS updates the titles of the stories of the last 7 days, keeping their dates and read state, and then as the points change. Older stories keep the title they have.',
		'url_settings' => 'These are the defaults. A feed can have its own in its address, e.g. <code>https://news.ycombinator.com/?min_points=1000&amp;date=posted&amp;title_points=1</code> (<code>date</code> is <code>reached</code> or <code>posted</code>, and <code>title_points=0</code> is for none), so you can follow several thresholds. A story over two of them is in both feeds.',
		'error' => [
			'fetch' => 'Hacker News Points: could not load %s (%s)',
			'format' => 'Hacker News Points: %s did not answer with a list of stories; the HN Search API may have changed',
		],
	],
];
