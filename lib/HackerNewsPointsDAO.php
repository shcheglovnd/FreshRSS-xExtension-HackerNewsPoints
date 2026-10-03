<?php

declare(strict_types=1);

/**
 * What FreshRSS’s own DAOs lack: deleting given entries of a feed.
 */
final class HackerNewsPointsDAO extends Minz_ModelPdo {
	/**
	 * Deletes the given entries of a feed, except starred ones and ones with labels, as FreshRSS’s own clean-up keeps them.
	 * @param list<string> $ids
	 * @return int how many were deleted
	 */
	public function deleteEntries(int $feedId, array $ids): int {
		$deleted = 0;
		foreach (array_chunk($ids, FreshRSS_DatabaseDAO::MAX_VARIABLE_NUMBER - 1) as $chunk) {
			$stm = $this->pdo->prepare('DELETE FROM `_entry` WHERE id_feed = ? AND is_favorite = 0'
				. ' AND id NOT IN (SELECT id_entry FROM `_entrytag`)'
				. ' AND id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
			if ($stm !== false && $stm->execute([$feedId, ...$chunk])) {
				$deleted += $stm->rowCount();
			}
		}
		return $deleted;
	}
}
