<?php

declare(strict_types=1);

/**
 * Hacker News as a feed of the stories with at least a given number of points, subscribed to as
 * https://news.ycombinator.com/.
 *
 * The feed is built from the HN Search API by Algolia ({@see HackerNewsPoints}), which lists the recent stories over a
 * number of points, so that a story is dated when it reaches it rather than when it was posted, often a day earlier.
 * The minimum, the dates and the points in the titles are settings, which a feed’s URL may override:
 * https://news.ycombinator.com/?min_points=500&date=posted&title_points=1.
 */
final class HackerNewsPointsExtension extends Minz_Extension {
	/** @var array<int,true> IDs of the feeds read by this extension during this request */
	private array $feedIds = [];

	#[\Override]
	public function init(): void {
		parent::init();
		require_once __DIR__ . '/lib/HackerNewsPoints.php';
		require_once __DIR__ . '/lib/HackerNewsPointsDAO.php';
		$this->registerTranslates();
		// Hook names as strings rather than Minz_HookType, to stay compatible with FreshRSS 1.28
		$this->registerHook('check_url_before_add', [$this, 'checkUrlBeforeAdd']);
		$this->registerHook('simplepie_before_init', [$this, 'simplepieBeforeInit']);
		$this->registerHook('entry_before_update', [$this, 'entryBeforeUpdate']);
	}

	/**
	 * “Add a feed” accepts Hacker News’s front page or best stories in any form (http://, /news, /best, …), which becomes
	 * https://news.ycombinator.com/, with its own `min_points`, `date` and `title_points` if given.
	 */
	public function checkUrlBeforeAdd(string $url): string {
		return HackerNewsPoints::pageUrl($url) ?? $url;
	}

	/**
	 * Before SimplePie fetches the feed, give it the RSS built from the API, and delete the feed’s stories that are below
	 * its minimum ({@see deleteBelow()}).
	 * @throws FreshRSS_Feed_Exception when the API cannot be read
	 */
	public function simplepieBeforeInit(FreshRSS_SimplePieCustom $simplePie, FreshRSS_Feed $feed): void {
		if (!in_array($feed->kind(), [FreshRSS_Feed::KIND_RSS, FreshRSS_Feed::KIND_RSS_FORCED], true)) {
			return;
		}
		$url = htmlspecialchars_decode($feed->url(), ENT_QUOTES);
		if (HackerNewsPoints::pageUrl($url) === null) {
			return;
		}
		$settings = HackerNewsPoints::urlSettings($url);
		$minPoints = $settings['min_points'] ?? $this->minPoints();
		$now = time();
		$apiUrl = HackerNewsPoints::apiUrl($minPoints, $now);
		$hits = HackerNewsPoints::decode(self::fetch($apiUrl, $feed));
		if ($hits === null) {
			throw new FreshRSS_Feed_Exception(_t('ext.hacker_news_points.error.format', $apiUrl));
		}
		$timezone = self::timezone();
		$stories = [];
		foreach ($hits as $hit) {
			$story = HackerNewsPoints::story($hit, $timezone);
			if ($story !== null) {
				$stories[] = $story;
			}
		}
		self::deleteBelow($feed, $stories, $minPoints);
		$rss = HackerNewsPoints::rss(HackerNewsPoints::stories($stories, $minPoints), $minPoints, $now,
			date: $settings['date'] ?? $this->date(), backdate: $feed->lastUpdate() <= 0,
			titlePoints: $settings['title_points'] ?? $this->titlePoints());
		$this->feedIds[$feed->id()] = true;

		// SimplePie would answer a refresh within its cache duration (800 s) from its cache rather than from this RSS, which
		// reflects the current settings. Drop the cache as FreshRSS’s own option to clear it does, without its favicon rebuild.
		@unlink($feed->cacheFilename());

		// With a Content-Type rather than force_feed(true), which would make SimplePie cache the feed under another name
		// than the one FreshRSS reads the time of the last refresh from
		$response = (new \SimplePie\HTTP\RawTextResponse($rss, $url))->with_header('content-type', 'application/rss+xml; charset=UTF-8');
		$file = \SimplePie\File::fromResponse($response);
		$simplePie->set_file($file);	// takes a reference
	}

	/**
	 * Delete the feed’s stories that are below its minimum, e.g. after it was raised ({@see HackerNewsPoints::below()}),
	 * except starred ones and ones with labels.
	 * @param list<array{id:string,link:string,discussion:string,title:string,author:string,html:string,points:int,date:int}> $stories
	 */
	private static function deleteBelow(FreshRSS_Feed $feed, array $stories, int $minPoints): void {
		$entries = [];
		foreach (FreshRSS_Factory::createEntryDao()->listWhere('f', $feed->id(), limit: 0) as $entry) {
			$entries[] = ['id' => $entry->id(), 'guid' => $entry->guid(), 'html' => $entry->content(false)];
		}
		$ids = HackerNewsPoints::below($entries, $stories, $minPoints);
		$deleted = $ids === [] ? 0 : (new HackerNewsPointsDAO())->deleteEntries($feed->id(), $ids);
		if ($deleted > 0) {
			FreshRSS_Factory::createFeedDao()->updateCachedValues($feed->id());
			Minz_Log::notice('Hacker News Points: deleted ' . $deleted . ' stories below ' . $minPoints . ' points from ' . $feed->url(includeCredentials: false));
		}
	}

	/**
	 * An API answer, which FreshRSS caches for its cache duration (800 s): feeds refreshed together share it.
	 * A cache file is also required before FreshRSS 1.30.
	 * @param non-empty-string $url
	 * @throws FreshRSS_Feed_Exception
	 */
	private static function fetch(string $url, FreshRSS_Feed $feed): string {
		$response = FreshRSS_http_Util::httpGet($url, $feed->cacheFilename($url), 'json', $feed->attributes(), $feed->curlOptions());
		if ($response['fail'] || $response['body'] === '') {
			// `status` and `error` are only returned since FreshRSS 1.29
			$status = $response['status'] ?? 0;
			$status = is_int($status) ? abs($status) : 0;
			$error = $response['error'] ?? '';
			throw new FreshRSS_Feed_Exception(_t('ext.hacker_news_points.error.fetch', $url,
				$status > 0 ? 'HTTP ' . $status : (is_string($error) && $error !== '' ? $error : '?')), $status);
		}
		return $response['body'];
	}

	/** The user’s time zone, in which a story’s content gives when it was posted, as FreshRSS shows the dates */
	private static function timezone(): ?DateTimeZone {
		$timezone = FreshRSS_Context::hasUserConf() ? FreshRSS_Context::userConf()->timezone : '';
		try {
			return new DateTimeZone($timezone !== '' ? $timezone : date_default_timezone_get());
		} catch (Exception) {
			return null;
		}
	}

	/**
	 * A listed story changes with its points and comments, and with the setting of points in the titles.
	 * FreshRSS then updates it with the date of the refresh, which would move it back to the top; keep its first date,
	 * when it reached the threshold, or was posted.
	 */
	public function entryBeforeUpdate(FreshRSS_Entry $entry): FreshRSS_Entry {
		if (isset($this->feedIds[$entry->feedId()])) {
			$stored = FreshRSS_Factory::createEntryDao()->searchByGuid($entry->feedId(), $entry->guid());
			if ($stored !== null) {
				$entry->_date($stored->date(true));
			}
		}
		return $entry;
	}

	#[\Override]
	public function handleConfigureAction(): void {
		require_once __DIR__ . '/lib/HackerNewsPoints.php';	// FreshRSS also configures a disabled extension, without init()
		$this->registerTranslates();
		if (Minz_Request::isPost()) {
			$this->setUserConfiguration([
				'min_points' => HackerNewsPoints::boundMinPoints(Minz_Request::paramInt('min_points')),
				'date' => HackerNewsPoints::date(Minz_Request::paramString('date')) ?? HackerNewsPoints::DATE_DEFAULT,
				'title_points' => Minz_Request::paramBoolean('title_points'),
			]);
		}
	}

	// getUserConfigurationValue() rather than the typed getters, which need FreshRSS 1.29
	/** The minimum of points of the feeds whose URL does not give one */
	public function minPoints(): int {
		$minPoints = $this->getUserConfigurationValue('min_points');
		return is_int($minPoints) ? HackerNewsPoints::boundMinPoints($minPoints) : HackerNewsPoints::MIN_POINTS_DEFAULT;
	}

	/** How the stories of the feeds whose URL does not say are dated: `reached` or `posted` */
	public function date(): string {
		$date = $this->getUserConfigurationValue('date');
		return (is_string($date) ? HackerNewsPoints::date($date) : null) ?? HackerNewsPoints::DATE_DEFAULT;
	}

	/** Whether the titles start with the points, for the feeds whose URL does not say */
	public function titlePoints(): bool {
		return $this->getUserConfigurationValue('title_points') === true;
	}
}
