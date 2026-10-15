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
			],
		],
		'readonly' => true,
	],
];
