<?php

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\EventManager;

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

// make → model dependency of the contact filter (js/aldagi/objectfilter/carmodel)
EventManager::getInstance()->addEventHandler('main', 'OnProlog', static function (): void {
	if (defined('ADMIN_SECTION') && ADMIN_SECTION === true)
	{
		return;
	}
	$page = (string)\Bitrix\Main\Context::getCurrent()->getRequest()->getRequestedPage();
	if (str_starts_with($page, '/crm/contact/'))
	{
		\Bitrix\Main\UI\Extension::load('aldagi.objectfilter.carmodel');
	}
});
