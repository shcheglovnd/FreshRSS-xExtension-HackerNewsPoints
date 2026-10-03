<?php

declare(strict_types=1);

/**
 * Checks of {@see HackerNewsPoints} without FreshRSS or network: php tests/stories.php
 */

require dirname(__DIR__) . '/lib/HackerNewsPoints.php';

date_default_timezone_set('UTC');
$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void {
	global $failures;
	if ($actual === $expected) {
		echo "ok   $name\n";
	} else {
		$failures++;
		echo "FAIL $name\n     expected " . var_export($expected, true) . "\n     got      " . var_export($actual, true) . "\n";
	}
}

/** @return array<string,mixed> an API hit, as the API answers with the extension’s attributesToRetrieve */
function hit(string $id, int $points, array $fields = []): array {
	return $fields + [
		'_highlightResult' => ['title' => ['value' => "Story $id", 'matchLevel' => 'none']],
		'author' => 'pg', 'created_at_i' => 1790968016, 'num_comments' => 316, 'objectID' => $id, 'points' => $points,
		'title' => "Story $id", 'url' => "https://www.example.com/posts/$id?ref=hn",
	];
}

/** @return list<array{id:string,link:string,discussion:string,title:string,author:string,html:string,points:int,date:int}> */
function read(array $hits): array {
	return array_values(array_filter(array_map(static fn(array $hit): ?array => HackerNewsPoints::story($hit), $hits)));
}

/** @return list<string> the values of an element of the items of an RSS document */
function items(string $rss, string $element): array {
	$xml = simplexml_load_string($rss);
	if (!$xml instanceof SimpleXMLElement) {
		return ['not XML'];
	}
	$values = [];
	foreach ($xml->channel->item as $item) {
		$values[] = $element === 'pubDate' ? gmdate('Y-m-d H:i:s', (int)strtotime((string)$item->pubDate))
			: ($element === 'dc:creator' ? (string)$item->children('http://purl.org/dc/elements/1.1/')->creator : (string)$item->{$element});
	}
	return $values;
}

$page = HackerNewsPoints::PAGE;
foreach ([
	'https://news.ycombinator.com/', 'https://news.ycombinator.com', 'news.ycombinator.com', 'http://news.ycombinator.com/news',
	'https://news.ycombinator.com/best', 'https://news.ycombinator.com/news?p=2', 'https://news.ycombinator.com/#top',
	' https://NEWS.ycombinator.com/ ', 'https://news.ycombinator.com/?min_points=abc&date=&title_points=maybe',
] as $url) {
	check("pageUrl($url)", HackerNewsPoints::pageUrl($url), $page);
}
foreach ([
	'https://news.ycombinator.com/rss', 'https://news.ycombinator.com/newest', 'https://news.ycombinator.com/item?id=49937276',
	'https://news.ycombinator.com/front', 'https://news.ycombinator.com/bestcomments', 'https://hnrss.org/newest?points=500',
	'https://news.ycombinator.com.example.com/', 'https://ycombinator.com/', 'https://www.ycombinator.com/', 'https://example.com/?min_points=5',
] as $url) {
	check("pageUrl($url) is not ours", HackerNewsPoints::pageUrl($url), null);
}
foreach ([
	'https://news.ycombinator.com/?min_points=500' => "$page?min_points=500",
	'news.ycombinator.com/best?points=0300' => "$page?min_points=300",
	'https://news.ycombinator.com/?points=100&min_points=500' => "$page?min_points=500",
	'https://news.ycombinator.com/?title_points=yes&date=POSTED&min_points=1000' => "$page?min_points=1000&date=posted&title_points=1",
	'https://news.ycombinator.com/?date=reached' => "$page?date=reached",
	'https://news.ycombinator.com/?date=both' => $page,
	'https://news.ycombinator.com/?min_points=0' => "$page?min_points=0",
	'https://news.ycombinator.com/?min_points=99999999999999999999' => "$page?min_points=100000",
	'https://news.ycombinator.com/?min_points=-5&date[]=posted' => $page,
	'https://news.ycombinator.com/?title_points=OFF&min_points=50' => "$page?min_points=50&title_points=0",
] as $url => $expected) {
	check("pageUrl($url)", HackerNewsPoints::pageUrl($url), $expected);
}
check('urlSettings: none', HackerNewsPoints::urlSettings($page), ['min_points' => null, 'date' => null, 'title_points' => null]);
check('urlSettings: all', HackerNewsPoints::urlSettings("$page?title_points=1&date=posted&min_points=250"),
	['min_points' => 250, 'date' => 'posted', 'title_points' => true]);
check('urlSettings: no points in titles', HackerNewsPoints::urlSettings("$page?title_points=0")['title_points'], false);

foreach (['reached' => 'reached', 'Posted' => 'posted', ' POSTED ' => 'posted', '' => null, 'both' => null, 'post' => null] as $value => $expected) {
	check("date($value)", HackerNewsPoints::date((string)$value), $expected);
}
check('boundMinPoints', [HackerNewsPoints::boundMinPoints(-1), HackerNewsPoints::boundMinPoints(500), HackerNewsPoints::boundMinPoints(PHP_INT_MAX)],
	[0, 500, 100000]);

$now = 1791054023;	// 2026-10-03 19:00:23 UTC
check('apiUrl: the stories of the last 7 days from a whole hour', HackerNewsPoints::apiUrl(500, $now),
	'https://hn.algolia.com/api/v1/search_by_date?tags=story&numericFilters=points%3E%3D500%2Ccreated_at_i%3E%3D1790449200'
	. '&hitsPerPage=1000&attributesToRetrieve=title%2Curl%2Cauthor%2Cpoints%2Cnum_comments%2Ccreated_at_i%2Cstory_text');
check('apiUrl: stays the same within the hour', HackerNewsPoints::apiUrl(500, $now + 3000), HackerNewsPoints::apiUrl(500, $now));
check('apiUrl: 7 days back', gmdate('Y-m-d H:i:s', 1790449200), '2026-09-26 19:00:00');

check('decode: stories', HackerNewsPoints::decode((string)json_encode(['hits' => [hit('1', 600), ['no' => 'ID'], 'x'], 'nbHits' => 3])),
	[hit('1', 600)]);
check('decode: no stories', HackerNewsPoints::decode('{"hits":[],"nbHits":0}'), []);
foreach (['not JSON' => '<html>', 'no hits' => '{"message":"Too many requests"}', 'hits not a list' => '{"hits":{"a":1}}',
	'hits without IDs' => '{"hits":[{"title":"x"}]}', 'a list' => '[1,2]'] as $name => $json) {
	check("decode: $name", HackerNewsPoints::decode($json), null);
}

$story = HackerNewsPoints::story(hit('49937276', 529, ['title' => 'Apple Pass Designer', 'author' => 'soheilpro',
	'url' => 'https://developer.apple.com/pass-designer/']));
check('story', $story, [
	'id' => '49937276', 'link' => 'https://developer.apple.com/pass-designer/', 'discussion' => 'https://news.ycombinator.com/item?id=49937276',
	'title' => 'Apple Pass Designer', 'author' => 'soheilpro',
	'html' => '<p><strong>529 points</strong> · <a href="https://news.ycombinator.com/item?id=49937276">316 comments</a>'
		. ' · <a href="https://developer.apple.com/pass-designer/">developer.apple.com</a> · posted 2 Oct 2026, 19:06 UTC</p>' . "\n",
	'points' => 529, 'date' => 1790968016,
]);
check('story: posted in the given time zone', HackerNewsPoints::story(hit('1', 600), new DateTimeZone('America/Chicago'))['html'] ?? '',
	'<p><strong>600 points</strong> · <a href="https://news.ycombinator.com/item?id=1">316 comments</a>'
	. ' · <a href="https://www.example.com/posts/1?ref=hn">example.com</a> · posted 2 Oct 2026, 14:06 CDT</p>' . "\n");
check('story: a text post links to its discussion and has its text', HackerNewsPoints::story(hit('2', 1, ['url' => null, 'num_comments' => 1,
	'title' => "Ask HN:  What are you\n working on?", 'story_text' => " What I’ve built<p>And <a href=\"https://x.org\">more</a>\n"])), [
	'id' => '2', 'link' => 'https://news.ycombinator.com/item?id=2', 'discussion' => 'https://news.ycombinator.com/item?id=2',
	'title' => 'Ask HN: What are you working on?', 'author' => 'pg',
	'html' => '<p><strong>1 point</strong> · <a href="https://news.ycombinator.com/item?id=2">1 comment</a> · posted 2 Oct 2026, 19:06 UTC</p>' . "\n"
		. '<div>What I’ve built<p>And <a href="https://x.org">more</a></div>' . "\n",
	'points' => 1, 'date' => 1790968016,
]);
check('story: not a web link', HackerNewsPoints::story(hit('3', 700, ['url' => 'javascript:alert(1)']))['link'] ?? '', 'https://news.ycombinator.com/item?id=3');
check('story: without counts or date', HackerNewsPoints::story(hit('4', 0, ['points' => null, 'num_comments' => null, 'created_at_i' => null,
	'url' => '', 'author' => null])), [
	'id' => '4', 'link' => 'https://news.ycombinator.com/item?id=4', 'discussion' => 'https://news.ycombinator.com/item?id=4',
	'title' => 'Story 4', 'author' => '',
	'html' => '<p><strong>0 points</strong> · <a href="https://news.ycombinator.com/item?id=4">0 comments</a></p>' . "\n",
	'points' => 0, 'date' => 0,
]);
check('story: escaped', HackerNewsPoints::story(hit('5', 700, ['url' => 'https://a.example/?x=1&y="2"']))['html'] ?? '',
	'<p><strong>700 points</strong> · <a href="https://news.ycombinator.com/item?id=5">316 comments</a>'
	. ' · <a href="https://a.example/?x=1&amp;y=&quot;2&quot;">a.example</a> · posted 2 Oct 2026, 19:06 UTC</p>' . "\n");
foreach (['no title' => hit('6', 700, ['title' => ' ']), 'no ID' => hit('', 700), 'not an HN ID' => hit('6a', 700), 'ID not a string' => ['objectID' => 6] + hit('6', 700)] as $name => $hit) {
	check("story: $name", HackerNewsPoints::story($hit), null);
}

$stories = read([hit('10', 520), hit('11', 1200), hit('12', 499), hit('11', 1200), hit('13', 520), hit('14', 800)]);
check('stories: over the minimum, most points first, each once', array_column(HackerNewsPoints::stories($stories, 500), 'id'), ['11', '14', '13', '10']);
check('stories: none', HackerNewsPoints::stories($stories, 5000), []);

$entry = static fn(string $id, string $story, string $html): array => ['id' => $id, 'guid' => "https://news.ycombinator.com/item?id=$story", 'html' => $html];
check('below: deleted after the minimum was raised, kept when over it, or without stored points', HackerNewsPoints::below([
	$entry('1', '21', '<p><strong>520 points</strong> · <a href="#">3 comments</a></p>'),	// listed now with 520: below
	$entry('2', '22', '<p><strong>520 points</strong></p>'),	// listed now with 1100: kept
	$entry('3', '23', '<p><strong>1100 points</strong></p>'),	// lost points since and not listed: kept
	$entry('4', '24', '<p><strong>600 points</strong></p>'),	// older than the stories read: by its stored points
	$entry('5', '25', '<p><strong>2500 points</strong></p>'),
	$entry('6', '26', '<p>Article URL: https://example.com/</p><p>Points: 600</p>'),	// from hnrss.org
	$entry('7', '27', '<p><strong>1 point</strong></p>'),
], read([hit('21', 520), hit('22', 1100)]), 1000), ['1', '4', '7']);
check('below: nothing under an unchanged minimum', HackerNewsPoints::below([$entry('1', '21', '<p><strong>520 points</strong></p>')],
	read([hit('21', 480)]), 500), []);

$stories = HackerNewsPoints::stories(read([
	hit('31', 650, ['created_at_i' => 1790950000]),	// 2026-10-02 14:06:40
	hit('32', 1500, ['created_at_i' => 1790800000, 'title' => 'A <b>bold</b> & ]]> title', 'author' => 'dang']),	// 2026-09-30 20:26:40
	hit('33', 510, ['created_at_i' => 1791053900]),	// 2 minutes ago
]), 500);
$rss = HackerNewsPoints::rss($stories, 500, $now);
check('rss: reached, dated with this refresh, the most points first', items($rss, 'pubDate'),
	['2026-10-03 19:00:23', '2026-10-03 19:00:22', '2026-10-03 19:00:21']);
check('rss: titles, unescaped', items($rss, 'title'), ['A <b>bold</b> & ]]> title', 'Story 31', 'Story 33']);
check('rss: links to the articles', items($rss, 'link'),
	['https://www.example.com/posts/32?ref=hn', 'https://www.example.com/posts/31?ref=hn', 'https://www.example.com/posts/33?ref=hn']);
check('rss: the discussions as IDs, as on hnrss.org', items($rss, 'guid'),
	['https://news.ycombinator.com/item?id=32', 'https://news.ycombinator.com/item?id=31', 'https://news.ycombinator.com/item?id=33']);
check('rss: comments', items($rss, 'comments'), items($rss, 'guid'));
check('rss: authors', items($rss, 'dc:creator'), ['dang', 'pg', 'pg']);
check('rss: content', items($rss, 'description')[1], $stories[1]['html']);
$xml = simplexml_load_string($rss);
check('rss: channel', $xml instanceof SimpleXMLElement ? [(string)$xml->channel->title, (string)$xml->channel->link, (string)$xml->channel->description] : [], [
	'Hacker News (500+ points)', 'https://news.ycombinator.com/', 'Stories on Hacker News with at least 500 points, dated when they reached it',
]);
check('rss: first refresh dated when posted, not after now', items(HackerNewsPoints::rss($stories, 500, $now, backdate: true), 'pubDate'),
	['2026-09-30 20:26:40', '2026-10-02 14:06:40', '2026-10-03 18:58:20']);
$posted = HackerNewsPoints::rss($stories, 500, $now, date: 'posted');
check('rss: posted, dated when posted', items($posted, 'pubDate'), ['2026-09-30 20:26:40', '2026-10-02 14:06:40', '2026-10-03 18:58:20']);
$xml = simplexml_load_string($posted);
check('rss: posted, channel', $xml instanceof SimpleXMLElement ? (string)$xml->channel->description : '',
	'Stories on Hacker News with at least 500 points, dated when they were posted');
check('rss: posted, without a date', items(HackerNewsPoints::rss(read([hit('34', 600, ['created_at_i' => 0])]), 500, $now, date: 'posted'), 'pubDate'),
	['2026-10-03 19:00:23']);
check('rss: points in the titles', items(HackerNewsPoints::rss($stories, 500, $now, titlePoints: true), 'title'),
	['▲1500 · A <b>bold</b> & ]]> title', '▲650 · Story 31', '▲510 · Story 33']);
check('rss: empty', items(HackerNewsPoints::rss([], 500, $now), 'title'), []);
check('rss: XML characters dropped', items(HackerNewsPoints::rss(read([hit('35', 600, ['title' => "Bell\x07 rings", 'story_text' => "a\x0Cb"])]), 500, $now), 'title'),
	['Bell rings']);

echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
