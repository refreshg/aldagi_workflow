<?php

namespace Aldagi\ObjectFilter\Search;

/**
 * What a contact-filter lookup needs from a searchable CRM entity.
 * Keys are item IDs, or "<entityTypeId>:<id>" when one lookup spans several types.
 */
interface LookupSearch
{
	/** Lookup value meaning "every item that matches this text" */
	public const TEXT_VALUE_PREFIX = 'search:';

	public static function isAvailable(): bool;

	public function getSelectorEntityId(): string;

	/**
	 * @return array{all: string, found: string, tab: string, stub: string} sprintf templates
	 */
	public function getCaptions(): array;

	/**
	 * @return array<int|string> readable keys matching the text, newest first
	 */
	public function findKeys(string $text): array;

	/**
	 * @return array<int|string> newest readable keys
	 */
	public function getRecentKeys(int $limit): array;

	/**
	 * @return array<int|string> the subset of keys the current user may read
	 */
	public function filterReadableKeys(array $keys): array;

	/**
	 * @return array<int|string, array{title: string, subtitle: string, url: string}> in the order of $keys
	 */
	public function getItemsInfo(array $keys): array;

	/**
	 * Contacts linked to items that are among $keys or match the text chips.
	 *
	 * @return int[]
	 */
	public function findContactIds(array $keys, array $texts): array;
}
