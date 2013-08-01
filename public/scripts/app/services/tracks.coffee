angular.module('ponyfm').factory('tracks', [
	'$rootScope', '$http', 'taxonomies'
	($rootScope, $http, taxonomies) ->
		filterDef = null
		trackCache = {}

		class Query
			cachedDef: null
			page: 1
			listeners: []

			constructor: (@availableFilters) ->
				@filters = {}
				@hasLoadedFilters = false

				_.each @availableFilters, (filter, name) =>
					if filter.type == 'single'
						@filters[name] = _.find filter.values, (f) -> f.isDefault
					else
						@filters[name] = {title: 'Any', selectedArray: [], selectedObject: {}}

			isIdSelected: (type, id) ->
				@filters[type].selectedObject[id] != undefined

			listen: (listener) ->
				@listeners.push listener
				@cachedDef.done listener if @cachedDef

			setListFilter: (type, id) ->
				@cachedDef = null
				@page = 1
				filterToAdd = _.find @availableFilters[type].values, (f) -> `f.id == id`
				return if !filterToAdd

				filter = @filters[type]
				filter.selectedArray = [filterToAdd]
				filter.selectedObject = {}
				filter.selectedObject[id] = filterToAdd
				filter.title = filterToAdd.title

			toggleListFilter: (type, id) ->
				@cachedDef = null
				@page = 1
				filter = @filters[type]

				if filter.selectedObject[id]
					delete filter.selectedObject[id]
					filter.selectedArray.splice _.indexOf(filter.selectedArray, (f) -> f.id == id), 1
				else
					filterToAdd = _.find @availableFilters[type].values, (f) -> `f.id == id`
					return if !filterToAdd
					filter.selectedObject[id] = filterToAdd
					filter.selectedArray.push filterToAdd

				if filter.selectedArray.length == 0
					filter.title = 'Any'
				else if filter.selectedArray.length == 1
					filter.title = filter.selectedArray[0].title
				else
					filter.title = filter.selectedArray.length + ' selected'

			setPage: (page) ->
				@page = page
				@cachedDef = null

			setFilter: (type, value) ->
				@cachedDef = null
				@page = 1
				@filters[type] = value

			toFilterString: ->
				parts = []
				_.each @availableFilters, (filter, name) =>
					if filter.type == 'single'
						return if @filters[name].query == ''
						parts.push(name + '-' + @filters[name].query)
					else
						return if @filters[name].selectedArray.length == 0
						parts.push(name + '-' + _.map(@filters[name].selectedArray, (f) -> f.id).join '-')

				return parts.join '!'

			fromFilterString: (str) ->
				@hasLoadedFilters = true
				return if !str
				filters = str.split '!'
				for filter in filters
					parts = filter.split '-'
					name = parts[0]
					return if !@availableFilters[name]

					if @availableFilters[name].type == 'single'
						filterToSet = _.find @availableFilters[name].values, (f) -> f.query == parts[1]
						filterToSet = _.find @availableFilters[name].values, (f) -> f.isDefault if filterToSet == null
					else
						@toggleListFilter name, id for id in _.rest parts, 1

			fetch: () ->
				return @cachedDef if @cachedDef
				@cachedDef = new $.Deferred()
				trackDef = @cachedDef

				query = '/api/web/tracks?'
				parts = ['page=' + @page]
				_.each @availableFilters, (filter, name) =>
					if filter.type == 'single'
						parts.push @filters[name].filter
					else
						queryName = filter.filterName
						for item in @filters[name].selectedArray
							parts.push queryName + "[]=" + item.id

				query += parts.join '&'
				$http.get(query).success (tracks) =>
					@tracks = tracks
					for listener in @listeners
						listener tracks

					trackDef.resolve tracks

				trackDef.promise()

		self =
			filters: {}

			fetch: (id, force) ->
				force = force || false
				return trackCache[id] if !force && trackCache[id]
				trackDef = new $.Deferred()
				$http.get('/api/web/tracks/' + id).success (track) ->
					trackDef.resolve track

				trackCache[id] = trackDef.promise()

			createQuery: -> new Query self.filters

			loadFilters: ->
				return filterDef if filterDef

				filterDef = new $.Deferred()
				self.filters.isVocal =
					type: 'single'
					values: [
						{title: 'Either', query: '', isDefault: true, filter: ''},
						{title: 'Yes', query: 'yes', isDefault: false, filter: 'is_vocal=true'},
						{title: 'No', query: 'no', isDefault: false, filter: 'is_vocal=false'}
					]

				self.filters.sort =
					type: 'single'
					values: [
						{title: 'Newest to Oldest', query: '', isDefault: true, filter: 'order=created_at,desc'},
						{title: 'Oldest to Newest', query: 'created_at,asc', isDefault: true, filter: 'order=created_at,asc'}
					]

				self.filters.genres =
					type: 'list'
					values: []
					filterName: 'genres'

				self.filters.trackTypes =
					type: 'list'
					values: []
					filterName: 'types'

				self.filters.showSongs =
					type: 'list'
					values: []
					filterName: 'songs'

				taxonomies.refresh().done (taxes) ->
					for genre in taxes.genresWithTracks
						self.filters.genres.values.push
							title: genre.name
							id: genre.id

					for type in taxes.trackTypesWithTracks
						self.filters.trackTypes.values.push
							title: type.title
							id: type.id

					for song in taxes.showSongsWithTracks
						self.filters.showSongs.values.push
							title: song.title
							id: song.id

					self.mainQuery = self.createQuery()
					filterDef.resolve self

				filterDef.promise()

		self
])