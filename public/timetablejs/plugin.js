/*jshint -W079*/

'use strict';

var Timetable = function() {
	this.scope = {
		hourStart: 9,
		hourEnd: 17
	};
	this.usingTwelveHour = false;
	this.locations = [];
	this.locationLinks = {};
	this.locationInfoContainer = undefined;
	this.locale = 'en-GB';
	this.events = [];
};

Timetable.Renderer = function(tt) {
	if (!(tt instanceof Timetable)) {
		throw new Error('Initialize renderer using a Timetable');
	}
	this.timetable = tt;
	this.timer = null;
	this.layout = null;
	this.selector = null;
	this.mediaListener = null;
};

// Width of one hour in the timeline, in pixels. On narrow screens hours are
// narrower, so more of the day fits. The grid in plugin.css follows via --hour-width.
Timetable.HOUR_WIDTH = 96;
Timetable.HOUR_WIDTH_NARROW = 72;
// Height of one lane in a location's row, in pixels (matches plugin.css). Events
// that overlap in time at the same location are shown in lanes below each other.
Timetable.ROW_HEIGHT = 46;
Timetable.NARROW_SCREEN = '(max-width: 600px)';
// How often the current-time line and past/current markers are refreshed.
Timetable.NOW_UPDATE_INTERVAL = 30 * 1000;

(function() {
	function isValidHourRange(start, end) {
		return isValidHour(start) && isValidHour(end);
	}
	function isValidHour(number) {
		return isInt(number) && isInHourRange(number);
	}
	function isInt(number) {
		return number === parseInt(number, 10);
	}
	function isInHourRange(number) {
		return number >= 0 && number < 24;
	}
	function locationExistsIn(loc, locs) {
		return locs.indexOf(loc) !== -1;
	}
	function isValidTimeRange(start, end) {
		var correctTypes = start instanceof Date && end instanceof Date;
		var correctOrder = start < end;
		return correctTypes && correctOrder;
	}
	function getDurationHours(startHour, endHour) {
		return endHour >= startHour ? endHour - startHour : 24 + endHour - startHour;
	}
	function getScopeDurationHours(scope) {
		// A scope from an hour to the same hour covers the whole day.
		return getDurationHours(scope.hourStart, scope.hourEnd) || 24;
	}

	/*
	 * Multi-day layout. Each day shows the scope (e.g. 09:00 to 02:00 the next
	 * morning); the hours outside the scope are left out, so the days sit
	 * directly next to each other on one horizontal timeline.
	 */

	// Start of the displayed day that `date` belongs to: the latest scope start at or before it.
	function getDayStart(date, scope) {
		var dayStart = new Date(date.getTime());
		dayStart.setHours(scope.hourStart, 0, 0, 0);
		if (dayStart > date) {
			dayStart.setDate(dayStart.getDate() - 1);
		}
		return dayStart;
	}
	function computeDays(events, scope, now) {
		var first, last;
		if (events.length === 0) {
			first = last = getDayStart(now, scope);
		} else {
			var minStart = Math.min.apply(null, events.map(function(event) { return event.startDate.getTime(); }));
			// End times are exclusive: an event ending exactly at 09:00 doesn't need the next day.
			var maxEnd = Math.max.apply(null, events.map(function(event) { return event.endDate.getTime() - 1; }));
			first = getDayStart(new Date(minStart), scope);
			last = getDayStart(new Date(maxEnd), scope);
		}

		var days = [];
		// setDate keeps the local start hour across daylight saving changes.
		for (var day = new Date(first.getTime()); day <= last; day.setDate(day.getDate() + 1)) {
			days.push(new Date(day.getTime()));
		}
		return days;
	}
	function isNarrowScreen() {
		return typeof window.matchMedia === 'function' && window.matchMedia(Timetable.NARROW_SCREEN).matches;
	}
	function createLayout(timetable, now) {
		var scopeHours = getScopeDurationHours(timetable.scope);
		var days = computeDays(timetable.events, timetable.scope, now);
		var hourWidth = isNarrowScreen() ? Timetable.HOUR_WIDTH_NARROW : Timetable.HOUR_WIDTH;
		var dayWidth = scopeHours * hourWidth;
		var layout = {
			hourWidth: hourWidth,
			days: days,
			scopeHours: scopeHours,
			dayWidth: dayWidth,
			width: days.length * dayWidth,
			// Horizontal position in pixels of a point in time. Times outside the
			// scope (e.g. at night) are placed at the end of their day.
			positionOf: function(date) {
				var dayStart = getDayStart(date, timetable.scope);
				var index = -1;
				for (var k = 0; k < days.length; k++) {
					if (days[k].getTime() === dayStart.getTime()) {
						index = k;
						break;
					}
				}
				if (index === -1) {
					return date < days[0] ? 0 : layout.width;
				}
				var hoursIntoDay = Math.min((date - dayStart) / 3600000, scopeHours);
				return index * dayWidth + hoursIntoDay * hourWidth;
			},
			isInRange: function(date) {
				var start = days[0];
				var end = new Date(days[days.length - 1].getTime() + scopeHours * 3600000);
				return date >= start && date <= end;
			}
		};
		return layout;
	}

	Timetable.prototype = {
		setScope: function(start, end) {
			if (isValidHourRange(start, end)) {
				this.scope.hourStart = start;
				this.scope.hourEnd = end;
			} else {
				throw new RangeError('Timetable scope should consist of (start, end) in whole hours from 0 to 23');
			}

			return this;
		},
		useTwelveHour: function(){
			this.usingTwelveHour = true;
		},
		setLocale: function(locale) {
			this.locale = locale;
			return this;
		},
		addLocations: function(newLocations) {
			function hasProperFormat() {
				return newLocations instanceof Array;
			}

			var existingLocations = this.locations;

			if (hasProperFormat()) {
				newLocations.forEach(function(loc) {
					if (!locationExistsIn(loc, existingLocations)) {
						existingLocations.push(loc);
					} else {
						throw new Error('Location already exists');
					}
				});
			} else {
				throw new Error('Tried to add locations in wrong format');
			}

			return this;
		},
		setLocationLink: function(location, url) {
			if (!locationExistsIn(location, this.locations)) {
				throw new Error('Unknown location');
			}

			this.locationLinks[location] = {
				url: url
			};

			return this;
		},
		setLocationInfoContainer: function(selector) {
			if (typeof selector !== 'string' || selector === '') {
				throw new Error('Location info container should be a non-empty selector string');
			}

			this.locationInfoContainer = selector;

			return this;
		},
		setLocationInfo: function(location, info) {
			if (!locationExistsIn(location, this.locations)) {
				throw new Error('Unknown location');
			}

			if (this.locationLinks[location] === undefined) {
				this.locationLinks[location] = {};
			}
			this.locationLinks[location].info = info;

			return this;
		},
		addEvent: function(name, location, start, end, options) {
			if (!locationExistsIn(location, this.locations)) {
				throw new Error('Unknown location');
			}
			if (!isValidTimeRange(start, end)) {
				throw new Error('Invalid time range: ' + JSON.stringify([start, end]));
			}

			var optionsHasValidType = Object.prototype.toString.call(options) === '[object Object]';

			this.events.push({
				name: name,
				location: location,
				startDate: start,
				endDate: end,
				options: optionsHasValidType ? options : undefined
			});

			return this;
		}
	};

	function emptyNode(node) {
		while (node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	function prettyFormatTime(date, usingTwelveHour) {
		var hour = date.getHours();
		var minutes = (date.getMinutes() < 10 ? '0' : '') + date.getMinutes();
		if (usingTwelveHour) {
			return ((hour + 11) % 12 + 1) + ':' + minutes + (hour >= 12 ? 'PM' : 'AM');
		}
		return (hour < 10 ? '0' : '') + hour + ':' + minutes;
	}

	function prettyFormatDay(date, locale, long) {
		return date.toLocaleDateString(locale, long ?
			{ weekday: 'long', day: 'numeric', month: 'long' } :
			{ weekday: 'short', day: 'numeric', month: 'short' });
	}

	function prettyFormatHour(hour, usingTwelveHour) {
		var prettyHour;
			if(usingTwelveHour) {
					var period = hour >= 12 ? 'PM':'AM';
					prettyHour = ((hour + 11) % 12 + 1) + ':00' + period;
			} else {
					var prefix = hour < 10 ? '0' : '';
					prettyHour = prefix + hour + ':00';
			}
		return prettyHour;
	}

	Timetable.Renderer.prototype = {
		draw: function(selector) {
      var timetable = this.timetable;

			function checkContainerPrecondition(container) {
				if (container === null) {
					throw new Error('Timetable container not found');
				}
			}
			function appendTimetableAside(container) {
				var asideNode = container.appendChild(document.createElement('aside'));
				var asideULNode = asideNode.appendChild(document.createElement('ul'));
				appendRowHeaders(asideULNode);
			}
			/*
			 * Puts overlapping events of each location into lanes: events sorted by
			 * start go into the first lane that is free by then. Returns, per location,
			 * the number of lanes, and the lane of each event (by its index).
			 */
			function computeLanes() {
				var result = { lanesByLocation: {}, laneByEvent: [] };
				timetable.locations.forEach(function(location) {
					var indexes = [];
					timetable.events.forEach(function(event, index) {
						if (event.location === location) {
							indexes.push(index);
						}
					});
					indexes.sort(function(a, b) {
						return timetable.events[a].startDate - timetable.events[b].startDate ||
							timetable.events[a].endDate - timetable.events[b].endDate;
					});

					var laneEnds = [];
					indexes.forEach(function(index) {
						var event = timetable.events[index];
						var lane = 0;
						while (lane < laneEnds.length && laneEnds[lane] > event.startDate) {
							lane++;
						}
						laneEnds[lane] = event.endDate;
						result.laneByEvent[index] = lane;
					});
					result.lanesByLocation[location] = Math.max(laneEnds.length, 1);
				});
				return result;
			}
			function rowHeight(location) {
				return lanes.lanesByLocation[location] * Timetable.ROW_HEIGHT + 'px';
			}
			function appendRowHeaders(ulNode) {
				for (var k=0; k<timetable.locations.length; k++) {
					var liNode = ulNode.appendChild(document.createElement('li'));
					var location = timetable.locations[k];
					liNode.style.height = rowHeight(location);
					if (hasLocationInfo(location)) {
						appendLocationInfoLink(liNode, location);
					} else {
						appendRowHeading(liNode, location);
					}
				}
			}
			function hasLocationInfo(location) {
				var links = timetable.locationLinks[location];
				var hasLinks = links !== undefined && (links.info !== undefined || links.url !== undefined);
				return hasLinks || getLocationEvents(location).length > 0;
			}
			function getLocationEvents(location) {
				return timetable.events
					.filter(function(event) { return event.location === location; })
					.sort(function(a, b) { return a.startDate - b.startDate; });
			}
			function appendLocationEventList(infoNode, events) {
				var listNode = infoNode.appendChild(document.createElement('ul'));
				listNode.className = 'location-event-list';
				events.forEach(function(event) {
					var itemNode = listNode.appendChild(document.createElement('li'));
					trackEventNode(event, itemNode);
					var timeNode = itemNode.appendChild(document.createElement('span'));
					timeNode.className = 'location-event-time';
					timeNode.textContent = prettyFormatDay(event.startDate, timetable.locale, false) + ', ' +
						prettyFormatTime(event.startDate, timetable.usingTwelveHour) + ' – ' +
						prettyFormatTime(event.endDate, timetable.usingTwelveHour);
					itemNode.appendChild(document.createTextNode(' '));

					var hasURL = event.options !== undefined && event.options.url !== undefined;
					var nameNode = itemNode.appendChild(document.createElement(hasURL ? 'a' : 'span'));
					nameNode.textContent = event.name;
					if (hasURL) {
						nameNode.href = event.options.url;
					}

					if (event.options !== undefined && isValidRegistration(event.options.registered, event.options.capacity)) {
						var countNode = itemNode.appendChild(document.createElement('span'));
						countNode.className = 'location-event-registration';
						countNode.textContent = ' (' + event.options.registered + '/' + event.options.capacity + ' registered)';
					}
				});
			}
			function appendRowHeading(node, location) {
				var locationNode = node.appendChild(document.createElement('span'));
				locationNode.className = 'row-heading';
				locationNode.textContent = location;
			}
			function appendLocationInfoLink(node, location) {
				// The whole row is the link, so it is easy to hit and clearly clickable.
				node.className = 'has-location-info';
				var rowLinkNode = node.appendChild(document.createElement('a'));
				rowLinkNode.className = 'location-info-link';
				rowLinkNode.href = '#' + getLocationInfoId(location);
				rowLinkNode.title = 'Show info for ' + location;
				appendRowHeading(rowLinkNode, location);
				var chevronNode = rowLinkNode.appendChild(document.createElement('span'));
				chevronNode.className = 'location-info-chevron';
				chevronNode.setAttribute('aria-hidden', 'true');
				chevronNode.textContent = '›';
			}
			function getLocationInfoId(location) {
				return 'location-info-' + timetable.locations.indexOf(location);
			}
			function appendLocationInfoSection(container) {
				var targetContainer = null;
				if (timetable.locationInfoContainer !== undefined) {
					targetContainer = document.querySelector(timetable.locationInfoContainer);
					if (targetContainer === null) {
						throw new Error('Location info container not found');
					}
					emptyNode(targetContainer);
				} else {
					var existingInfoSection = container.nextSibling;
					if (existingInfoSection !== null && existingInfoSection.className === 'location-info-list') {
						container.parentNode.removeChild(existingInfoSection);
					}
				}

				var infoSection = document.createElement('section');
				infoSection.className = 'location-info-list';

				for (var k = 0; k < timetable.locations.length; k++) {
					var location = timetable.locations[k];
					if (!hasLocationInfo(location)) {
						continue;
					}
					var links = timetable.locationLinks[location] || {};
					var locationEvents = getLocationEvents(location);

					var infoNode = infoSection.appendChild(document.createElement('article'));
					infoNode.id = getLocationInfoId(location);
					var headingNode = infoNode.appendChild(document.createElement('h2'));
					headingNode.textContent = location;
					if (links.url !== undefined) {
						var locationLinkNode = infoNode.appendChild(document.createElement('p'));
						var locationAnchorNode = locationLinkNode.appendChild(document.createElement('a'));
						locationAnchorNode.className = 'external-link';
						locationAnchorNode.textContent = 'Map & directions';
						locationAnchorNode.href = links.url;
					}
					if (links.info !== undefined) {
						var textNode = infoNode.appendChild(document.createElement('p'));
						textNode.textContent = links.info;
					}
					if (locationEvents.length > 0) {
						appendLocationEventList(infoNode, locationEvents);
					}
				}

				if (targetContainer !== null) {
					targetContainer.appendChild(infoSection);
				} else if (infoSection.childNodes.length > 0) {
					container.parentNode.insertBefore(infoSection, container.nextSibling);
				}
			}
			function appendTimetableSection(container) {
				var sectionNode = container.appendChild(document.createElement('section'));
				var headerNode = appendColumnHeaders(sectionNode);
				var timeNode = sectionNode.appendChild(document.createElement('time'));
				var canvasNode = timeNode.appendChild(document.createElement('div'));
				canvasNode.className = 'timeline-canvas';
				canvasNode.style.width = layout.width + 'px';
				appendTimeRows(canvasNode);
				appendDayDividers(canvasNode);

				nowNodes.pastShade = canvasNode.appendChild(document.createElement('div'));
				nowNodes.pastShade.className = 'past-shade';
				nowNodes.line = canvasNode.appendChild(document.createElement('div'));
				nowNodes.line.className = 'now-line';
				nowNodes.line.setAttribute('aria-hidden', 'true');

				// The header can't be scrolled by itself; it follows the timeline.
				timeNode.addEventListener('scroll', function() {
					headerNode.scrollLeft = timeNode.scrollLeft;
				});
				renderer.scrollNode = timeNode;
			}
			function appendColumnHeaders(node) {
				var headerNode = node.appendChild(document.createElement('header'));
				var dayLabelsNode = headerNode.appendChild(document.createElement('div'));
				dayLabelsNode.className = 'day-labels';
				dayLabelsNode.style.width = layout.width + 'px';
				var headerULNode = headerNode.appendChild(document.createElement('ul'));
				headerULNode.style.width = layout.width + 'px';

				layout.days.forEach(function(day) {
					var dayNode = dayLabelsNode.appendChild(document.createElement('div'));
					dayNode.className = 'day-label';
					dayNode.style.width = layout.dayWidth + 'px';
					dayNode.appendChild(document.createElement('span')).textContent =
						prettyFormatDay(day, timetable.locale, true);

					for (var k = 0; k < layout.scopeHours; k++) {
						var liNode = headerULNode.appendChild(document.createElement('li'));
						if (k === 0) {
							liNode.className = 'day-start';
						}
						var spanNode = liNode.appendChild(document.createElement('span'));
						spanNode.className = 'time-label';
						spanNode.textContent = prettyFormatHour((timetable.scope.hourStart + k) % 24, timetable.usingTwelveHour);
					}
				});

				// Closing label at the end of the last day.
				var endNode = headerULNode.appendChild(document.createElement('li'));
				var endLabelNode = endNode.appendChild(document.createElement('span'));
				endLabelNode.className = 'time-label';
				endLabelNode.textContent = prettyFormatHour(timetable.scope.hourEnd, timetable.usingTwelveHour);

				nowNodes.marker = headerNode.appendChild(document.createElement('span'));
				nowNodes.marker.className = 'now-marker';

				return headerNode;
			}
			function appendDayDividers(node) {
				for (var k = 1; k < layout.days.length; k++) {
					var dividerNode = node.appendChild(document.createElement('div'));
					dividerNode.className = 'day-divider';
					dividerNode.style.left = k * layout.dayWidth + 'px';
				}
			}
			function appendTimeRows(node) {
				var ulNode = node.appendChild(document.createElement('ul'));
				ulNode.className = 'room-timeline';
				for (var k=0; k<timetable.locations.length; k++) {
					var liNode = ulNode.appendChild(document.createElement('li'));
					liNode.style.height = rowHeight(timetable.locations[k]);
					if (lanes.lanesByLocation[timetable.locations[k]] > 1) {
						liNode.classList.add('has-overlaps');
					}
					appendLocationEvents(timetable.locations[k], liNode);
				}
			}
			function appendLocationEvents(location, node) {
				for (var k=0; k<timetable.events.length; k++) {
					var event = timetable.events[k];
					if (event.location === location) {
						appendEvent(event, node, lanes.laneByEvent[k]);
					}
				}
			}
			function appendEvent(event, node, lane) {
				var hasOptions = event.options !== undefined;
				var hasURL, hasAdditionalClass, hasDataAttributes, hasClickHandler = false;

				if (hasOptions) {
					hasURL = event.options.url !== undefined;
					hasAdditionalClass = event.options.class !== undefined;
					hasDataAttributes = event.options.data !== undefined;
					hasClickHandler = event.options.onClick !== undefined;
				}

				var elementType = hasURL ? 'a' : 'span';
				var eventNode = node.appendChild(document.createElement(elementType));
				var smallNode = eventNode.appendChild(document.createElement('small'));
				eventNode.title = event.name;

				if (hasURL) {
					eventNode.href = event.options.url;
				}

				if (hasDataAttributes){
					for (var key in event.options.data) {
						eventNode.setAttribute('data-'+key, event.options.data[key]);
					}
				}

				if (hasClickHandler) {
				  eventNode.addEventListener('click', function(e) {
				    event.options.onClick(event, timetable, e);
          });
        }

				eventNode.className = hasAdditionalClass ? 'time-entry ' + event.options.class : 'time-entry';
				var left = layout.positionOf(event.startDate);
				eventNode.style.left = left + 'px';
				eventNode.style.top = lane * Timetable.ROW_HEIGHT + 'px';
				eventNode.style.width = Math.max(layout.positionOf(event.endDate) - left, 0) + 'px';
				// The top line holds the name and, if known, the count next to it.
				var nameNode = smallNode.appendChild(document.createElement('span'));
				nameNode.className = 'time-entry-name';
				nameNode.textContent = event.name;
				trackEventNode(event, eventNode);

				var hasCapacity = hasOptions && isValidRegistration(event.options.registered, event.options.capacity);
				if (hasCapacity) {
					appendRegistrationBar(event, eventNode);
				}
				// Optional minimum of people needed: marks whether it is reached
				// (styled lighter in plugin.css), so events still needing help stand out.
				if (hasOptions && hasMinimum(event)) {
					var reached = (event.options.registered || 0) >= event.options.minimum;
					eventNode.className += reached ? ' is-minimum-reached' : ' is-below-minimum';
					eventNode.title += reached ? ' – minimum of ' + event.options.minimum + ' reached' :
						' – at least ' + event.options.minimum + ' needed';
				}
				if (hasCapacity || (hasOptions && hasMinimum(event) && typeof event.options.registered === 'number')) {
					appendRegistrationCount(event, eventNode, smallNode);
				}
			}
			// "5/15", with the minimum if there is one: "5/15 · min 5" (or "2 · min 5" without a maximum).
			function appendRegistrationCount(event, eventNode, smallNode) {
				var text = String(event.options.registered);
				if (isValidRegistration(event.options.registered, event.options.capacity)) {
					text += '/' + event.options.capacity;
				}
				if (hasMinimum(event)) {
					text += ' (min. ' + event.options.minimum + ')';
				}
				eventNode.className += ' has-count';
				var countNode = smallNode.appendChild(document.createElement('span'));
				countNode.className = 'registration-count';
				countNode.textContent = text;
			}
			function isValidRegistration(registered, capacity) {
				return typeof registered === 'number' && typeof capacity === 'number' &&
					registered >= 0 && capacity > 0;
			}
			function hasMinimum(event) {
				return typeof event.options.minimum === 'number' && event.options.minimum > 0;
			}
			function appendRegistrationBar(event, eventNode) {
				var registered = event.options.registered;
				var capacity = event.options.capacity;
				var ratio = Math.min(registered / capacity, 1);
				var countText = registered + '/' + capacity;

				eventNode.className += ' has-registration';
				if (registered >= capacity) {
					eventNode.className += ' is-full';
				}
				eventNode.title = event.name + ' (' + countText + ' registered)';

				var barNode = eventNode.appendChild(document.createElement('span'));
				barNode.className = 'registration-bar';
				barNode.setAttribute('role', 'meter');
				barNode.setAttribute('aria-valuemin', '0');
				barNode.setAttribute('aria-valuemax', String(capacity));
				barNode.setAttribute('aria-valuenow', String(registered));
				barNode.setAttribute('aria-label', countText + ' registered');

				var fillNode = barNode.appendChild(document.createElement('span'));
				fillNode.className = 'registration-bar-fill';
				fillNode.style.width = ratio * 100 + '%';

				// A small mark where the minimum is.
				if (hasMinimum(event) && event.options.minimum < capacity) {
					var minimumNode = barNode.appendChild(document.createElement('span'));
					minimumNode.className = 'registration-bar-minimum';
					minimumNode.style.left = event.options.minimum / capacity * 100 + '%';
				}
			}
			// Nodes that get marked as past/current as time goes on.
			function trackEventNode(event, node) {
				trackedEvents.push({ event: event, node: node });
			}

			var renderer = this;
			var trackedEvents = [];
			var nowNodes = {};
			var layout = createLayout(timetable, new Date());
			var lanes = computeLanes();
			var container = document.querySelector(selector);
			checkContainerPrecondition(container);
			emptyNode(container);
			container.style.setProperty('--hour-width', layout.hourWidth + 'px');
			appendTimetableAside(container);
			appendTimetableSection(container);
			appendLocationInfoSection(container);

			this.layout = layout;
			this.trackedEvents = trackedEvents;
			this.nowNodes = nowNodes;
			this.updateNow();
			if (this.timer !== null) {
				clearInterval(this.timer);
			}
			this.timer = setInterval(this.updateNow.bind(this), Timetable.NOW_UPDATE_INTERVAL);
			this.watchScreenWidth(selector);
		},

		// Redraws with the matching hour width when the screen gets narrow or wide
		// (e.g. when a tablet is rotated).
		watchScreenWidth: function(selector) {
			this.selector = selector;
			if (this.mediaListener !== null || typeof window.matchMedia !== 'function') {
				return;
			}
			var renderer = this;
			var query = window.matchMedia(Timetable.NARROW_SCREEN);
			this.mediaListener = function() {
				renderer.draw(renderer.selector);
				renderer.scrollToNow();
			};
			if (query.addEventListener) {
				query.addEventListener('change', this.mediaListener);
			} else {
				query.addListener(this.mediaListener); // older Safari
			}
		},

		// Moves the current-time line and marks events as past or current.
		updateNow: function() {
			var now = new Date();
			var nodes = this.nowNodes;
			var inRange = this.layout.isInRange(now);
			var x = this.layout.positionOf(now);

			nodes.line.hidden = !inRange;
			nodes.marker.hidden = !inRange;
			nodes.line.style.left = x + 'px';
			nodes.marker.style.left = x + 'px';
			nodes.marker.textContent = prettyFormatTime(now, this.timetable.usingTwelveHour);
			nodes.pastShade.style.width = x + 'px';

			this.trackedEvents.forEach(function(tracked) {
				var isPast = tracked.event.endDate <= now;
				var isCurrent = !isPast && tracked.event.startDate <= now;
				tracked.node.classList.toggle('is-past', isPast);
				tracked.node.classList.toggle('is-current', isCurrent);
			});
		},

		/*
		 * Scrolls the timeline so that a little of the past is visible and
		 * mostly what is happening now and next. Returns false while the
		 * timeline is hidden (it can't be scrolled then), so it can be retried.
		 */
		scrollToNow: function() {
			var scrollNode = this.scrollNode;
			if (!scrollNode || scrollNode.clientWidth === 0) {
				return false;
			}
			var pastWidth = Math.min(this.layout.hourWidth, scrollNode.clientWidth * 0.2);
			scrollNode.scrollLeft = Math.max(0, this.layout.positionOf(new Date()) - pastWidth);
			return true;
		}
	};

})();
