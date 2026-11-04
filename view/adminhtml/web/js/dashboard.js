/**
 * Infrastructure health dashboard. History and rates are kept in the page only.
 */
define([], function () {
    'use strict';

    var HISTORY = 40,
        NODE_STALE_MS = 90000,
        STATUS_WORD = {pending: 'Checking', ok: 'Healthy', tip: 'Could be tuned', warn: 'Degraded', down: 'Unreachable', off: 'Not used', local: 'Local', unknown: 'Not probed'},
        SVG_NS = 'http://www.w3.org/2000/svg';

    /* ---------- tiny DOM helpers (textContent only, never innerHTML with data) ---------- */

    function h(tag, attrs, children) {
        var el = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'class') {
                el.className = attrs[k];
            } else if (k === 'text') {
                el.textContent = attrs[k];
            } else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) {
                el.setAttribute(k, attrs[k]);
            }
        });
        (children || []).forEach(function (c) {
            if (c !== null && c !== undefined) {
                el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
            }
        });
        return el;
    }

    function s(tag, attrs) {
        var el = document.createElementNS(SVG_NS, tag);
        Object.keys(attrs || {}).forEach(function (k) {
            el.setAttribute(k, attrs[k]);
        });
        return el;
    }

    function clear(el) {
        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }
        return el;
    }

    /* ---------- formatting ---------- */

    function fmtNumber(v, decimals) {
        if (v === null || v === undefined || v === '') {
            return '—';
        }
        if (typeof v !== 'number') {
            return String(v);
        }
        var abs = Math.abs(v);
        if (abs >= 1e9) {
            return (v / 1e9).toFixed(1) + 'B';
        }
        if (abs >= 1e6) {
            return (v / 1e6).toFixed(1) + 'M';
        }
        if (abs >= 1e4) {
            return (v / 1e3).toFixed(1) + 'k';
        }
        if (decimals === undefined) {
            decimals = Number.isInteger(v) ? 0 : (abs < 10 ? 2 : 1);
        }
        return v.toLocaleString(undefined, {minimumFractionDigits: decimals, maximumFractionDigits: decimals});
    }

    function fmtBytes(b) {
        if (b === null || b === undefined) {
            return '—';
        }
        var units = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
        while (b >= 1024 && i < units.length - 1) {
            b /= 1024;
            i++;
        }
        return (b >= 100 || i === 0 ? Math.round(b) : b.toFixed(1)) + ' ' + units[i];
    }

    function fmtValue(v, unit) {
        if (unit === 'bytes') {
            return fmtBytes(v);
        }
        if (unit === 'ms') {
            return v === null || v === undefined ? '—' : fmtNumber(v, v < 10 ? 2 : 1) + ' ms';
        }
        if (unit === 'x') {
            return v === null || v === undefined ? '—' : fmtNumber(v, 2) + '×';
        }
        if (unit === '/s') {
            return v === null || v === undefined ? '—' : fmtNumber(v, v < 10 ? 1 : 0) + '/s';
        }
        return fmtNumber(v);
    }

    function fmtCell(v) {
        if (v && typeof v === 'object') {
            if ('bytes' in v) {
                return fmtBytes(v.bytes);
            }
            if ('rate' in v) {
                return v.rate ? fmtNumber(v.rate, 1) : '0';
            }
        }
        return fmtNumber(v);
    }

    function ago(ms) {
        var sec = Math.round(ms / 1000);
        return sec < 5 ? 'just now' : sec < 60 ? sec + 's ago' : Math.round(sec / 60) + 'm ago';
    }

    var BACKEND_ORDER = ['mysql', 'redis', 'search', 'rabbitmq', 'ext', 'shared', 'disk', 'local'];

    /**
     * File-backed subsystems: one board node per shared mount, one for "can't tell", one for local disk.
     */
    function storageKey(storage) {
        if (storage.kind === 'shared') {
            return 'shared:' + storage.label + ':' + (storage.source || storage.mount || '');
        }
        return storage.kind === 'local' ? 'local' : 'disk:unknown';
    }

    function storageEndpoint(key, storage) {
        if (storage.kind === 'shared') {
            return {key: key, svc: null, status: 'shared', statusWord: 'Shared across nodes',
                title: storage.label, detail: storage.source || storage.mount || ''};
        }
        if (storage.kind === 'local') {
            return {key: key, svc: null, status: 'local', statusWord: 'This node only',
                title: 'This node', detail: storage.label};
        }
        return {key: key, svc: null, status: 'unknown', statusWord: 'Could not tell',
            title: 'Unknown storage',
            detail: storage.method === 'stat' ? storage.label + ', mount table not readable' : 'Mount table not readable'};
    }

    /**
     * Board position of a backend: shared services first, per-node local disk last.
     */
    function endpointRank(key) {
        var parts = key.split(':'),
            code = parts[0] === 'pending' ? parts[1] : parts[0],
            rank = BACKEND_ORDER.indexOf(code);
        // Third-party services sit after the built-ins but before Varnish and disk boxes.
        return rank === -1 ? BACKEND_ORDER.indexOf('ext') - 0.5 : rank;
    }

    function serviceKey(svc) {
        return svc.code + (svc.instance ? ':' + svc.instance : '');
    }

    /* ---------- dashboard ---------- */

    function Dashboard(config, root) {
        this.url = config.statsUrl;
        this.probes = config.probes || [];
        this.wiring = config.wiring || [];
        this.root = root;
        this.history = {};
        this.prevCounters = {};
        this.nodes = {};
        this.hosts = {};
        this.results = {};
        this.pending = {};
        this.roundStarted = 0;
        this.expired = false;
        this.timer = null;
        this.interval = this.loadInterval();
        this.reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        this.el = {};
        ['hero-dot', 'hero-title', 'hero-sub', 'interval', 'refresh', 'board', 'wires', 'subsystems',
            'backends', 'nodes', 'services', 'unused', 'foot'].forEach(function (role) {
            this.el[role] = root.querySelector('[data-role="' + role + '"]');
        }, this);

        this.bind();
        // The board is drawn from config alone, before any backend has answered.
        this.renderAll(false);
        this.refresh();
    }

    Dashboard.prototype.loadInterval = function () {
        try {
            var v = window.localStorage.getItem('furan-infrahealth-interval');
            if (v !== null && ['0', '5', '15', '30'].indexOf(v) !== -1) {
                return parseInt(v, 10);
            }
        } catch (e) {
            // storage unavailable; fall through to default
        }
        return 15;
    };

    Dashboard.prototype.bind = function () {
        var self = this;
        this.el.interval.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-interval]');
            if (!btn) {
                return;
            }
            self.interval = parseInt(btn.getAttribute('data-interval'), 10);
            try {
                window.localStorage.setItem('furan-infrahealth-interval', String(self.interval));
            } catch (err) {
                // ignore
            }
            self.syncInterval();
            self.schedule();
        });
        this.el.refresh.addEventListener('click', function () {
            self.refresh();
        });
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && self.interval) {
                self.refresh();
            } else {
                self.schedule();
            }
        });
        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                self.drawWires(false);
            }, 120);
        });
        this.syncInterval();
    };

    Dashboard.prototype.syncInterval = function () {
        var current = String(this.interval);
        Array.prototype.forEach.call(this.el.interval.querySelectorAll('button'), function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-interval') === current ? 'true' : 'false');
        });
    };

    Dashboard.prototype.schedule = function () {
        clearTimeout(this.timer);
        if (this.interval && !document.hidden) {
            this.timer = setTimeout(this.refresh.bind(this), this.interval * 1000);
        }
    };

    /**
     * Fire one request per probe in parallel; each panel updates as soon as its own probe answers,
     * so one slow or dead backend never holds up the rest of the page.
     */
    Dashboard.prototype.refresh = function () {
        var self = this,
            remaining = this.probes.length;

        if (Object.keys(this.pending).length || this.expired || !remaining) {
            return;
        }
        clearTimeout(this.timer);
        this.roundStarted = Date.now();
        this.root.classList.add('is-loading');

        this.probes.forEach(function (probe) {
            self.pending[probe.code] = true;
            self.fetchProbe(probe.code)
                .then(function (data) {
                    self.ingest(probe.code, data);
                }, function (err) {
                    self.fail(probe, err);
                })
                .then(function () {
                    delete self.pending[probe.code];
                    remaining--;
                    self.renderAll(remaining === 0);
                    if (remaining === 0) {
                        self.root.classList.remove('is-loading');
                        self.schedule();
                    }
                });
        });
        this.renderAll(false);
    };

    Dashboard.prototype.fetchProbe = function (code) {
        var url = this.url + (this.url.indexOf('?') === -1 ? '?' : '&')
            + 'probe=' + encodeURIComponent(code) + '&isAjax=true';

        return fetch(url, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json'}})
            .then(function (res) {
                var type = res.headers.get('content-type') || '';
                if (type.indexOf('json') === -1) {
                    throw {expired: res.status === 200 || res.status === 302, message: 'HTTP ' + res.status};
                }
                return res.json().then(function (data) {
                    if (data && data.ajaxExpired) {
                        throw {expired: true};
                    }
                    if (!res.ok || !data || !Array.isArray(data.services)) {
                        throw {message: (data && data.error) || 'HTTP ' + res.status};
                    }
                    return data;
                });
            });
    };

    /* ---------- state ---------- */

    Dashboard.prototype.push = function (key, value) {
        if (typeof value !== 'number' || !isFinite(value)) {
            return this.history[key] || [];
        }
        var list = this.history[key] || (this.history[key] = []);
        list.push(value);
        if (list.length > HISTORY) {
            list.shift();
        }
        return list;
    };

    /**
     * Turn monotonic counters into rates/latencies over the last poll interval.
     */
    Dashboard.prototype.derive = function (svc, now) {
        var key = serviceKey(svc),
            prev = this.prevCounters[key],
            c = svc.counters || {},
            out = [];

        this.prevCounters[key] = {t: now, c: c};
        if (!prev || !Object.keys(c).length) {
            return out;
        }
        var dt = (now - prev.t) / 1000,
            d = function (k) {
                return (c[k] || 0) - (prev.c[k] || 0);
            };
        if (dt <= 0) {
            return out;
        }

        (svc.rates || []).forEach(function (r) {
            var num = d(r.num), value;
            if (r.den) {
                var den = d(r.den);
                value = den > 0 ? num / den * (r.scale || 1) : null;
            } else {
                value = Math.max(0, num / dt) * (r.scale || 1);
            }
            out.push({id: r.id, label: r.label, unit: r.unit, value: value});
        });
        return out;
    };

    /* ---------- render ---------- */

    Dashboard.prototype.ingest = function (code, data) {
        var now = Date.now(),
            self = this;

        data.services.forEach(function (svc) {
            var key = serviceKey(svc);
            svc.derived = self.derive(svc, now);
            svc.hist = {};
            (svc.stats || []).concat(svc.derived).forEach(function (st) {
                svc.hist[st.id] = self.push(key + ':' + st.id, st.value);
            });
            if (svc.latency_ms !== null) {
                svc.latencyHistory = self.push(key + ':latency', svc.latency_ms);
            }
            if (svc.code === 'node') {
                self.nodes[svc.instance] = {seen: now, svc: svc};
            }
        });
        this.hosts[data.node.hostname] = now;
        this.results[code] = {services: data.services, at: now, ms: data.duration_ms, host: data.node.hostname};
    };

    Dashboard.prototype.fail = function (probe, err) {
        if (err && err.expired) {
            this.expired = true;
            return;
        }
        var message = (err && err.message) || 'request failed';
        this.results[probe.code] = {
            at: Date.now(),
            services: [{
                code: probe.code, label: probe.label, instance: '', status: 'down', latency_ms: null,
                summary: '', error: 'Could not load stats (' + message + ')',
                config: [], stats: [], gauges: [], tables: [], counters: {}
            }]
        };
    };

    /**
     * Services in probe order, from whatever has answered so far.
     */
    Dashboard.prototype.services = function () {
        var out = [];
        this.probes.forEach(function (probe) {
            var r = this.results[probe.code];
            if (r) {
                out.push.apply(out, r.services);
            }
        }, this);
        return out;
    };

    Dashboard.prototype.renderAll = function (roundDone) {
        var now = Date.now(),
            services = this.services();

        this.renderHero(services);
        this.renderBoard(services, roundDone);
        this.renderNodes(now);
        this.renderServices(now);
        this.renderFoot(roundDone);
    };

    Dashboard.prototype.renderFoot = function (roundDone) {
        var foot = this.el.foot;
        foot.classList.toggle('is-error', this.expired);
        if (this.expired) {
            foot.textContent = 'Your admin session has expired. Reload the page to sign in again.';
            return;
        }
        if (!roundDone) {
            return;
        }
        var slowest = null;
        this.probes.forEach(function (probe) {
            var r = this.results[probe.code];
            if (r && r.ms !== undefined && (!slowest || r.ms > slowest.ms)) {
                slowest = {label: probe.label, ms: r.ms};
            }
        }, this);
        foot.textContent = 'Last refreshed ' + new Date().toLocaleTimeString() + '. '
            + (slowest ? 'Slowest check: ' + slowest.label + ', ' + fmtNumber(slowest.ms, 0) + ' ms. ' : '')
            + 'Each backend is checked in its own request. Probes are read-only and nothing is stored on the server.';
    };

    Dashboard.prototype.pendingLabels = function () {
        return this.probes.filter(function (p) {
            return this.pending[p.code] && !this.results[p.code];
        }, this).map(function (p) {
            return p.label;
        });
    };

    Dashboard.prototype.renderHero = function (services) {
        var probed = services.filter(function (s) {
                return s.code !== 'node' && s.status !== 'off';
            }),
            down = probed.filter(function (s) {
                return s.status === 'down';
            }),
            warn = probed.filter(function (s) {
                return s.status === 'warn';
            }),
            tips = probed.filter(function (s) {
                return s.status === 'tip';
            }),
            names = function (list) {
                return list.map(function (s) {
                    return s.label;
                }).join(' and ');
            },
            waiting = this.pendingLabels(),
            status = down.length ? 'down' : (warn.length ? 'warn' : (waiting.length ? 'pending' : 'ok')),
            title;

        if (down.length) {
            title = names(down) + (down.length > 1 ? ' are' : ' is') + ' unreachable';
        } else if (warn.length) {
            title = names(warn) + (warn.length > 1 ? ' need' : ' needs') + ' attention';
        } else if (waiting.length) {
            title = 'Checking services…';
        } else {
            title = !probed.length ? 'No backends to check'
                : 'All ' + probed.length + ' services are ' + (tips.length ? 'running' : 'healthy');
        }

        var healthy = probed.length - down.length - warn.length,
            hostCount = Object.keys(this.hosts).length,
            sub = probed.length ? healthy + ' of ' + probed.length + ' backends healthy. ' : '';
        if (tips.length && !down.length && !warn.length) {
            sub = names(tips) + (tips.length > 1 ? ' have a suggestion. ' : ' has a suggestion. ');
        }
        if (waiting.length) {
            sub += 'Still checking ' + waiting.join(', ') + '.';
        } else if (hostCount) {
            sub += hostCount > 1 ? hostCount + ' app nodes have answered so far.' : 'One app node has answered so far.';
        }
        this.el['hero-dot'].className = 'ih-dot ih-dot--' + status;
        this.el['hero-title'].textContent = title;
        this.el['hero-sub'].textContent = sub || 'Running read-only probes against each configured backend.';
        this.root.setAttribute('data-status', status);
    };

    Dashboard.prototype.findService = function (services, ref) {
        if (!ref) {
            return null;
        }
        for (var i = 0; i < services.length; i++) {
            var svc = services[i];
            if (ref === serviceKey(svc) || (ref === svc.code && !svc.instance)) {
                return svc;
            }
        }
        for (i = 0; i < services.length; i++) {
            if (ref.split(':')[0] === services[i].code) {
                return services[i];
            }
        }
        return null;
    };

    Dashboard.prototype.probeLabel = function (code) {
        for (var i = 0; i < this.probes.length; i++) {
            if (this.probes[i].code === code) {
                return this.probes[i].label;
            }
        }
        return code;
    };

    Dashboard.prototype.renderBoard = function (services, pulse) {
        var self = this,
            wiring = this.wiring,
            left = clear(this.el.subsystems),
            right = clear(this.el.backends),
            endpoints = [],
            rows = [],
            byKey = {};

        this.links = [];

        wiring.forEach(function (row, i) {
            var code = row.service ? row.service.split(':')[0] : null,
                svc = self.findService(services, row.service),
                waiting = code && !svc && !self.results[code],
                key = svc ? serviceKey(svc)
                    : (waiting ? 'pending:' + row.service
                        : (row.storage ? storageKey(row.storage)
                            : (row.service || row.backend === 'Varnish' ? 'ext:' + row.backend : 'local'))),
                ep = byKey[key];

            if (!ep && waiting) {
                ep = byKey[key] = {
                    key: key,
                    svc: null,
                    title: self.probeLabel(code),
                    detail: row.service.slice(code.length + 1),
                    status: 'pending'
                };
                endpoints.push(ep);
            } else if (!ep && !svc && row.storage) {
                ep = byKey[key] = storageEndpoint(key, row.storage);
                endpoints.push(ep);
            } else if (!ep) {
                ep = byKey[key] = {
                    key: key,
                    svc: svc,
                    title: svc ? svc.label : (row.backend === 'Varnish' ? 'Varnish' : 'This node'),
                    detail: svc ? svc.instance : (row.backend === 'Varnish' ? row.target : 'Local disk'),
                    status: svc ? svc.status : (row.backend === 'Varnish' ? 'unknown' : 'local')
                };
                endpoints.push(ep);
            }

            rows.push({row: row, index: i, ep: ep});
            self.links.push({from: i, to: key, status: ep.status});
        });

        // Group rows by backend so wires don't cross.
        endpoints.sort(function (a, b) {
            return endpointRank(a.key) - endpointRank(b.key);
        });
        rows.sort(function (a, b) {
            return endpoints.indexOf(a.ep) - endpoints.indexOf(b.ep) || a.index - b.index;
        });

        rows.forEach(function (r, pos) {
            var li = h('li', {class: 'ih-sub', 'data-link': String(r.index)}, [
                h('span', {class: 'ih-sub__name', text: r.row.subsystem}),
                h('span', {class: 'ih-sub__via'}, [
                    h('strong', {text: r.row.backend}),
                    r.row.target ? ' ' + r.row.target : ''
                ])
            ]);
            li.style.gridRow = String(pos + 1);
            left.appendChild(li);
            r.ep.rows = r.ep.rows || [];
            r.ep.rows.push(pos + 1);
        });

        endpoints.forEach(function (ep) {
            var node = h(ep.svc ? 'a' : 'div', {
                class: 'ih-ep ih-ep--' + ep.status,
                'data-ep': ep.key,
                href: ep.svc && ep.svc.status !== 'off' ? '#ih-svc-' + ep.key.replace(/[^a-z0-9]+/gi, '-') : null
            }, [
                h('span', {class: 'ih-dot ih-dot--' + ep.status}),
                h('span', {class: 'ih-ep__body'}, [
                    h('span', {class: 'ih-ep__title', text: ep.title}),
                    h('span', {class: 'ih-ep__detail', text: ep.detail || ''})
                ]),
                h('span', {class: 'ih-ep__meta'}, [
                    h('span', {class: 'ih-ep__status', text: ep.statusWord || STATUS_WORD[ep.status] || ep.status}),
                    ep.svc && ep.svc.latency_ms !== null
                        ? h('span', {class: 'ih-ep__lat', text: fmtValue(ep.svc.latency_ms, 'ms')}) : null
                ])
            ]);
            var li = h('li', {}, [node]);
            li.style.gridRow = ep.rows[0] + ' / span ' + ep.rows.length;
            right.appendChild(li);
        });

        this.drawWires(pulse);
    };

    Dashboard.prototype.drawWires = function (pulse) {
        var svg = clear(this.el.wires),
            board = this.el.board,
            box = board.getBoundingClientRect(),
            self = this;

        if (!this.links || window.getComputedStyle(svg).display === 'none') {
            return;
        }
        svg.setAttribute('viewBox', '0 0 ' + box.width + ' ' + box.height);
        svg.setAttribute('width', box.width);
        svg.setAttribute('height', box.height);

        this.links.forEach(function (link) {
            var a = board.querySelector('[data-link="' + link.from + '"]'),
                b = board.querySelector('[data-ep="' + (window.CSS && CSS.escape ? CSS.escape(link.to) : link.to) + '"]');
            if (!a || !b) {
                return;
            }
            var ra = a.getBoundingClientRect(),
                rb = b.getBoundingClientRect(),
                x1 = ra.right - box.left,
                y1 = ra.top + ra.height / 2 - box.top,
                x2 = rb.left - box.left,
                y2 = rb.top + rb.height / 2 - box.top,
                mx = (x1 + x2) / 2,
                d = 'M' + x1 + ',' + y1 + ' C' + mx + ',' + y1 + ' ' + mx + ',' + y2 + ' ' + x2 + ',' + y2,
                live = ['ok', 'tip', 'warn', 'down'].indexOf(link.status) !== -1;

            svg.appendChild(s('path', {d: d, class: 'ih-wire ih-wire--' + link.status}));
            svg.appendChild(s('circle', {cx: x1, cy: y1, r: 3, class: 'ih-wire__end ih-wire--' + link.status}));
            svg.appendChild(s('circle', {cx: x2, cy: y2, r: 3, class: 'ih-wire__end ih-wire--' + link.status}));
            if (pulse && live && !self.reducedMotion && link.status !== 'down') {
                svg.appendChild(s('path', {d: d, pathLength: '100', class: 'ih-pulse ih-wire--' + link.status}));
            }
        });
    };

    Dashboard.prototype.renderNodes = function (now) {
        var wrap = clear(this.el.nodes),
            names = Object.keys(this.hosts).sort(),
            self = this;

        if (!names.length) {
            wrap.hidden = true;
            return;
        }
        wrap.hidden = false;
        wrap.appendChild(h('h3', {text: names.length > 1 ? 'App nodes behind the load balancer' : 'App node'}));
        wrap.appendChild(h('p', {
            text: 'Each request is answered by whichever node the load balancer picks. Nodes are listed as they answer.'
        }));
        var list = h('ul', {class: 'ih-nodes__list'});
        names.forEach(function (name) {
            var seen = self.hosts[name],
                stale = now - seen > NODE_STALE_MS,
                thisRound = seen >= self.roundStarted,
                node = self.nodes[name];
            list.appendChild(h('li', {class: 'ih-node' + (thisRound ? ' is-current' : '') + (stale ? ' is-stale' : '')}, [
                h('span', {class: 'ih-dot ih-dot--' + (stale ? 'off' : (node ? node.svc.status : 'ok'))}),
                h('span', {class: 'ih-node__name', text: name}),
                h('span', {class: 'ih-node__seen', text: thisRound ? 'answered this refresh' : 'last answered ' + ago(now - seen)})
            ]));
        });
        wrap.appendChild(list);
    };

    Dashboard.prototype.renderServices = function (now) {
        var wrap = clear(this.el.services),
            unused = clear(this.el.unused),
            self = this;

        // Fixed slot per probe so panels don't jump around as answers arrive.
        this.probes.forEach(function (probe) {
            var r = self.results[probe.code];

            if (probe.code === 'node' && Object.keys(self.nodes).length) {
                // One panel per app node seen (latest snapshot each).
                Object.keys(self.nodes).sort().forEach(function (name) {
                    var n = self.nodes[name];
                    wrap.appendChild(self.panel(n.svc, now - n.seen > NODE_STALE_MS ? ago(now - n.seen) : null));
                });
                if (!r || r.services[0].code === 'node') {
                    return;
                }
            }
            if (!r) {
                wrap.appendChild(h('section', {class: 'ih-panel ih-panel--pending'}, [
                    h('header', {class: 'ih-panel__head'}, [
                        h('span', {class: 'ih-dot ih-dot--pending'}),
                        h('div', {class: 'ih-panel__titles'}, [
                            h('h3', {text: probe.label}),
                            h('p', {class: 'ih-panel__summary', text: 'Checking…'})
                        ])
                    ])
                ]));
                return;
            }
            r.services.forEach(function (svc) {
                if (svc.code === 'node') {
                    return;
                }
                if (svc.status === 'off') {
                    unused.appendChild(h('p', {class: 'ih-unused__row'}, [
                        h('span', {class: 'ih-dot ih-dot--off'}),
                        h('strong', {text: svc.label}),
                        ' ' + svc.summary
                    ]));
                    return;
                }
                wrap.appendChild(self.panel(svc, null));
            });
        });
    };

    Dashboard.prototype.panel = function (svc, staleFor) {
        var key = serviceKey(svc),
            self = this,
            stats = (svc.stats || []).concat(svc.derived || []);

        var head = h('header', {class: 'ih-panel__head'}, [
            h('span', {class: 'ih-dot ih-dot--' + svc.status}),
            h('div', {class: 'ih-panel__titles'}, [
                h('h3', {}, [svc.label, svc.instance ? h('span', {class: 'ih-panel__inst', text: svc.instance}) : null]),
                h('p', {class: 'ih-panel__summary', text: svc.error || svc.summary})
            ]),
            h('div', {class: 'ih-panel__lat'}, [
                h('span', {class: 'ih-panel__latv', text: svc.latency_ms !== null ? fmtValue(svc.latency_ms, 'ms') : ''}),
                h('span', {class: 'ih-panel__latl', text: svc.latency_ms !== null ? 'round trip' : ''}),
                svc.latencyHistory ? this.spark(svc.latencyHistory, 'ih-spark--lat') : null
            ])
        ]);

        var body = h('div', {class: 'ih-panel__body'});

        if (svc.config && svc.config.length) {
            var dl = h('dl', {class: 'ih-config'});
            svc.config.forEach(function (c) {
                dl.appendChild(h('dt', {text: c.label}));
                dl.appendChild(h('dd', {text: c.value, title: c.value}));
            });
            body.appendChild(dl);
        }

        var main = h('div', {class: 'ih-panel__main'});
        if (svc.status === 'down' && svc.error) {
            main.appendChild(h('p', {class: 'ih-down', text: 'The probe could not reach this backend: ' + svc.error
                + '. Check the host and credentials shown here, and that the service is running.'}));
        }
        (svc.tips || []).forEach(function (tip) {
            main.appendChild(h('p', {class: 'ih-tip'}, [h('strong', {text: 'Suggestion '}), tip]));
        });

        if (stats.length) {
            var grid = h('div', {class: 'ih-stats'});
            stats.forEach(function (st) {
                var hist = svc.hist[st.id] || [],
                    value = st.unit === '%' ? (st.value === null ? '—' : fmtNumber(st.value, 1) + '%') : fmtValue(st.value, st.unit);
                grid.appendChild(h('div', {class: 'ih-stat'}, [
                    h('span', {class: 'ih-stat__label', text: st.label}),
                    h('span', {class: 'ih-stat__value', text: value}),
                    hist.length > 1 ? self.spark(hist) : h('span', {class: 'ih-spark ih-spark--empty'})
                ]));
            });
            main.appendChild(grid);
        }

        if (svc.gauges && svc.gauges.length) {
            var gauges = h('div', {class: 'ih-gauges'});
            svc.gauges.forEach(function (g) {
                gauges.appendChild(self.gauge(g));
            });
            main.appendChild(gauges);
        }

        (svc.tables || []).forEach(function (t) {
            main.appendChild(self.table(t));
        });

        body.appendChild(main);

        var section = h('section', {
            class: 'ih-panel ih-panel--' + svc.status + (staleFor ? ' is-stale' : ''),
            id: 'ih-svc-' + key.replace(/[^a-z0-9]+/gi, '-')
        }, [head, staleFor ? h('p', {class: 'ih-stale', text: 'This node last answered ' + staleFor + '. Figures may be out of date.'}) : null, body]);
        return section;
    };

    Dashboard.prototype.spark = function (values, extra) {
        var w = 120, hgt = 28, pad = 2,
            min = Math.min.apply(null, values),
            max = Math.max.apply(null, values),
            range = max - min || 1,
            step = values.length > 1 ? (w - pad * 2) / (values.length - 1) : 0,
            offset = pad,
            pts = values.map(function (v, i) {
                var y = max === min ? hgt / 2 : hgt - pad - (v - min) / range * (hgt - pad * 2);
                return (offset + i * step).toFixed(1) + ',' + y.toFixed(1);
            }),
            svg = s('svg', {class: 'ih-spark ' + (extra || ''), viewBox: '0 0 ' + w + ' ' + hgt, preserveAspectRatio: 'none', 'aria-hidden': 'true'});

        if (pts.length > 1) {
            svg.appendChild(s('polygon', {
                class: 'ih-spark__fill',
                points: pts[0].split(',')[0] + ',' + hgt + ' ' + pts.join(' ') + ' ' + pts[pts.length - 1].split(',')[0] + ',' + hgt
            }));
            svg.appendChild(s('polyline', {class: 'ih-spark__line', points: pts.join(' ')}));
            var lastPt = pts[pts.length - 1].split(',');
            svg.appendChild(s('circle', {class: 'ih-spark__dot', cx: lastPt[0], cy: lastPt[1], r: 2}));
        }
        return svg;
    };

    Dashboard.prototype.gauge = function (g) {
        var r = 30, c = 2 * Math.PI * r,
            v = g.value,
            level = 'off';

        if (v !== null) {
            level = g.high_is_good
                ? (v >= 90 ? 'ok' : v >= 70 ? 'warn' : 'down')
                : (v <= 75 ? 'ok' : v <= 90 ? 'warn' : 'down');
        }
        var svg = s('svg', {class: 'ih-gauge__ring', viewBox: '0 0 72 72', 'aria-hidden': 'true'});
        svg.appendChild(s('circle', {class: 'ih-gauge__track', cx: 36, cy: 36, r: r}));
        if (v !== null) {
            svg.appendChild(s('circle', {
                class: 'ih-gauge__arc ih-gauge__arc--' + level,
                cx: 36, cy: 36, r: r,
                'stroke-dasharray': (c * v / 100).toFixed(2) + ' ' + c.toFixed(2),
                transform: 'rotate(-90 36 36)'
            }));
        }
        return h('figure', {class: 'ih-gauge', role: 'img', 'aria-label': g.label + ': ' + (v === null ? 'not available' : v + '%')}, [
            h('div', {class: 'ih-gauge__dial'}, [
                svg,
                h('span', {class: 'ih-gauge__value', text: v === null ? 'n/a' : fmtNumber(v, v % 1 ? 1 : 0) + '%'})
            ]),
            h('figcaption', {}, [
                h('span', {class: 'ih-gauge__label', text: g.label}),
                g.caption ? h('span', {class: 'ih-gauge__caption', text: g.caption}) : null
            ])
        ]);
    };

    Dashboard.prototype.table = function (t) {
        var numeric = t.columns.map(function (_, i) {
                return t.rows.length && t.rows.every(function (r) {
                    return typeof r[i] === 'number' || (r[i] && typeof r[i] === 'object');
                });
            }),
            // Bar on the first numeric column.
            barCol = numeric.indexOf(true),
            barMax = barCol === -1 ? 0 : Math.max.apply(null, t.rows.map(function (r) {
                return typeof r[barCol] === 'number' ? r[barCol] : 0;
            }).concat([0]));

        var thead = h('thead', {}, [h('tr', {}, t.columns.map(function (c, i) {
            return h('th', {class: numeric[i] ? 'is-num' : '', scope: 'col', text: c});
        }))]);

        var tbody = h('tbody');
        t.rows.forEach(function (row, ri) {
            tbody.appendChild(h('tr', {class: t.flags && t.flags[ri] ? 'is-' + t.flags[ri] : ''}, row.map(function (cell, ci) {
                if (ci === barCol && typeof cell === 'number') {
                    return h('td', {class: 'is-num is-bar'}, [
                        h('span', {class: 'ih-bar', style: 'width:' + (barMax ? Math.max(cell ? 3 : 0, cell / barMax * 100) : 0) + '%'}),
                        h('span', {class: 'ih-bar__v', text: fmtCell(cell)})
                    ]);
                }
                if (typeof cell === 'string' && ['green', 'yellow', 'red'].indexOf(cell) !== -1) {
                    var level = {green: 'ok', yellow: 'warn', red: 'down'}[cell];
                    return h('td', {}, [h('span', {class: 'ih-dot ih-dot--' + level}), ' ' + cell]);
                }
                return h('td', {class: numeric[ci] ? 'is-num' : '', text: fmtCell(cell)});
            })));
        });

        return h('div', {class: 'ih-table'}, [
            h('h4', {text: t.title}),
            h('div', {class: 'ih-table__scroll'}, [h('table', {}, [thead, tbody])])
        ]);
    };

    return function (config, element) {
        return new Dashboard(config, element);
    };
});
