<?php

namespace Aldagi\ObjectFilter\EntitySelector;

use Aldagi\ObjectFilter\Search\CalculationSearch;
use Aldagi\ObjectFilter\Search\LookupSearch;

class CalculationProvider extends LookupProvider
{
	protected function createSearch(): LookupSearch
	{
		return new CalculationSearch();
	}

	protected function getSearchClass(): string
	{
		return CalculationSearch::class;
	}
}
