/**
 * Contact filter: the "ობიექტი: მოდელი" dialog shows only models of the makes chosen
 * in the "ობიექტი: მარკა" field of the same filter (aldagi.objectfilter, D-10).
 *
 * The dialog sends its entity options with every request, so the chosen makes are put
 * into the options before loading and searching; when the makes change, the already
 * loaded model list is dropped and loaded again.
 */
(function () {
	'use strict';

	var ENTITY_ID = 'aldagi-car-model';
	var Dialog = BX.UI && BX.UI.EntitySelector && BX.UI.EntitySelector.Dialog;
	if (!Dialog || Dialog.prototype.aldagiCarModelPatched)
	{
		return;
	}
	Dialog.prototype.aldagiCarModelPatched = true;

	function getMakeIds(entity)
	{
		var options = entity.getOptions() || {};
		var filter = BX.Main && BX.Main.filterManager && BX.Main.filterManager.getById(options.filterId);
		if (!filter || typeof filter.getFilterFieldsValues !== 'function')
		{
			return [];
		}

		var value = filter.getFilterFieldsValues()[options.makeFieldId];
		var values = [];
		if (Array.isArray(value))
		{
			values = value;
		}
		else if (value && typeof value === 'object')
		{
			values = Object.keys(value).map(function (key) { return value[key]; });
		}
		else if (value)
		{
			values = [value];
		}

		return values
			.map(function (id) { return parseInt(id, 10); })
			.filter(function (id) { return id > 0; })
			.sort(function (a, b) { return a - b; });
	}

	var originalShow = Dialog.prototype.show;
	Dialog.prototype.show = function () {
		var entity = this.getEntity(ENTITY_ID);
		if (entity)
		{
			var makeIds = getMakeIds(entity);
			var key = makeIds.join(',');
			if (typeof this.aldagiMakeKey !== 'undefined' && this.aldagiMakeKey !== key && this.loadState === 'DONE')
			{
				this.getItems().forEach(function (item) {
					if (item.getEntityId() === ENTITY_ID && !item.isSelected())
					{
						this.removeItem(item);
					}
				}, this);
				this.loadState = 'UNSENT';
			}
			this.aldagiMakeKey = key;
			entity.getOptions().makeIds = makeIds;
		}

		return originalShow.apply(this, arguments);
	};

	var originalGetAjaxJson = Dialog.prototype.getAjaxJson;
	Dialog.prototype.getAjaxJson = function () {
		var entity = this.getEntity(ENTITY_ID);
		if (entity)
		{
			entity.getOptions().makeIds = getMakeIds(entity);
		}

		return originalGetAjaxJson.apply(this, arguments);
	};
})();
