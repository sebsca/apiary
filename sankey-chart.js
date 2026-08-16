export function renderSankeyChart(container, data) {
  const d3 = window.d3;
  const width = 960;
  const links = data.links || [];
  const nodes = data.nodes || [];
  const columnLabels = data.columns || [];
  const plotTop = 120;
  const height = Math.max(420, nodes.length * 44, links.length * 28) + (plotTop - 48);
  const baseNodeCount = nodes.length;
  const dummyNodes = columnLabels.map((_, column) => ({
    name: '',
    column,
    date: columnLabels[column],
    dummy: true
  }));
  const dummyLinks = columnLabels.slice(1).map((_, index) => ({
    source: baseNodeCount + index,
    target: baseNodeCount + index + 1,
    value: 0.000001,
    dummy: true
  }));
  const graph = {
    nodes: [...nodes.map((node) => ({ ...node })), ...dummyNodes],
    links: [...links.map((link) => ({ ...link })), ...dummyLinks]
  };
  const color = d3.scaleOrdinal(d3.schemeTableau10);

  d3.sankey()
    .nodeWidth(18)
    .nodePadding(16)
    .nodeAlign((node) => node.column)
    .nodeSort((a, b) => {
      if (a.dummy || b.dummy) {
        return Number(!!a.dummy) - Number(!!b.dummy);
      }
      return String(a.name).localeCompare(String(b.name), undefined, { sensitivity: 'base' });
    })
    .extent([[1, plotTop], [width - 1, height - 8]])(graph);

  const svg = d3.select(container)
    .append('svg')
    .attr('viewBox', `0 0 ${width} ${height}`)
    .attr('role', 'img')
    .attr('aria-labelledby', 'sankey-title sankey-description');

  svg.append('title')
    .attr('id', 'sankey-title')
    .text('Hive movements between locations');

  svg.append('desc')
    .attr('id', 'sankey-description')
    .text('Flow diagram of active hives moving between apiary locations during the current calendar year. A data table follows the chart.');

  svg.append('g')
    .attr('fill', 'none')
    .selectAll('path')
    .data(graph.links.filter((link) => !link.dummy))
    .join('path')
    .attr('d', d3.sankeyLinkHorizontal())
    .attr('stroke', (link) => color(link.source.name))
    .attr('stroke-width', (link) => Math.max(1, link.width))
    .attr('stroke-opacity', 0.5)
    .append('title')
    .text((link) =>
      `${link.date}: ${link.source.name} → ${link.target.name}: ${link.value}\nHive no.: ${link.hives || '—'}`
    );

  const node = svg.append('g')
    .selectAll('g')
    .data(graph.nodes.filter((item) => !item.dummy))
    .join('g');

  node.append('rect')
    .attr('x', (item) => item.x0)
    .attr('y', (item) => item.y0)
    .attr('height', (item) => Math.max(1, item.y1 - item.y0))
    .attr('width', (item) => item.x1 - item.x0)
    .attr('fill', (item) => color(item.name))
    .attr('rx', 3)
    .append('title')
    .text((item) =>
      `${item.date}: ${item.name}: ${item.value}\nHive no.: ${item.hives || '—'}`
    );

  node.append('text')
    .attr('x', (item) => item.x0 < width / 2 ? item.x1 + 8 : item.x0 - 8)
    .attr('y', (item) => (item.y0 + item.y1) / 2)
    .attr('dy', '0.35em')
    .attr('text-anchor', (item) => item.x0 < width / 2 ? 'start' : 'end')
    .text((item) => item.name);

  const columnXs = columnLabels.map((_, column) => {
    const columnNodes = graph.nodes.filter((item) => item.column === column);
    if (columnNodes.length > 0) {
      return d3.mean(columnNodes, (item) => (item.x0 + item.x1) / 2);
    }
    return columnLabels.length <= 1 ? width / 2 : column * (width - 20) / (columnLabels.length - 1) + 10;
  });

  svg.append('g')
    .attr('class', 'sankey-axis')
    .selectAll('text')
    .data(columnLabels)
    .join('text')
    .attr('transform', (_, index) => `translate(${columnXs[index]}, ${plotTop - 10}) rotate(-90)`)
    .attr('text-anchor', 'start')
    .text((label) => label);
}
