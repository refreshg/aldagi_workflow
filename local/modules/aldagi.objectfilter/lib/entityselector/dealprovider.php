<?php

namespace Aldagi\ObjectFilter\EntitySelector;

use Aldagi\ObjectFilter\Search\DealSearch;
use Aldagi\ObjectFilter\Search\LookupSearch;

class DealProvider extends LookupProvider
{
	protected function createSearch(): LookupSearch
	{
		return new DealSearch();
	}

	protected function getSearchClass(): string
	{
		return DealSearch::class;
	}
}
