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
	// Called as handler(location, start, end) when a time span is dragged in a row
	// (see setRangeSelectHandler); null: dragging does nothing.
	this.rangeSelectHandler = null;
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
	// Long breaks the user expanded, by their start time (see createLayout).
	this.expandedGaps = {};
};

// Width of one hour in the timeline, in pixels. On narrow screens hours are
// narrower, so more of the day fits. The grid in plugin.css follows via --hour-width.
Timetable.HOUR_WIDTH = 96;
Timetable.HOUR_WIDTH_NARROW = 72;
// Height of one lane in a location's row, in pixels (matches plugin.css). Events
// that overlap in time at the same location are shown in lanes below each other.
Timetable.ROW_HEIGHT = 46;
// Name next to events too short to show it (see appendOverflowLabel): distance
// to the event, and the gap between name and count inside events (as in plugin.css).
Timetable.OVERFLOW_MARGIN = 6;
Timetable.OVERFLOW_GAP = 6;
// Lines an event name may take: one above a fill bar, otherwise two (as in plugin.css).
Timetable.NAME_LINES = { withBar: 1, withoutBar: 2 };
// Selecting a time span by dragging snaps to this step.
Timetable.SELECT_STEP_MINUTES = 15;
// Long breaks without any event are shortened to a narrow strip that expands when
// clicked: breaks of at least GAP_MIN_HOURS, keeping GAP_PADDING_HOURS visible
// next to the events around them, drawn GAP_WIDTH pixels wide.
Timetable.GAP_MIN_HOURS = 3;
Timetable.GAP_PADDING_HOURS = 1;
Timetable.GAP_WIDTH = 44;
Timetable.NARROW_SCREEN = '(max-width: 600px)';
// How often the current-time line and past/current markers are refreshed.
Timetable.NOW_UPDATE_INTERVAL = 30 * 1000;

// Texts shown by the timetable, in English. A page can translate them by
// setting Timetable.translate = function(key, params) { … } (see i18n.js).
Timetable.texts = {
	'timetable.showInfoFor': 'Show info for {location}',
	'timetable.mapDirections': 'Map & directions',
	'timetable.registeredTitle': '{name} ({count} registered)',
	'timetable.minimumReached': ' – minimum of {min} reached',
	'timetable.minimumNeeded': ' – at least {min} needed',
	'timetable.registeredCount': '({count} registered)',
	'timetable.gapHours': '{hours} h',
	'timetable.expandGap': 'No events from {from} to {to}. Click to show these hours.',
	'timetable.collapseGap': 'Hide the hours without events'
};
Timetable.translate = null;

Timetable.text = function(key, params) {
	if (typeof Timetable.translate === 'function') {
		return Timetable.translate(key, params);
	}
	return (Timetable.texts[key] || key).replace(/\{(\w+)\}/g, function(match, name) {
		return params && Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match;
	});
};

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
	var HOUR = 3600000;

	// The full hour at or before / at or after a point in time (in ms).
	function floorHour(time) {
		var date = new Date(time);
		date.setMinutes(0, 0, 0);
		return date.getTime();
	}
	function ceilHour(time) {
		var floored = floorHour(time);
		return floored === time ? time : floored + HOUR;
	}

	/*
	 * Long breaks: stretches of at least Timetable.GAP_MIN_HOURS in which no event
	 * takes place at any location (e.g. nights), within the displayed days. A
	 * little time next to the events around them stays visible. Times in ms, on
	 * full hours.
	 */
	function findGaps(events, rangeStart, rangeEnd) {
		var busy = events
			.map(function(event) { return { start: event.startDate.getTime(), end: event.endDate.getTime() }; })
			.sort(function(a, b) { return a.start - b.start; });
		var free = [];
		var freeFrom = rangeStart;
		busy.forEach(function(interval) {
			if (interval.start > freeFrom) {
				free.push({ start: freeFrom, end: interval.start });
			}
			freeFrom = Math.max(freeFrom, interval.end);
		});
		if (freeFrom < rangeEnd) {
			free.push({ start: freeFrom, end: rangeEnd });
		}

		var padding = Timetable.GAP_PADDING_HOURS * HOUR;
		return free.map(function(interval) {
			// No padding at the start and end of the timeline: nothing is next to it there.
			return {
				start: ceilHour(interval.start) + (interval.start === rangeStart ? 0 : padding),
				end: floorHour(interval.end) - (interval.end === rangeEnd ? 0 : padding)
			};
		}).filter(function(gap) {
			return gap.end - gap.start >= Timetable.GAP_MIN_HOURS * HOUR;
		});
	}

	/*
	 * The horizontal layout: a sequence of segments from the first displayed day
	 * to the last. Hours within the scope are hourWidth wide, a collapsed long
	 * break is one narrow strip, and hours outside the scope (e.g. at night with
	 * a scope of 09:00 to 17:00) take no room.
	 */
	function createLayout(timetable, now, expandedGaps) {
		var scopeHours = getScopeDurationHours(timetable.scope);
		var days = computeDays(timetable.events, timetable.scope, now);
		var hourWidth = isNarrowScreen() ? Timetable.HOUR_WIDTH_NARROW : Timetable.HOUR_WIDTH;
		var dayRanges = days.map(function(day) {
			var end = new Date(day.getTime());
			end.setHours(end.getHours() + scopeHours);
			return { start: day.getTime(), end: end.getTime() };
		});
		var rangeStart = dayRanges[0].start;
		var rangeEnd = dayRanges[dayRanges.length - 1].end;
		var gaps = findGaps(timetable.events, rangeStart, rangeEnd);
		gaps.forEach(function(gap) {
			gap.expanded = Boolean(expandedGaps[gap.start]);
		});
		var collapsed = gaps.filter(function(gap) { return !gap.expanded; });

		// Cut the timeline at every day and break boundary, then size each piece.
		var points = [];
		dayRanges.forEach(function(range) { points.push(range.start, range.end); });
		collapsed.forEach(function(gap) { points.push(gap.start, gap.end); });
		points = points.sort(function(a, b) { return a - b; }).filter(function(point, index, all) {
			return index === 0 || point !== all[index - 1];
		});
		var segments = [];
		var x = 0;
		var findCollapsed = function(start, end) {
			return collapsed.filter(function(candidate) { return candidate.start <= start && end <= candidate.end; })[0];
		};
		var isInScope = function(start, end) {
			return dayRanges.some(function(range) { return range.start <= start && end <= range.end; });
		};
		for (var k = 0; k + 1 < points.length; k++) {
			var start = points[k];
			var end = points[k + 1];
			var gap = findCollapsed(start, end);
			var last = segments[segments.length - 1];
			if (gap !== undefined) {
				if (last !== undefined && last.gap === gap) {
					last.end = end;
				} else {
					segments.push({ start: start, end: end, x: x, width: Timetable.GAP_WIDTH, gap: gap, visible: false });
					x += Timetable.GAP_WIDTH;
				}
				continue;
			}
			var inScope = isInScope(start, end);
			var width = inScope ? (end - start) / HOUR * hourWidth : 0;
			segments.push({ start: start, end: end, x: x, width: width, gap: null, visible: inScope });
			x += width;
		}

		var layout = {
			hourWidth: hourWidth,
			days: days,
			segments: segments,
			gaps: gaps,
			width: x,
			// Horizontal position in pixels of a point in time. Times outside the
			// scope (e.g. at night) are placed at the end of their day.
			positionOf: function(date) {
				var time = date.getTime();
				for (var k = 0; k < segments.length; k++) {
					var segment = segments[k];
					if (time < segment.end) {
						var into = Math.max(0, time - segment.start) / (segment.end - segment.start);
						return segment.x + into * segment.width;
					}
				}
				return layout.width;
			},
			// The point in time at a horizontal position (the opposite of positionOf).
			timeAt: function(x) {
				for (var k = 0; k < segments.length; k++) {
					var segment = segments[k];
					if (segment.width > 0 && x < segment.x + segment.width) {
						var into = Math.max(0, x - segment.x) / segment.width;
						return new Date(segment.start + Math.round(into * (segment.end - segment.start) / 60000) * 60000);
					}
				}
				return new Date(rangeEnd);
			},
			// Where a day begins; a day starting within a collapsed break begins after it.
			dayStartX: function(day) {
				var time = day.getTime();
				for (var k = 0; k < segments.length; k++) {
					var segment = segments[k];
					if (segment.gap && segment.start < time && time < segment.end) {
						return segment.x + segment.width;
					}
				}
				return layout.positionOf(day);
			},
			isInRange: function(date) {
				return date.getTime() >= rangeStart && date.getTime() <= rangeEnd;
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
		// Lets the user drag with the mouse across a row to select a time span
		// (snapped to Timetable.SELECT_STEP_MINUTES); handler(location, start, end)
		// is called when the mouse is released. null switches it off.
		setRangeSelectHandler: function(handler) {
			this.rangeSelectHandler = typeof handler === 'function' ? handler : null;
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
						countNode.textContent = ' ' + Timetable.text('timetable.registeredCount',
							{ count: event.options.registered + '/' + event.options.capacity });
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
				rowLinkNode.title = Timetable.text('timetable.showInfoFor', { location: location });
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
						locationAnchorNode.textContent = Timetable.text('timetable.mapDirections');
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
				appendGrid(canvasNode);
				appendDayDividers(canvasNode);
				appendGaps(canvasNode);

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

				layout.days.forEach(function(day, index) {
					var from = index === 0 ? 0 : layout.dayStartX(day);
					var to = index + 1 < layout.days.length ? layout.dayStartX(layout.days[index + 1]) : layout.width;
					// A day completely within a collapsed break isn't shown, so it gets no
					// label (its name would cover the next day's).
					if (to - from < 1) {
						return;
					}
					var dayNode = dayLabelsNode.appendChild(document.createElement('div'));
					dayNode.className = 'day-label';
					dayNode.style.width = to - from + 'px';
					dayNode.appendChild(document.createElement('span')).textContent =
						prettyFormatDay(day, timetable.locale, true);
				});

				var dayStarts = layout.days.map(function(day) { return day.getTime(); });
				var lastEnd = null;
				var afterGap = false;
				layout.segments.forEach(function(segment) {
					if (segment.gap) {
						// A collapsed break: room above its strip (which shows its length).
						var gapNode = headerULNode.appendChild(document.createElement('li'));
						gapNode.className = 'gap';
						gapNode.style.width = segment.width + 'px';
						lastEnd = segment.end;
						afterGap = true;
						return;
					}
					if (!segment.visible) {
						return;
					}
					for (var time = segment.start; time < segment.end; time += HOUR) {
						var liNode = headerULNode.appendChild(document.createElement('li'));
						if (dayStarts.indexOf(time) !== -1) {
							liNode.classList.add('day-start');
						}
						// Right after a break, the label starts at the line instead of
						// being centred on it, so it doesn't cover the break's length.
						if (afterGap) {
							liNode.classList.add('after-gap');
							afterGap = false;
						}
						var spanNode = liNode.appendChild(document.createElement('span'));
						spanNode.className = 'time-label';
						spanNode.textContent = prettyFormatHour(new Date(time).getHours(), timetable.usingTwelveHour);
					}
					lastEnd = segment.end;
				});

				// Closing label at the end of the timeline.
				var endNode = headerULNode.appendChild(document.createElement('li'));
				var endLabelNode = endNode.appendChild(document.createElement('span'));
				endLabelNode.className = 'time-label';
				endLabelNode.textContent = prettyFormatHour(new Date(lastEnd).getHours(), timetable.usingTwelveHour);

				nowNodes.marker = headerNode.appendChild(document.createElement('span'));
				nowNodes.marker.className = 'now-marker';

				return headerNode;
			}
			function appendDayDividers(node) {
				for (var k = 1; k < layout.days.length; k++) {
					var dividerNode = node.appendChild(document.createElement('div'));
					dividerNode.className = 'day-divider';
					dividerNode.style.left = layout.dayStartX(layout.days[k]) + 'px';
				}
			}
			// Lines every hour and quarter of an hour, in the displayed hours only.
			function appendGrid(node) {
				layout.segments.forEach(function(segment) {
					if (!segment.visible || segment.width === 0) {
						return;
					}
					var gridNode = node.appendChild(document.createElement('div'));
					gridNode.className = 'timeline-grid';
					gridNode.style.left = segment.x + 'px';
					gridNode.style.width = segment.width + 'px';
				});
			}
			// Collapsed breaks as narrow strips across all rows; expanded ones get a
			// small button to collapse them again.
			function appendGaps(node) {
				layout.segments.forEach(function(segment) {
					if (!segment.gap) {
						return;
					}
					var stripNode = node.appendChild(createGapButton(segment.gap));
					stripNode.classList.add('timeline-gap');
					stripNode.style.left = segment.x + 'px';
					stripNode.style.width = segment.width + 'px';
					var lengthNode = stripNode.appendChild(document.createElement('span'));
					lengthNode.className = 'timeline-gap-length';
					lengthNode.textContent = Timetable.text('timetable.gapHours',
						{ hours: Math.round((segment.gap.end - segment.gap.start) / HOUR) });
					stripNode.appendChild(document.createElement('span')).textContent = '↔';
				});
				layout.gaps.forEach(function(gap) {
					if (!gap.expanded) {
						return;
					}
					var collapseNode = node.appendChild(document.createElement('button'));
					collapseNode.type = 'button';
					collapseNode.className = 'timeline-gap-collapse';
					collapseNode.style.left = layout.positionOf(new Date(gap.start)) + 'px';
					collapseNode.textContent = '→←';
					collapseNode.title = Timetable.text('timetable.collapseGap');
					collapseNode.setAttribute('aria-label', collapseNode.title);
					collapseNode.addEventListener('click', function() {
						toggleGap(gap, false);
					});
				});
			}
			function createGapButton(gap) {
				var buttonNode = document.createElement('button');
				buttonNode.type = 'button';
				buttonNode.className = 'gap-button';
				var format = function(time) {
					var date = new Date(time);
					return prettyFormatDay(date, timetable.locale, false) + ', ' + prettyFormatTime(date, timetable.usingTwelveHour);
				};
				buttonNode.title = Timetable.text('timetable.expandGap', { from: format(gap.start), to: format(gap.end) });
				buttonNode.setAttribute('aria-label', buttonNode.title);
				buttonNode.addEventListener('click', function() {
					toggleGap(gap, true);
				});
				return buttonNode;
			}
			// Expands or collapses a break; the view stays where it is.
			function toggleGap(gap, expanded) {
				if (expanded) {
					renderer.expandedGaps[gap.start] = true;
				} else {
					delete renderer.expandedGaps[gap.start];
				}
				var scrollLeft = renderer.scrollNode.scrollLeft;
				renderer.draw(renderer.selector);
				renderer.scrollNode.scrollLeft = scrollLeft;
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
					if (timetable.rangeSelectHandler !== null) {
						enableRangeSelect(timetable.locations[k], liNode);
					}
				}
			}
			/*
			 * Dragging with the mouse across empty space in a row selects a time span,
			 * shown while dragging and snapped to whole steps (e.g. 15 minutes). Touch
			 * input is left alone, so the timeline can still be scrolled on phones.
			 */
			function enableRangeSelect(location, rowNode) {
				var step = Timetable.SELECT_STEP_MINUTES * 60000;
				// The nearest whole step (in time) to a position.
				var snap = function(x) {
					var time = new Date(Math.round(layout.timeAt(x).getTime() / step) * step);
					return { x: layout.positionOf(time), time: time };
				};
				var drag = null;

				rowNode.classList.add('is-selectable');
				rowNode.addEventListener('pointerdown', function(e) {
					var onEvent = e.target.closest && e.target.closest('.time-entry, .time-entry-overflow');
					if (e.pointerType === 'touch' || e.button !== 0 || onEvent) {
						return;
					}
					e.preventDefault();
					var x = e.clientX - rowNode.getBoundingClientRect().left;
					drag = { from: x, to: x, node: rowNode.appendChild(document.createElement('span')) };
					drag.node.className = 'range-selection';
					drag.node.hidden = true;
					rowNode.setPointerCapture(e.pointerId);
				});
				rowNode.addEventListener('pointermove', function(e) {
					if (drag === null) {
						return;
					}
					drag.to = e.clientX - rowNode.getBoundingClientRect().left;
					var span = selectedSpan();
					drag.node.hidden = span === null;
					if (span !== null) {
						drag.node.style.left = span.left + 'px';
						drag.node.style.width = span.right - span.left + 'px';
						drag.node.textContent = prettyFormatTime(span.start, timetable.usingTwelveHour) + ' – ' +
							prettyFormatTime(span.end, timetable.usingTwelveHour);
					}
				});
				var finish = function(e) {
					if (drag === null) {
						return;
					}
					var span = e.type === 'pointerup' ? selectedSpan() : null;
					rowNode.removeChild(drag.node);
					drag = null;
					// A click without dragging selects nothing.
					if (span !== null) {
						timetable.rangeSelectHandler(location, span.start, span.end);
					}
				};
				rowNode.addEventListener('pointerup', finish);
				rowNode.addEventListener('pointercancel', finish);

				// The dragged span, snapped; null if it is shorter than one step.
				function selectedSpan() {
					var left = snap(Math.min(drag.from, drag.to));
					var right = snap(Math.max(drag.from, drag.to));
					if (right.time - left.time < step) {
						return null;
					}
					return { left: left.x, right: right.x, start: left.time, end: right.time };
				}
			}
			function appendLocationEvents(location, node) {
				var drawn = [];
				for (var k=0; k<timetable.events.length; k++) {
					var event = timetable.events[k];
					if (event.location === location) {
						drawn.push({ event: event, lane: lanes.laneByEvent[k], node: appendEvent(event, node, lanes.laneByEvent[k]) });
					}
				}
				drawn.forEach(function(entry) {
					appendOverflowLabel(entry, drawn, node);
				});
			}
			/*
			 * An event too short to show its whole name gets the name next to it, if the
			 * space there is free: to the right of the event, otherwise to the left of it.
			 * The count stays in the event only (it takes its room first, so it is shown
			 * there whenever the name is shortened).
			 */
			function appendOverflowLabel(entry, drawn, rowNode) {
				var eventNode = entry.node;
				var nameNode = eventNode.querySelector('.time-entry-name');
				var countNode = eventNode.querySelector('.registration-count');
				var isMine = eventNode.classList.contains('is-mine');
				var nameFont = fontOf(nameNode);
				var textWidth = measureText(entry.event.name, nameFont) +
					(isMine ? measureText('✓ ', fontOf(nameNode, '700')) : 0);
				var start = layout.positionOf(entry.event.startDate);
				var end = layout.positionOf(entry.event.endDate);
				// Room for the name: without padding (10px each side), border, a little slack
				// for rounding, and what the check mark and the count take beside it.
				var nameRoom = end - start - 24 -
					(isMine ? measureText('✓', fontOf(nameNode, '700')) + Timetable.OVERFLOW_GAP : 0) -
					(countNode !== null ? Timetable.OVERFLOW_GAP + measureText(countNode.textContent, fontOf(countNode)) : 0);
				var maxLines = eventNode.classList.contains('has-registration') ?
					Timetable.NAME_LINES.withBar : Timetable.NAME_LINES.withoutBar;
				if (nameRoom > 0 && linesNeeded(entry.event.name, nameFont, nameRoom) <= maxLines) {
					return;
				}

				// Free space up to the neighbouring events in the same lane.
				var freeLeft = start;
				var freeRight = layout.width - end;
				drawn.forEach(function(other) {
					if (other === entry || other.lane !== entry.lane) {
						return;
					}
					var otherStart = layout.positionOf(other.event.startDate);
					var otherEnd = layout.positionOf(other.event.endDate);
					if (otherStart >= end) {
						freeRight = Math.min(freeRight, otherStart - end);
					} else if (otherEnd <= start) {
						freeLeft = Math.min(freeLeft, start - otherEnd);
					}
				});
				var needed = textWidth + 2 * Timetable.OVERFLOW_MARGIN + 4;
				var side = freeRight >= needed ? 'right' : freeLeft >= needed ? 'left' : null;
				if (side === null) {
					return;
				}

				var labelNode = rowNode.appendChild(document.createElement('span'));
				labelNode.className = 'time-entry-overflow is-' + side +
					(isMine ? ' is-mine' : '') +
					(eventNode.classList.contains('has-registration') ? ' has-registration' : '');
				labelNode.setAttribute('aria-hidden', 'true');
				labelNode.title = eventNode.title;
				labelNode.style.top = entry.lane * Timetable.ROW_HEIGHT + 'px';
				if (side === 'right') {
					labelNode.style.left = end + Timetable.OVERFLOW_MARGIN + 'px';
				} else {
					labelNode.style.right = layout.width - start + Timetable.OVERFLOW_MARGIN + 'px';
				}
				// The name is drawn in front of collapsed breaks; where it crosses one, a
				// white halo keeps the break's edge lines off the letters.
				var labelLeft = side === 'right' ? end + Timetable.OVERFLOW_MARGIN : start - Timetable.OVERFLOW_MARGIN - textWidth;
				var labelRight = labelLeft + textWidth;
				var crossesGap = layout.segments.some(function(segment) {
					return segment.gap && segment.x < labelRight && segment.x + segment.width > labelLeft;
				});
				if (crossesGap) {
					labelNode.classList.add('is-over-gap');
				}
				labelNode.appendChild(document.createElement('span')).textContent = entry.event.name;

				trackEventNode(entry.event, labelNode);
			}
			// The font of a node as a canvas font string, optionally with another weight.
			// Works while the timetable is hidden, as no layout is needed.
			function fontOf(node, weight) {
				var style = window.getComputedStyle(node);
				return style.fontStyle + ' ' + (weight || style.fontWeight) + ' ' + style.fontSize + ' ' + style.fontFamily;
			}
			// Lines a text takes when wrapped at spaces to the given width (words longer
			// than a line are broken, like overflow-wrap: anywhere in plugin.css).
			function linesNeeded(text, font, width) {
				var spaceWidth = measureText(' ', font);
				var lines = 0;
				var lineWidth = width;
				text.split(/\s+/).filter(Boolean).forEach(function(word) {
					var wordWidth = measureText(word, font);
					if (lines > 0 && lineWidth + spaceWidth + wordWidth <= width) {
						lineWidth += spaceWidth + wordWidth;
						return;
					}
					var wordLines = Math.max(1, Math.ceil(wordWidth / width));
					lines += wordLines;
					lineWidth = wordWidth - (wordLines - 1) * width;
				});
				return Math.max(lines, 1);
			}
			function measureText(text, font) {
				var context = measureText.context ||
					(measureText.context = document.createElement('canvas').getContext('2d'));
				context.font = font;
				return Math.ceil(context.measureText(text).width);
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
					eventNode.title += Timetable.text(reached ? 'timetable.minimumReached' : 'timetable.minimumNeeded',
						{ min: event.options.minimum });
				}
				if (hasCapacity || (hasOptions && hasMinimum(event) && typeof event.options.registered === 'number')) {
					appendRegistrationCount(event, eventNode, smallNode);
				}
				return eventNode;
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
				eventNode.title = Timetable.text('timetable.registeredTitle', { name: event.name, count: countText });

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
			this.selector = selector;
			var layout = createLayout(timetable, new Date(), this.expandedGaps);
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
			var position = this.nowScrollPosition();
			if (position === null) {
				return false;
			}
			this.scrollNode.scrollLeft = position;
			return true;
		},

		// The default scroll position (a little past, mostly now and next), or null
		// while the timetable is hidden.
		nowScrollPosition: function() {
			var scrollNode = this.scrollNode;
			if (!scrollNode || scrollNode.clientWidth === 0) {
				return null;
			}
			var pastWidth = Math.min(this.layout.hourWidth, scrollNode.clientWidth * 0.2);
			return Math.max(0, Math.min(this.layout.positionOf(new Date()) - pastWidth,
				scrollNode.scrollWidth - scrollNode.clientWidth));
		},

		/*
		 * Scrolls back to the default position if the view starts earlier than it,
		 * i.e. shows mostly the past. A view scrolled ahead into the future is kept.
		 * Returns false while the timetable is hidden.
		 */
		scrollToNowIfInPast: function() {
			var position = this.nowScrollPosition();
			if (position === null) {
				return false;
			}
			// A few pixels of tolerance for rounding.
			if (this.scrollNode.scrollLeft < position - 4) {
				this.scrollNode.scrollLeft = position;
			}
			return true;
		}
	};

})();
