<?php

namespace Aldagi\ObjectFilter\EntitySelector;

use Aldagi\ObjectFilter\Search\LookupSearch;
use Aldagi\ObjectFilter\Search\ObjectSearch;

class ObjectProvider extends LookupProvider
{
	protected function createSearch(): LookupSearch
	{
		return new ObjectSearch();
	}

	protected function getSearchClass(): string
	{
		return ObjectSearch::class;
	}
}
