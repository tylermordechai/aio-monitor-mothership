/*
 * Server map (MS-UI-01, MS-UI-07, M-054). Draws /api/map with Cytoscape.js
 * 3.34.4 (vendored, MIT). Text from the server goes into the page only
 * through textContent, never innerHTML, and Cytoscape draws on a canvas.
 */
(function () {
  'use strict';

  var el = document.getElementById('map');
  if (!el || typeof cytoscape !== 'function') {
    return;
  }
  var statusEl = document.getElementById('map-status');
  var info = document.getElementById('info');

  function css(name, fallback) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
  }

  function bytes(n) {
    var u = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = 0;
    n = Number(n) || 0;
    while (n >= 1024 && i < u.length - 1) {
      n /= 1024;
      i++;
    }
    return (i === 0 ? n : n.toFixed(1)) + ' ' + u[i];
  }

  function setStatus(text) {
    if (statusEl) {
      statusEl.textContent = text;
    }
  }

  function line(parent, label, value) {
    var p = document.createElement('p');
    var b = document.createElement('strong');
    b.textContent = label + ': ';
    p.appendChild(b);
    p.appendChild(document.createTextNode(value === null || value === undefined || value === '' ? '-' : String(value)));
    parent.appendChild(p);
  }

  function showInfo(d, names) {
    info.textContent = '';
    var h = document.createElement('h3');
    if (d.source) {
      h.textContent = (names[d.source] || d.source) + ' → ' + (names[d.target] || d.target);
      info.appendChild(h);
      line(info, 'Traffic', bytes(d.bytes));
      line(info, 'Connections', d.conns);
      line(info, 'Ports', (d.ports || []).join(', '));
      return;
    }
    h.textContent = d.label;
    info.appendChild(h);
    if (d.type === 'host') {
      line(info, 'Kind', d.kind);
      line(info, 'OS', d.os || 'not reported yet');
      line(info, 'Agent', d.version);
      line(info, 'Last check-in (UTC)', (d.last_checkin || 'never') + ' (' + d.ago + ')');
      line(info, 'Status', { ok: 'checking in', behind: 'checking in, ingest behind', stale: 'silent', revoked: 'revoked', no_agent: 'no agent' }[d.status] || d.status);
      line(info, 'Coverage gaps in this range', d.gaps);
      var p = document.createElement('p');
      p.className = 'muted';
      p.textContent = 'Click to open the host.';
      info.appendChild(p);
    } else if (d.type === 'internal') {
      line(info, 'Internal address, not monitored', bytes(d.bytes) + ' in this range');
    } else if (d.type === 'other-internal') {
      line(info, 'Internal addresses grouped', d.addresses);
    } else if (d.type === 'internet') {
      line(info, 'Outside addresses grouped', d.addresses);
    }
  }

  function showGaps(gaps) {
    var box = document.getElementById('gaps');
    var list = document.getElementById('gap-list');
    if (!box || !list) {
      return;
    }
    list.textContent = '';
    gaps.forEach(function (g) {
      var li = document.createElement('li');
      li.textContent = g.hostname + ': ' + g.reason + ', ' + g.start + ' to ' + (g.end || 'still open') + ' UTC';
      list.appendChild(li);
    });
    box.hidden = gaps.length === 0;
  }

  function draw(d) {
    var names = {};
    var maxBytes = 1;
    var elements = [];
    d.nodes.forEach(function (n) {
      names[n.id] = n.label;
      elements.push({ group: 'nodes', data: n });
    });
    d.edges.forEach(function (e) {
      maxBytes = Math.max(maxBytes, e.bytes);
      elements.push({ group: 'edges', data: e });
    });
    var logMax = Math.log(maxBytes + 1);
    var fg = css('--fg', '#1d2330');
    var muted = css('--muted', '#6b7385');
    var lineColor = css('--line', '#9aa3b5');
    var cy = cytoscape({
      container: el,
      elements: elements,
      minZoom: 0.2,
      maxZoom: 3,
      wheelSensitivity: 0.2,
      boxSelectionEnabled: false,
      autoungrabify: false,
      style: [
        { selector: 'node', style: { 'label': 'data(label)', 'color': fg, 'font-size': 11, 'text-valign': 'bottom', 'text-margin-y': 4, 'text-wrap': 'wrap', 'text-max-width': 140, 'border-width': 2, 'border-color': css('--border', '#c9cfdb') } },
        { selector: 'node[type="host"]', style: { 'shape': 'round-rectangle', 'width': 46, 'height': 34, 'background-color': css('--server', '#2f6fde'), 'border-color': css('--server-edge', '#1e4fa8') } },
        { selector: 'node[type="host"][kind="workstation"]', style: { 'shape': 'ellipse', 'width': 34, 'height': 34, 'background-color': css('--workstation', '#4aa3a2'), 'border-color': css('--workstation-edge', '#2f7473') } },
        { selector: 'node[type="host"][status!="ok"]', style: { 'background-color': css('--stale', '#e0a020'), 'border-color': css('--stale-edge', '#a8740c') } },
        { selector: 'node[type="host"][gaps > 0]', style: { 'border-style': 'dashed', 'border-width': 4, 'border-color': css('--gap', '#c2410c') } },
        { selector: 'node[type="internal"]', style: { 'shape': 'diamond', 'width': 22, 'height': 22, 'background-color': css('--internal', '#8c95a8'), 'font-size': 10, 'color': muted } },
        { selector: 'node[type="other-internal"]', style: { 'shape': 'barrel', 'width': 44, 'height': 30, 'background-color': css('--other', '#b3bac8'), 'color': muted } },
        { selector: 'node[type="internet"]', style: { 'shape': 'ellipse', 'width': 70, 'height': 46, 'background-color': css('--internet', '#9b6bd3'), 'border-color': css('--internet-edge', '#6d43a3') } },
        { selector: 'edge', style: {
          'curve-style': 'bezier',
          'width': function (e) { return 1 + 7 * Math.log(e.data('bytes') + 1) / logMax; },
          'line-color': lineColor, 'target-arrow-color': lineColor, 'target-arrow-shape': 'triangle', 'arrow-scale': 0.8,
          'opacity': 0.75, 'font-size': 9, 'color': muted, 'text-rotation': 'autorotate', 'text-background-color': css('--bg', '#ffffff'), 'text-background-opacity': 0.8, 'text-background-padding': 1
        } },
        { selector: 'edge.hover', style: { 'label': 'data(label)', 'opacity': 1, 'line-color': css('--accent', '#2f6fde'), 'target-arrow-color': css('--accent', '#2f6fde') } },
        { selector: 'node.hover', style: { 'border-color': css('--accent', '#2f6fde'), 'border-width': 4 } }
      ],
      layout: { name: 'cose', animate: false, randomize: false, nodeRepulsion: function () { return 12000; }, idealEdgeLength: function () { return 120; }, padding: 30, numIter: 1500 }
    });
    cy.on('mouseover', 'node, edge', function (ev) {
      ev.target.addClass('hover');
      showInfo(ev.target.data(), names);
    });
    cy.on('mouseout', 'node, edge', function (ev) {
      ev.target.removeClass('hover');
    });
    cy.on('tap', 'node[type="host"]', function (ev) {
      window.location.href = '/hosts/' + encodeURIComponent(ev.target.data('host_pk'));
    });
    cy.on('tap', 'edge', function (ev) {
      showInfo(ev.target.data(), names);
    });
    showGaps(d.gaps || []);
    var hosts = d.nodes.filter(function (n) { return n.type === 'host'; }).length;
    setStatus(hosts + ' hosts, ' + d.edges.length + ' lines, ' + d.since + ' to ' + d.until + ' UTC. ' +
      (d.data_until ? 'Connections summarised up to ' + d.data_until + ' UTC (about a minute behind).' : 'No connections in this range yet.'));
  }

  setStatus('Loading the map…');
  fetch('/api/map?range=' + encodeURIComponent(el.getAttribute('data-range') || '24h'), {
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' }
  }).then(function (r) {
    if (r.status === 401) {
      window.location.href = '/login';
      throw new Error('signed out');
    }
    if (!r.ok) {
      throw new Error('the server answered ' + r.status);
    }
    return r.json();
  }).then(draw).catch(function (err) {
    setStatus('The map could not be loaded: ' + err.message);
  });
}());
