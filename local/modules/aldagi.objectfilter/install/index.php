<?php

use Bitrix\Main\ModuleManager;

class aldagi_objectfilter extends CModule
{
	public $MODULE_ID = 'aldagi.objectfilter';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME = 'Aldagi: კონტაქტების ძებნა ობიექტით';
	public $MODULE_DESCRIPTION = 'კონტაქტების ფილტრში „ობიექტი" lookup და ობიექტის (SPA 130) ველებით ძებნა';
	public $PARTNER_NAME = 'Aldagi';
	public $PARTNER_URI = '';

	public function __construct()
	{
		$arModuleVersion = [];
		include __DIR__ . '/version.php';
		$this->MODULE_VERSION = $arModuleVersion['VERSION'];
		$this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
	}

	public function DoInstall()
	{
		ModuleManager::registerModule($this->MODULE_ID);
	}

	public function DoUninstall()
	{
		ModuleManager::unRegisterModule($this->MODULE_ID);
	}
}
