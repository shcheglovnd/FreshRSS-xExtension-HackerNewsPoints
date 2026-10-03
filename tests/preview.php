<?php

declare(strict_types=1);

/**
 * Manual check against the HN Search API, without FreshRSS: prints the stories the feed would list now.
 *
 *   php tests/preview.php                  # with at least 500 points, of the last 7 days
 *   php tests/preview.php 100              # with at least 100
 *   php tests/preview.php 1000 --rss       # the generated RSS instead of a summary, dated when they reached it
 *   php tests/preview.php 1000 --rss --posted   # dated when they were posted
 */

require dirname(__DIR__) . '/lib/HackerNewsPoints.php';

$args = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => !str_starts_with($a, '--')));
$minPoints = HackerNewsPoints::boundMinPoints((int)($args[0] ?? HackerNewsPoints::MIN_POINTS_DEFAULT));
$context = stream_context_create(['http' => ['header' => "User-Agent: FreshRSS Hacker News Points test\r\n", 'timeout' => 60]]);
$now = time();
$url = HackerNewsPoints::apiUrl($minPoints, $now);
$hits = HackerNewsPoints::decode((string)file_get_contents($url, false, $context));
if ($hits === null) {
	fwrite(STDERR, "not a list of stories: $url\n");
	exit(1);
}
$stories = HackerNewsPoints::stories(array_values(array_filter(array_map(static fn(array $hit): ?array => HackerNewsPoints::story($hit), $hits))), $minPoints);
if (in_array('--rss', $argv, true)) {
	echo HackerNewsPoints::rss($stories, $minPoints, $now, date: in_array('--posted', $argv, true) ? 'posted' : 'reached');
	exit(0);
}
printf("%d stories with at least %d points, posted in the last %d days\n\n", count($stories), $minPoints, HackerNewsPoints::DAYS);
foreach ($stories as $rank => $story) {
	printf("%4d. %5d  %s  %-9s  %s\n", $rank + 1, $story['points'], date('Y-m-d H:i', $story['date']), $story['id'], mb_strimwidth($story['title'], 0, 70, '…'));
}
