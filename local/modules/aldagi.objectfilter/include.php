<?php

use Bitrix\Main\DI\ServiceLocator;

// crm skips its own 'crm.filter.factory' registration when the service is already defined
// (see ServiceLocator::registerByModuleSettings), so the module has to be included before crm.
$serviceLocator = ServiceLocator::getInstance();
if (!$serviceLocator->has('crm.filter.factory'))
{
	$serviceLocator->addInstanceLazy('crm.filter.factory', [
		'className' => \Aldagi\ObjectFilter\Filter\FilterFactory::class,
	]);
}
unset($serviceLocator);
