<?php

declare(strict_types=1);

/**
 * Hacker News stories with at least a given number of points, as an RSS document, dated when they reach it.
 *
 * A story gains points for a day or two, so it reaches a threshold hours after it was posted, at a time that no API
 * gives. So all the stories of the last {@see DAYS} days with at least the minimum are read at each refresh, from the HN
 * Search API by Algolia, which hnrss.org uses too, and a story is dated with the refresh that first lists it, i.e. when
 * it reached the minimum. Or, as a setting, when it was posted.
 *
 * A feed’s URL may set its own minimum, dates and points in the titles, e.g.
 * https://news.ycombinator.com/?min_points=500&date=posted&title_points=1.
 *
 * Independent of FreshRSS, so it can be tested without it.
 */
final class HackerNewsPoints {
	/** The feed URL with the extension’s settings */
	public const PAGE = 'https://news.ycombinator.com/';
	/** A story’s discussion on Hacker News, also the ID of its entry, as on hnrss.org */
	private const ITEM = 'https://news.ycombinator.com/item?id=';
	private const API = 'https://hn.algolia.com/api/v1/search_by_date';
	public const MIN_POINTS_DEFAULT = 500;
	public const MIN_POINTS_MAX = 100000;
	/** How a story is dated: when it reached the minimum, as first listed by the feed, or when it was posted */
	public const DATES = ['reached', 'posted'];
	public const DATE_DEFAULT = 'reached';
	/** Stories are read while they are this many days old at most: they stop gaining points within a day or two */
	public const DAYS = 7;
	/** Most stories per refresh, the API’s maximum for a request */
	public const HITS = 1000;

	/**
	 * The feed URL for Hacker News’s front page or best stories in any form (http://, /news, /best, other query
	 * parameters), else null: https://news.ycombinator.com/, with the feed’s own settings if any ({@see urlSettings()}).
	 */
	public static function pageUrl(string $url): ?string {
		if (preg_match('#^(?:https?://)?news\.ycombinator\.com(?:/|/news|/best)?(?:[?\#].*)?$#i', trim($url)) !== 1) {
			return null;
		}
		$settings = array_filter(self::urlSettings($url), static fn(string|int|bool|null $value): bool => $value !== null);
		return self::PAGE . ($settings !== [] ? '?' . http_build_query($settings) : '');
	}

	/**
	 * The settings of a feed URL, or null when it does not give them in a known form: `min_points` as a number within its
	 * bounds (or `points`, as on hnrss.org), `date`, how stories are dated ({@see date()}), and `title_points`, whether
	 * titles start with the points, as 1 or 0 (or true/false, yes/no, on/off)
	 * @return array{min_points:int|null,date:string|null,title_points:bool|null}
	 */
	public static function urlSettings(string $url): array {
		$params = self::params($url);
		$minPoints = $params['min_points'] ?? $params['points'] ?? null;
		$date = $params['date'] ?? null;
		$titlePoints = $params['title_points'] ?? null;
		return [
			'min_points' => is_string($minPoints) && ctype_digit($minPoints) ? self::boundMinPoints((int)$minPoints) : null,
			'date' => is_string($date) ? self::date($date) : null,
			'title_points' => is_string($titlePoints) ? match (strtolower($titlePoints)) {
				'1', 'true', 'yes', 'on' => true,
				'0', 'false', 'no', 'off' => false,
				default => null,
			} : null,
		];
	}

	/** One of the ways of dating stories ({@see DATES}), in any case, else null */
	public static function date(string $value): ?string {
		$value = strtolower(trim($value));
		return in_array($value, self::DATES, true) ? $value : null;
	}

	public static function boundMinPoints(int $minPoints): int {
		return max(0, min(self::MIN_POINTS_MAX, $minPoints));
	}

	/**
	 * The API URL of the stories posted in the last {@see DAYS} days that have at least $minPoints, newest first.
	 * They start at a whole hour, so that the URL, and FreshRSS’s cached answer, stay the same for an hour.
	 * @return non-empty-string
	 */
	public static function apiUrl(int $minPoints, int $now): string {
		return self::API . '?' . http_build_query([
			'tags' => 'story',
			'numericFilters' => 'points>=' . $minPoints . ',created_at_i>=' . (intdiv($now, 3600) - self::DAYS * 24) * 3600,
			'hitsPerPage' => self::HITS,
			'attributesToRetrieve' => 'title,url,author,points,num_comments,created_at_i,story_text',
		]);
	}

	/**
	 * @return list<array<string,mixed>>|null the stories of an API answer, null when it is not a list of stories
	 */
	public static function decode(string $json): ?array {
		$answer = json_decode($json, true);
		$hits = is_array($answer) ? ($answer['hits'] ?? null) : null;
		if (!is_array($hits) || !array_is_list($hits)) {
			return null;
		}
		$stories = array_values(array_filter($hits, static fn(mixed $hit): bool => is_array($hit) && is_string($hit['objectID'] ?? null)));
		/** @var list<array<string,mixed>> $stories */
		return $hits === [] || $stories !== [] ? $stories : null;
	}

	/**
	 * A story of an API answer, whatever its points, or null if it lacks an ID or a title. It links to the article, or to
	 * the discussion for a text post; its date is when it was posted, 0 if unknown, which its content gives in $timezone.
	 * @param array<string,mixed> $hit
	 * @return array{id:string,link:string,discussion:string,title:string,author:string,html:string,points:int,date:int}|null
	 */
	public static function story(array $hit, ?DateTimeZone $timezone = null): ?array {
		$id = self::text($hit['objectID'] ?? null);
		$title = self::text($hit['title'] ?? null);
		if (!ctype_digit($id) || $title === '') {
			return null;
		}
		$discussion = self::ITEM . $id;
		$points = self::number($hit['points'] ?? null);
		$comments = self::number($hit['num_comments'] ?? null);
		$date = self::number($hit['created_at_i'] ?? null);
		$url = self::text($hit['url'] ?? null);
		$host = preg_match('#^https?://#i', $url) === 1 ? parse_url($url, PHP_URL_HOST) : null;
		if (!is_string($host) || $host === '') {
			$url = '';
		}

		$facts = ['<strong>' . $points . ' ' . ($points === 1 ? 'point' : 'points') . '</strong>',
			self::link($discussion, $comments . ' ' . ($comments === 1 ? 'comment' : 'comments'))];
		if ($url !== '') {
			$facts[] = self::link($url, preg_replace('/^www\./i', '', (string)$host) ?? '');
		}
		if ($date > 0) {
			$posted = (new DateTimeImmutable('@' . $date))->setTimezone($timezone ?? new DateTimeZone(date_default_timezone_get()));
			$facts[] = 'posted ' . $posted->format('j M Y, H:i T');
		}
		$text = trim(self::text($hit['story_text'] ?? null, collapse: false));
		$html = '<p>' . implode(' · ', $facts) . "</p>\n" . ($text !== '' ? '<div>' . $text . "</div>\n" : '');
		return ['id' => $id, 'link' => $url !== '' ? $url : $discussion, 'discussion' => $discussion, 'title' => $title,
			'author' => self::text($hit['author'] ?? null), 'html' => $html, 'points' => $points, 'date' => $date];
	}

	/**
	 * The stories with at least $minPoints, most points first, each once.
	 * @param list<array{id:string,link:string,discussion:string,title:string,author:string,html:string,points:int,date:int}> $stories
	 * @return list<array{id:string,link:string,discussion:string,title:string,author:string,html:string,points:int,date:int}>
	 */
	public static function stories(array $stories, int $minPoints): array {
		$over = [];
		foreach ($stories as $story) {
			if ($story['points'] >= $minPoints) {
				$over[$story['id']] ??= $story;
			}
		}
		$over = array_values($over);
		usort($over, static fn(array $a, array $b): int => [$b['points'], (int)$b['id']] <=> [$a['points'], (int)$a['id']]);
		return $over;
	}

	/**
	 * The entries of a feed to delete as below its minimum, e.g. after it was raised: those whose points, as stored in the
	 * entry, are below it, unless the API now gives at least the minimum. An entry keeps the points it had when it was
	 * last listed, so a story that loses a few points and falls under the minimum stays, rather than coming and going.
	 * An entry without stored points stays too, such as one from hnrss.org.
	 * @param list<array{id:string,guid:string,html:string}> $entries
	 * @param list<array{id:string,link:string,discussion:string,title:string,author:string,html:string,points:int,date:int}> $stories
	 *  the stories of this refresh, whatever their points
	 * @return list<string> entry IDs
	 */
	public static function below(array $entries, array $stories, int $minPoints): array {
		$points = [];
		foreach ($stories as $story) {
			$points[$story['discussion']] = max($points[$story['discussion']] ?? 0, $story['points']);
		}
		$ids = [];
		foreach ($entries as $entry) {
			if (preg_match('#<strong>(\d+) points?</strong>#', $entry['html'], $matches) === 1 && (int)$matches[1] < $minPoints
				&& ($points[$entry['guid']] ?? 0) < $minPoints) {
				$ids[] = $entry['id'];
			}
		}
		return $ids;
	}

	/**
	 * The RSS document of the stories. With $date `reached`, each is dated with the time of the refresh that first lists
	 * it, i.e. when it reached the minimum, so that a story reaching it a day after it was posted comes first in a reader
	 * sorted by date. On the feed’s first refresh, $backdate dates them when they were posted instead, rather than all at
	 * once. Stories dated together are one second apart, the most points first. With $date `posted`, each is dated when it
	 * was posted, as on Hacker News.
	 * With $titlePoints, titles start with the points, e.g. “▲529 · Apple Pass Designer”.
	 * @param list<array{id:string,link:string,discussion:string,title:string,author:string,html:string,points:int,date:int}> $stories
	 */
	public static function rss(array $stories, int $minPoints, int $now, string $date = self::DATE_DEFAULT, bool $backdate = false,
		bool $titlePoints = false): string {
		$posted = $date === 'posted';
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n"
			. "<channel>\n"
			. self::element('title', 'Hacker News (' . $minPoints . '+ points)')
			. self::element('link', self::PAGE)
			. self::element('description', 'Stories on Hacker News with at least ' . $minPoints . ' points, dated when they '
				. ($posted ? 'were posted' : 'reached it'));
		foreach ($stories as $rank => $story) {
			$time = match (true) {
				$story['date'] > 0 && $posted => $story['date'],
				$story['date'] > 0 && $backdate => min($now - $rank, $story['date']),
				default => $now - $rank,
			};
			$xml .= "<item>\n"
				. self::element('title', ($titlePoints ? '▲' . $story['points'] . ' · ' : '') . $story['title'])
				. self::element('link', $story['link'])
				. '<guid isPermaLink="false">' . self::escape($story['discussion']) . "</guid>\n"
				. self::element('comments', $story['discussion'])
				. self::element('pubDate', gmdate(DATE_RSS, $time))
				. ($story['author'] !== '' ? self::element('dc:creator', $story['author']) : '')
				. '<description><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', self::xmlChars($story['html'])) . "]]></description>\n"
				. "</item>\n";
		}
		return $xml . "</channel>\n</rss>\n";
	}

	/** A non-negative whole number of the API, else 0 */
	private static function number(mixed $value): int {
		return is_int($value) && $value > 0 ? $value : 0;
	}

	/** @return array<mixed> the query parameters of a URL */
	private static function params(string $url): array {
		parse_str(preg_match('/\?([^#]*)/', $url, $matches) === 1 ? $matches[1] : '', $params);
		return $params;
	}

	/** A string value, trimmed, with its runs of white space made one space unless $collapse is false */
	private static function text(mixed $value, bool $collapse = true): string {
		if (!is_string($value)) {
			return '';
		}
		return trim($collapse ? (preg_replace('/\s+/u', ' ', $value) ?? $value) : $value);
	}

	private static function link(string $url, string $text): string {
		return '<a href="' . self::escape($url) . '">' . self::escape($text) . '</a>';
	}

	private static function element(string $name, string $value): string {
		return '<' . $name . '>' . self::escape($value) . '</' . $name . ">\n";
	}

	private static function escape(string $text): string {
		return htmlspecialchars(self::xmlChars($text), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/** Drops characters XML 1.0 does not allow. */
	private static function xmlChars(string $text): string {
		return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
	}
}
