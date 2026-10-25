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
	this.events = [];
};

Timetable.Renderer = function(tt) {
	if (!(tt instanceof Timetable)) {
		throw new Error('Initialize renderer using a Timetable');
	}
	this.timetable = tt;
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
			function appendRowHeaders(ulNode) {
				for (var k=0; k<timetable.locations.length; k++) {
					var liNode = ulNode.appendChild(document.createElement('li'));
					var location = timetable.locations[k];
					var locationNode = liNode.appendChild(document.createElement('span'));
					locationNode.className = 'row-heading';
					locationNode.textContent = location;
					var links = timetable.locationLinks[location];
					if (links !== undefined && links.info !== undefined) {
						appendLocationInfoLink(liNode, location);
					}
				}
			}
			function appendLocationInfoLink(node, location) {
				var infoLinkNode = node.appendChild(document.createElement('a'));
				infoLinkNode.className = 'location-info-link';
				infoLinkNode.textContent = '> More Info';
				infoLinkNode.href = '#' + getLocationInfoId(location);
			}
			function getLocationInfoId(location) {
				return 'location-info-' + timetable.locations.indexOf(location);
			}
			function appendLocationInfoSection(container) {
				var existingInfoSection = container.nextSibling;
				if (existingInfoSection !== null && existingInfoSection.className === 'location-info-list') {
					container.parentNode.removeChild(existingInfoSection);
				}

				var infoSection = document.createElement('section');
				infoSection.className = 'location-info-list';

				for (var k = 0; k < timetable.locations.length; k++) {
					var location = timetable.locations[k];
					var links = timetable.locationLinks[location];
					if (links === undefined || (links.url === undefined && links.info === undefined)) {
						continue;
					}

					var infoNode = infoSection.appendChild(document.createElement('article'));
					infoNode.id = getLocationInfoId(location);
					var headingNode = infoNode.appendChild(document.createElement('h2'));
					headingNode.textContent = location;
					if (links.url !== undefined) {
						var locationLinkNode = infoNode.appendChild(document.createElement('p'));
						var locationAnchorNode = locationLinkNode.appendChild(document.createElement('a'));
						locationAnchorNode.textContent = '> Location';
						locationAnchorNode.href = links.url;
					}
					if (links.info !== undefined) {
						var textNode = infoNode.appendChild(document.createElement('p'));
						textNode.textContent = links.info;
					}
				}

				if (infoSection.childNodes.length > 0) {
					container.parentNode.insertBefore(infoSection, container.nextSibling);
				}
			}
			function appendTimetableSection(container) {
        var sectionNode = container.appendChild(document.createElement('section'));
				var headerNode = appendColumnHeaders(sectionNode);
				var timeNode = sectionNode.appendChild(document.createElement('time'));
        timeNode.className = 'syncscroll';
        timeNode.setAttribute('name', 'scrollheader');
        var width = headerNode.scrollWidth + 'px';
				appendTimeRows(timeNode, width);
			}
			function appendColumnHeaders(node) {
				var headerNode = node.appendChild(document.createElement('header'));
				headerNode.className = 'syncscroll';
        headerNode.setAttribute('name', 'scrollheader');
				var headerULNode = headerNode.appendChild(document.createElement('ul'));

				var completed = false;
				var looped = false;

				for (var hour=timetable.scope.hourStart; !completed;) {
					var liNode = headerULNode.appendChild(document.createElement('li'));
					var spanNode = liNode.appendChild(document.createElement('span'));
					spanNode.className = 'time-label';
					spanNode.textContent = prettyFormatHour(hour, timetable.usingTwelveHour);

					if (hour === timetable.scope.hourEnd && (timetable.scope.hourStart !== timetable.scope.hourEnd || looped)) {
						completed = true;
					}
					if (++hour === 24) {
						hour = 0;
						looped = true;
					}
				}
				return headerNode;
			}
			function appendTimeRows(node, width) {
				var ulNode = node.appendChild(document.createElement('ul'));
        ulNode.style.width = width;
				ulNode.className = 'room-timeline';
				for (var k=0; k<timetable.locations.length; k++) {
					var liNode = ulNode.appendChild(document.createElement('li'));
					appendLocationEvents(timetable.locations[k], liNode);/**/
				}
			}
			function appendLocationEvents(location, node) {
				for (var k=0; k<timetable.events.length; k++) {
					var event = timetable.events[k];
					if (event.location === location) {
						appendEvent(event, node);
					}
				}
			}
			function appendEvent(event, node) {
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
				eventNode.style.width = computeEventBlockWidth(event);
				eventNode.style.left = computeEventBlockOffset(event);
				smallNode.textContent = event.name;
			}
			function computeEventBlockWidth(event) {
				var start = event.startDate;
				var end = event.endDate;
				var durationHours = computeDurationInHours(start, end);
				return durationHours / scopeDurationHours * 100 + '%';
			}
			function computeDurationInHours(start, end) {
				return (end.getTime() - start.getTime()) / 1000 / 60 / 60;
			}
			function computeEventBlockOffset(event) {
				var scopeStartHours = timetable.scope.hourStart;
				var eventStartHours = event.startDate.getHours() + (event.startDate.getMinutes() / 60);
				var hoursBeforeEvent =  getDurationHours(scopeStartHours, eventStartHours);
				return hoursBeforeEvent / scopeDurationHours * 100 + '%';
			}

			var scopeDurationHours = getDurationHours(timetable.scope.hourStart, timetable.scope.hourEnd);
			var container = document.querySelector(selector);
			checkContainerPrecondition(container);
			emptyNode(container);
			appendTimetableAside(container);
			appendTimetableSection(container);
			appendLocationInfoSection(container);
			//syncscroll.reset();
		}
	};

})();
