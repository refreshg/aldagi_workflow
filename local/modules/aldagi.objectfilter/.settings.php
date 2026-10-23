<?php

return [
	'ui.entity-selector' => [
		'value' => [
			'entities' => [
				[
					'entityId' => 'aldagi-object',
					'provider' => [
						'moduleId' => 'aldagi.objectfilter',
						'className' => '\\Aldagi\\ObjectFilter\\EntitySelector\\ObjectProvider',
					],
				],
				[
					'entityId' => 'aldagi-deal',
					'provider' => [
						'moduleId' => 'aldagi.objectfilter',
						'className' => '\\Aldagi\\ObjectFilter\\EntitySelector\\DealProvider',
					],
				],
				[
					'entityId' => 'aldagi-calculation',
					'provider' => [
						'moduleId' => 'aldagi.objectfilter',
						'className' => '\\Aldagi\\ObjectFilter\\EntitySelector\\CalculationProvider',
					],
				],
			],
		],
		'readonly' => true,
	],
];
