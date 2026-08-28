// World map drawing function with SVG-based tooltip
function drawZoteroMap(containerId, countryData) {
    const container = document.querySelector(`#${containerId} .zotero-map-container`);
    
    console.log('🗺️ drawZoteroMap called:', containerId, countryData);
    
    // Get dimensions
    const width = container.offsetWidth;
    const height = container.offsetHeight || 500;
    
    // Create SVG
    const svg = d3.select(container)
        .append('svg')
        .attr('width', width)
        .attr('height', height);
    
    // Add zoom controls positioned better relative to the map
    const zoomControls = d3.select(`#${containerId}`)
        .append('div')
        .attr('class', 'zoom-controls')
        .style('position', 'absolute')
        .style('top', '20px')
        .style('right', '20px')
        .style('z-index', '1000');
    
    zoomControls.append('button')
        .text('+')
        .on('click', () => zoom.scaleBy(svg.transition().duration(300), 1.3));
    
    zoomControls.append('button')
        .text('-')
        .on('click', () => zoom.scaleBy(svg.transition().duration(300), 0.7));
    
    // Set up projection for better world view
    const projection = d3.geoMercator()
        .scale(width / 7)  // Reduced scale for wider view
        .translate([width / 2, height / 1.6]); // Adjusted translate for better centering
    
    const path = d3.geoPath().projection(projection);
    
    // Create zoom behavior
    const zoom = d3.zoom()
        .scaleExtent([0.5, 8])
        .on('zoom', (event) => {
            g.attr('transform', event.transform);
            // Hide tooltip during zoom
            svgTooltip.style('display', 'none');
        });
    
    svg.call(zoom);
    
    // Create main group
    const g = svg.append('g');
    
    // Add water background with better coverage
    g.append('rect')
        .attr('class', 'water')
        .attr('width', width * 3)
        .attr('height', height * 3)
        .attr('x', -width)
        .attr('y', -height)
        .style('fill', zoteroVizData.colors.water);
    
    // Create SVG-based tooltip group
    const svgTooltip = svg.append('g')
        .attr('class', 'svg-tooltip')
        .style('display', 'none')
        .style('pointer-events', 'none');
    
    // Tooltip background rectangle
    const tooltipBg = svgTooltip.append('rect')
        .attr('rx', 4)
        .attr('ry', 4)
        .style('fill', 'rgba(0, 0, 0, 0.8)')
        .style('stroke', '#fff')
        .style('stroke-width', 1);
    
    // Tooltip text
    const tooltipText = svgTooltip.append('text')
        .style('fill', 'white')
        .style('font-family', 'Arial, sans-serif')
        .style('font-size', '12px')
        .style('text-anchor', 'middle')
        .attr('dy', '0.35em');
    
    // Enhanced tooltip positioning function for mobile
    function positionTooltip(event, tooltipNode) {
        // Get mouse/touch position relative to SVG
        const [mouseX, mouseY] = d3.pointer(event, svg.node());
        
        // Update text first to get accurate dimensions
        const textBBox = tooltipText.node().getBBox();
        const padding = 8;
        
        // Mobile-specific adjustments
        const isMobile = window.innerWidth <= 768;
        let tooltipX, tooltipY;
        
        if (isMobile) {
            // On mobile, position above the touch point and center horizontally
            tooltipX = mouseX;
            tooltipY = mouseY - textBBox.height - padding * 2 - 20;
            
            // Keep it within horizontal bounds
            const halfWidth = textBBox.width / 2 + padding;
            if (tooltipX - halfWidth < 0) {
                tooltipX = halfWidth;
            } else if (tooltipX + halfWidth > width) {
                tooltipX = width - halfWidth;
            }
            
            // If it would go off the top, put it below
            if (tooltipY - textBBox.height / 2 - padding < 0) {
                tooltipY = mouseY + padding * 2 + 20;
            }
            
            // Final vertical bounds check
            if (tooltipY + textBBox.height / 2 + padding > height) {
                tooltipY = height - textBBox.height / 2 - padding;
            }
        } else {
            // Desktop positioning - offset to the right and up
            tooltipX = mouseX + 15;
            tooltipY = mouseY - 15;
            
            // Adjust if tooltip would go off the right edge
            if (tooltipX + textBBox.width / 2 + padding > width) {
                tooltipX = mouseX - textBBox.width / 2 - padding - 15;
            }
            
            // Adjust if tooltip would go off the top edge
            if (tooltipY - textBBox.height / 2 - padding < 0) {
                tooltipY = mouseY + textBBox.height / 2 + padding + 15;
            }
            
            // Adjust if tooltip would go off the left edge
            if (tooltipX - textBBox.width / 2 - padding < 0) {
                tooltipX = textBBox.width / 2 + padding;
            }
            
            // Adjust if tooltip would go off the bottom edge
            if (tooltipY + textBBox.height / 2 + padding > height) {
                tooltipY = height - textBBox.height / 2 - padding;
            }
        }
        
        return { x: tooltipX, y: tooltipY };
    }

    // Function to show SVG tooltip
    function showSVGTooltip(event, text) {
        // Update tooltip text
        tooltipText.text(text);
        
        // Get position from enhanced function
        const position = positionTooltip(event, tooltipText.node());
        const textBBox = tooltipText.node().getBBox();
        const padding = 8;
        
        // Update background rectangle
        tooltipBg
            .attr('x', position.x - textBBox.width/2 - padding)
            .attr('y', position.y - textBBox.height/2 - padding)
            .attr('width', textBBox.width + padding * 2)
            .attr('height', textBBox.height + padding * 2);
        
        // Position text
        tooltipText
            .attr('x', position.x)
            .attr('y', position.y);
        
        // Show tooltip
        svgTooltip.style('display', 'block');
    }
    
    // Function to update SVG tooltip position
    function updateSVGTooltip(event) {
        if (svgTooltip.style('display') !== 'none') {
            const position = positionTooltip(event, tooltipText.node());
            const textBBox = tooltipText.node().getBBox();
            const padding = 8;
            
            tooltipBg
                .attr('x', position.x - textBBox.width/2 - padding)
                .attr('y', position.y - textBBox.height/2 - padding);
            
            tooltipText
                .attr('x', position.x)
                .attr('y', position.y);
        }
    }
    
    // Load country mappings and world topology
    Promise.all([
        fetch(zoteroVizData.pluginUrl + 'assets/country-mappings.json').then(r => r.json()),
        d3.json('https://cdn.jsdelivr.net/npm/world-atlas@2/countries-110m.json')
    ]).then(([countryNameMap, world]) => {
        // Create reverse mapping for finding data
        const reverseMap = {};
        Object.entries(countryNameMap).forEach(([key, value]) => {
            reverseMap[value] = key;
        });
        
        // Draw countries
        const countries = g.append('g')
            .selectAll('path')
            .data(topojson.feature(world, world.objects.countries).features)
            .enter().append('path')
            .attr('d', path)
            .attr('class', 'country')
            .style('fill', d => {
                const countryName = d.properties.name;
                const countryNameAlt = d.properties.NAME;
                const iso3 = d.properties.ISO_A3;

                if (countryData[countryName]) {
                    return zoteroVizData.colors.highlight;
                }

                const mappedName = countryNameMap[countryName] || 
                                countryNameMap[countryNameAlt] || 
                                countryNameMap[iso3];

                if (mappedName && countryData[mappedName]) {
                    return zoteroVizData.colors.highlight;
                }

                if (reverseMap[countryName] && countryData[countryName]) {
                    return zoteroVizData.colors.highlight;
                }

                return zoteroVizData.colors.default;
            })
            .style('stroke', zoteroVizData.colors.border)
            .style('stroke-width', 0.5)
            .on('mouseover', function(event, d) {
                const countryName = d.properties.name;
                const countryNameAlt = d.properties.NAME;
                const iso3 = d.properties.ISO_A3;

                let worldBankName = countryName;
                let count = 0;

                if (countryData[countryName]) {
                    count = countryData[countryName];
                } else {
                    const mappedName = countryNameMap[countryName] || 
                                    countryNameMap[countryNameAlt] || 
                                    countryNameMap[iso3];
                    if (mappedName && countryData[mappedName]) {
                        worldBankName = mappedName;
                        count = countryData[mappedName];
                    }
                }

                if (count > 0) {
                    d3.select(this)
                        .style('opacity', 0.8)
                        .style('cursor', 'pointer');
                    const tooltipContent = `${worldBankName} — ${count} tagged citation${count > 1 ? 's' : ''} (click to view)`;
                    showSVGTooltip(event, tooltipContent);
                }
            })
            .on('mousemove', function(event, d) {
                const countryName = d.properties.name;
                const countryNameAlt = d.properties.NAME;
                const iso3 = d.properties.ISO_A3;

                let count = 0;
                if (countryData[countryName]) {
                    count = countryData[countryName];
                } else {
                    const mappedName = countryNameMap[countryName] || 
                                    countryNameMap[countryNameAlt] || 
                                    countryNameMap[iso3];
                    if (mappedName && countryData[mappedName]) {
                        count = countryData[mappedName];
                    }
                }

                if (count > 0 && svgTooltip.style('display') !== 'none') {
                    updateSVGTooltip(event);
                }
            })
            .on('mouseout', function() {
                d3.select(this)
                    .style('opacity', 1)
                    .style('cursor', 'default');
                svgTooltip.style('display', 'none');
            })
            .on('touchstart', function(event, d) {
                console.log('👆 Touch start event fired on country:', d.properties.name);
                
                // Handle touch start - show tooltip and prevent scrolling
                const countryName = d.properties.name;
                const countryNameAlt = d.properties.NAME;
                const iso3 = d.properties.ISO_A3;

                let worldBankName = countryName;
                let count = 0;

                if (countryData[countryName]) {
                    count = countryData[countryName];
                } else {
                    const mappedName = countryNameMap[countryName] || 
                                    countryNameMap[countryNameAlt] || 
                                    countryNameMap[iso3];
                    if (mappedName && countryData[mappedName]) {
                        worldBankName = mappedName;
                        count = countryData[mappedName];
                    }
                }

                if (count > 0) {
                    d3.select(this)
                        .style('opacity', 0.8)
                        .style('cursor', 'pointer');
                    const tooltipContent = `${worldBankName} — ${count} tagged citation${count > 1 ? 's' : ''} (tap to open)`;
                    showSVGTooltip(event, tooltipContent);
                    
                    console.log('📱 Touch tooltip shown for:', worldBankName);
                }
            })
            .on('click', function(event, d) {
                console.log('🖱️ Click event fired on country:', d.properties.name);
                
                // Debug container information
                const containerElement = document.getElementById(containerId);
                console.log('🔍 Container element:', containerElement);
                console.log('🔍 Container ID being searched:', containerId);
                console.log('🔍 All elements with zotero-map class:', document.querySelectorAll('.zotero-map'));
                
                if (containerElement) {
                    console.log('🔍 Container dataset:', containerElement.dataset);
                    console.log('🔍 All container attributes:', Array.from(containerElement.attributes).map(attr => ({
                        name: attr.name,
                        value: attr.value
                    })));
                }
                
                // Handle desktop clicks
                const countryName = d.properties.name;
                const countryNameAlt = d.properties.NAME;
                const iso3 = d.properties.ISO_A3;

                let worldBankName = countryName;
                let count = 0;

                if (countryData[countryName]) {
                    count = countryData[countryName];
                    worldBankName = countryName;
                } else {
                    const mappedName = countryNameMap[countryName] || 
                                    countryNameMap[countryNameAlt] || 
                                    countryNameMap[iso3];
                    if (mappedName && countryData[mappedName]) {
                        worldBankName = mappedName;
                        count = countryData[mappedName];
                    }
                }

                console.log('🔍 Country data found:', { worldBankName, count });

                // Only make clickable if there are citations
                if (count > 0) {
                    // Get the library information from data attributes
                    const groupId = containerElement?.dataset.zoteroGroupId;
                    const libraryName = containerElement?.dataset.zoteroLibraryName;
                    
                    console.log('📚 Library info extracted:', { 
                        containerId, 
                        groupId, 
                        libraryName,
                        hasContainer: !!containerElement,
                        datasetKeys: containerElement ? Object.keys(containerElement.dataset) : 'no container'
                    });
                    
                    if (groupId && libraryName) {
                        // Construct the Zotero URL with proper URL encoding
                        const encodedCountryName = encodeURIComponent(worldBankName);
                        const zoteroUrl = `https://www.zotero.org/groups/${groupId}/${libraryName}/tags/${encodedCountryName}/library`;
                        
                        console.log('🔗 Opening Zotero URL (desktop):', {
                            country: worldBankName,
                            url: zoteroUrl
                        });
                        
                        // Try opening in new tab
                        const newWindow = window.open(zoteroUrl, '_blank');
                        if (!newWindow) {
                            console.warn('🚫 Popup blocked - trying alternative method');
                            // Fallback: create a temporary link and click it
                            const link = document.createElement('a');
                            link.href = zoteroUrl;
                            link.target = '_blank';
                            link.rel = 'noopener noreferrer';
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                        }
                    } else {
                        console.warn('⚠️ Could not determine library information for country click', {
                            containerId,
                            groupId,
                            libraryName,
                            hasContainer: !!containerElement,
                            containerElement,
                            allDataAttributes: containerElement ? containerElement.dataset : 'no container'
                        });
                    }
                } else {
                    console.log('ℹ️ No citations for this country:', worldBankName);
                }
                
                // Prevent event bubbling
                event.preventDefault();
                event.stopPropagation();
            })
            .on('touchend', function(event, d) {
                console.log('👆 Touch end event fired on country:', d.properties.name);
                
                // Handle mobile touches
                const countryName = d.properties.name;
                const countryNameAlt = d.properties.NAME;
                const iso3 = d.properties.ISO_A3;

                let worldBankName = countryName;
                let count = 0;

                if (countryData[countryName]) {
                    count = countryData[countryName];
                    worldBankName = countryName;
                } else {
                    const mappedName = countryNameMap[countryName] || 
                                    countryNameMap[countryNameAlt] || 
                                    countryNameMap[iso3];
                    if (mappedName && countryData[mappedName]) {
                        worldBankName = mappedName;
                        count = countryData[mappedName];
                    }
                }

                console.log('🔍 Mobile country data found:', { worldBankName, count });

                // Only make clickable if there are citations
                if (count > 0) {
                    // Get the library information from data attributes
                    const containerElement = document.getElementById(containerId);
                    const groupId = containerElement?.dataset.zoteroGroupId;
                    const libraryName = containerElement?.dataset.zoteroLibraryName;
                    
                    console.log('📚 Mobile library info:', { containerId, groupId, libraryName });
                    
                    if (groupId && libraryName) {
                        // Construct the Zotero URL with proper URL encoding
                        const encodedCountryName = encodeURIComponent(worldBankName);
                        const zoteroUrl = `https://www.zotero.org/groups/${groupId}/${libraryName}/tags/${encodedCountryName}/library`;
                        
                        console.log('🔗 Opening Zotero URL (mobile):', {
                            country: worldBankName,
                            url: zoteroUrl
                        });
                        
                        // On mobile, direct navigation works better
                        window.location.href = zoteroUrl;
                    } else {
                        console.warn('⚠️ Mobile: Could not determine library information', {
                            containerId,
                            groupId,
                            libraryName
                        });
                    }
                } else {
                    console.log('ℹ️ Mobile: No citations for this country:', worldBankName);
                }
                
                // Prevent event bubbling
                event.preventDefault();
                event.stopPropagation();
            });
    }).catch(error => {
        console.error('❌ Error loading map data:', error);
    });
}

// Timeline/bar chart drawing function (unchanged)
function drawZoteroTimeline(containerId, yearData) {
    const container = document.querySelector(`#${containerId} .zotero-timeline-container`);
    
    // Get dimensions with increased margins for better spacing
    const margin = {top: 20, right: 30, bottom: 60, left: 70};
    const width = container.offsetWidth - margin.left - margin.right;
    const height = (container.offsetHeight || 400) - margin.top - margin.bottom;
    
    // Process data
    const currentYear = new Date().getFullYear();
    const data = Object.entries(yearData)
        .filter(([year, count]) => !year.includes('_projected') && !year.includes('_actual'))
        .map(([year, count]) => ({
            year: +year,
            actualCount: count,
            projectedCount: 0,
            type: 'actual'
        }));
    
    // Add projected data if exists
    if (yearData[currentYear + '_projected']) {
        const currentYearEntry = data.find(d => d.year === currentYear);
        if (currentYearEntry) {
            currentYearEntry.projectedCount = yearData[currentYear + '_projected'];
        }
    }
    
    // Create SVG
    const svg = d3.select(container)
        .append('svg')
        .attr('width', width + margin.left + margin.right)
        .attr('height', height + margin.top + margin.bottom)
        .append('g')
        .attr('transform', `translate(${margin.left},${margin.top})`);
    
    // Set up scales
    const x = d3.scaleBand()
        .domain(data.map(d => d.year))
        .range([0, width])
        .padding(0.1);
    
    const y = d3.scaleLinear()
        .domain([0, d3.max(data, d => d.actualCount + d.projectedCount) * 1.1])
        .nice()
        .range([height, 0]);
    
    // Add axes
    const xAxis = svg.append('g')
        .attr('class', 'x-axis')
        .attr('transform', `translate(0,${height})`)
        .call(d3.axisBottom(x));
    
    // Style x-axis tick labels
    xAxis.selectAll('text')
        .style('font-size', '12px')
        .style('font-weight', 'normal');
    
    // Add x-axis title
    svg.append('text')
        .attr('x', width / 2)
        .attr('y', height + 50)
        .style('text-anchor', 'middle')
        .style('fill', 'black')
        .style('font-weight', 'bold')
        .style('font-size', '16px')
        .text('Publication Year');
    
    const yAxis = svg.append('g')
        .attr('class', 'y-axis')
        .call(d3.axisLeft(y));
    
    // Style y-axis tick labels
    yAxis.selectAll('text')
        .style('font-size', '12px')
        .style('font-weight', 'normal');
    
    // Add y-axis title
    svg.append('text')
        .attr('transform', 'rotate(-90)')
        .attr('y', -50)
        .attr('x', -height / 2)
        .style('text-anchor', 'middle')
        .style('fill', 'black')
        .style('font-weight', 'bold')
        .style('font-size', '16px')
        .text('Citations');
    
    // Add actual bars
    svg.selectAll('.bar-actual')
        .data(data)
        .enter().append('rect')
        .attr('class', 'bar actual')
        .attr('x', d => x(d.year))
        .attr('y', d => y(d.actualCount))
        .attr('width', x.bandwidth())
        .attr('height', d => height - y(d.actualCount))
        .style('fill', '#dc3545');
    
    // Add projected bars (stacked on top of actual)
    svg.selectAll('.bar-projected')
        .data(data.filter(d => d.projectedCount > 0))
        .enter().append('rect')
        .attr('class', 'bar projected')
        .attr('x', d => x(d.year))
        .attr('y', d => y(d.actualCount + d.projectedCount))
        .attr('width', x.bandwidth())
        .attr('height', d => height - y(d.actualCount + d.projectedCount) - (height - y(d.actualCount)))
        .style('fill', '#ffcccc');
    
    // Add legend
    const legend = svg.append('g')
        .attr('transform', `translate(${width - 100}, 0)`);
    
    legend.append('rect')
        .attr('x', 0)
        .attr('y', 0)
        .attr('width', 18)
        .attr('height', 18)
        .style('fill', '#dc3545');
    
    legend.append('text')
        .attr('x', 24)
        .attr('y', 9)
        .attr('dy', '.35em')
        .style('text-anchor', 'start')
        .text('Actual');
    
    if (data.some(d => d.projectedCount > 0)) {
        legend.append('rect')
            .attr('x', 0)
            .attr('y', 25)
            .attr('width', 18)
            .attr('height', 18)
            .style('fill', '#ffcccc');
        
        legend.append('text')
            .attr('x', 24)
            .attr('y', 34)
            .attr('dy', '.35em')
            .style('text-anchor', 'start')
            .text('Projected');
    }
}