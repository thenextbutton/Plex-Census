// =====================================================================
// GLOBAL STATE & ELEMENT REFERENCES
// =====================================================================

let allData = null; // Entire JSON payload (libraries + items)
let currentModalItem = null;

const modal = document.getElementById('item-modal');
const modalDetails = document.getElementById('modal-details');
const closeButton = document.querySelector('.close-button');
const libraryContainer = document.getElementById('library-container');
const searchInput = document.getElementById('search-input');
const QUICK_TAP_DURATION = 500; // Time in milliseconds for a quick tap

const quickInfoPopup = document.createElement('div');
quickInfoPopup.className = 'quick-info-popup';
document.body.appendChild(quickInfoPopup);

const activePressTimer = {
    timerId: null,
    clear: function() {
        if (this.timerId) {
            clearTimeout(this.timerId);
            this.timerId = null;
        }
    }
};

let activeLibraryId = 'all'; // Tracks active library filter; defaults to 'all'

const htmlElement = document.documentElement; // Used for robust scroll lock

// Loader elements
const loadingOverlay = document.getElementById('loading-overlay');
const progressBar = document.getElementById('progress-bar');
const scrollProgressBar = document.getElementById('scroll-progress-bar');

// =====================================================================
// SELECTOR BUTTON HELPERS
// =====================================================================

/**
 * Creates a library selector button.
 */
function createSelectorButton(text, id) {
    const button = document.createElement('button');
    button.textContent = text;
    button.setAttribute('data-library-id', id);
    button.className = 'selector-button';
    return button;
}

// =====================================================================
// SCROLL PROGRESS BAR LOGIC
// =====================================================================

function updateScrollProgress() {
    if (!scrollProgressBar) return;

    // The entire document is the scrollable area
    const scrollHeight = document.documentElement.scrollHeight;
    const clientHeight = document.documentElement.clientHeight;
    const scrollTop = document.documentElement.scrollTop;

    // Total distance the user can scroll from top to bottom
    const scrollableDistance = scrollHeight - clientHeight;

    let scrollPercent = 0;
    if (scrollableDistance > 0) {
        // Calculate percentage scrolled
        scrollPercent = (scrollTop / scrollableDistance) * 100;
    }

    // Apply the percentage width to the bar
    scrollProgressBar.style.width = `${scrollPercent}%`;
}

// =====================================================================
// WATCH STATUS ICONS
// =====================================================================

/**
 * Returns icon path for watch status: 0 (none), 1 (partial), 2 (full).
 */
function getWatchIconUrl(watchStatus) {
    switch (watchStatus) {
        case 2: return 'assets/images/watched_full.webp';
        case 1: return 'assets/images/watched_partial.webp';
        default: return 'assets/images/watched_none.webp';
    }
}

// =====================================================================
// A simple function to escape characters for HTML safety
// =====================================================================
function escapeHTML(str) {
    if (typeof str !== 'string') return str;
    return str.replace(/[&<>"']/g, function(m) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&apos;'
        }[m];
    });
}


// =====================================================================
// Retrieve Library Data
// =====================================================================

/**
 * Retrieves the library object from the global data store.
 */
function getLibraryById(libraryId) {
    if (allData && allData.libraries) {
        return allData.libraries[libraryId.toString()];
    }
    return null;
}

// =====================================================================
// QUICK INFO POP-UP (Tap-and-Hold Logic)
// =====================================================================

const PRESS_DURATION = 350; // Time in milliseconds for long press

/**
 * Creates and displays the quick info pop-up using the global element.
 */
function showQuickInfo(item) {
    // ------------------------------------------------------------------
    // Retrieve the library object to get the certificationCountry
    // ------------------------------------------------------------------
    const library = getLibraryById(item.libraryId); 

    // ------------------------------------------------------------------
    // LINE 1: YEAR / TOTAL ALBUMS LOGIC
    // ------------------------------------------------------------------
    let yearOrAlbums = 'N/A';
    if (item.type === 'music_artist' && item.totalAlbums) {
        const albumCount = parseInt(item.totalAlbums);
        yearOrAlbums = `${albumCount} ${albumCount === 1 ? 'Album' : 'Albums'}`;
    } else {
        yearOrAlbums = item.year || 'N/A';
    }
    
    // ------------------------------------------------------------------
    // LINE 2: DURATION / RUNTIME LOGIC
    // ------------------------------------------------------------------
    let durationValue = item.duration || item.totalduration || item.albumduration;
    let durationText = 'N/A';

    if (typeof durationValue === 'string' && durationValue.length > 0) {
        let parts = durationValue.split(':');
    
        if (parts.length === 2) {
            // If format is MM:SS (e.g., "10:30"), prepend '00' for hours.
            parts.unshift('00');
        } 
    
        if (parts.length === 3) {
            // Format to HH:MM:SS, ensuring all parts are 2 digits for consistency.
            const hours = parts[0].padStart(2, '0');
            const minutes = parts[1].padStart(2, '0');
            const seconds = parts[2].padStart(2, '0');
        
            durationText = `${hours}:${minutes}:${seconds}`;
        } else {
            // Fallback for non-standard or unexpected formats
            durationText = durationValue; 
        }
    }
    // ------------------------------------------------------------------
    // TOTAL SEASONS (if item is a show)
    // ------------------------------------------------------------------
    let seasonsText = '';
    if (item.type === 'show' && item.totalSeasons > 0) {
        const seasonCount = parseInt(item.totalSeasons);
        seasonsText = `${seasonCount} ${seasonCount === 1 ? 'Season' : 'Seasons'}`;
    }
    
    // ------------------------------------------------------------------
    // LINE 3: FILE SIZE LOGIC
    // ------------------------------------------------------------------
    let fileSize = item.fileSizeGB || item.totalFileSizeGB || item.albumFileSizeGB; 
    let sizeText = 'N/A';
    if (fileSize) {
        const sizeNum = parseFloat(fileSize);
        if (!isNaN(sizeNum)) {
            sizeText = `${sizeNum.toFixed(2)} GB`; 
        } else {
            sizeText = `${fileSize} GB`; 
        }
    }
    
    // ------------------------------------------------------------------
    // CERTIFICATION ICON 
    // ------------------------------------------------------------------
    let certificationIconHtml = '';

    const certificationCountry = library ? library.certificationCountry : null; 
    const contentRating = item.contentRating;

    // Only show for Movie or Show types and if data exists
    if (certificationCountry && contentRating && (item.type === 'movie' || item.type === 'show')) {
        // Construct the dynamic path: assets/images/certifications/COUNTRY/TYPE/RATING.webp
        const imagePath = `assets/images/certifications/${certificationCountry}/${item.type}/${contentRating}.webp`; 
        certificationIconHtml = `
		<img src="${imagePath}" 
			alt="${contentRating} Certification" 
			title="${contentRating} (${certificationCountry})" 
            class="info-certification-icon overlay-icon"
            onerror="handleCertificationError(this)">`;
			 
    }

    // ------------------------------------------------------------------
    // WATCH STATUS ICON 
    // ------------------------------------------------------------------
    const watchStatus = item.w || 0; // Get existing numeric status (0=none, 1=partial, 2=full)
    let watchStatusHtml = '';

    if (watchStatus > 0) {  
        // We only show the icon if it is partially or fully watched (1 or 2)
        const watchIconUrl = getWatchIconUrl(watchStatus); // Calls existing function 
        watchStatusHtml = `
            <div class="quick-info-status-overlay">
                <img src="${watchIconUrl}" alt="Watched Status ${watchStatus}" draggable="false">
            </div>`;
    }

    // ------------------------------------------------------------------
    // NEW VARIABLES FOR VISUAL DISPLAY
    // ------------------------------------------------------------------
    const titleText = item.title || 'N/A';  
    const thumbUrl = item.thumb_url || '';  
    
    // Determine the main label based on item type
    const mainLabel = item.type === 'music_artist' ? 'Artist' : (item.type === 'show' ? 'Series' : 'Movie');  
    
    // ------------------------------------------------------------------
    // DYNAMIC STATS HTML (Including Seasons)
    // ------------------------------------------------------------------
    let statsHtml = `<span class="stat-year">${yearOrAlbums}</span>`;  
    
    // Add runtime for movies/shows/artists
    if (durationText !== 'N/A') {
        statsHtml += `<span class="stat-divider">•</span><span class="stat-runtime">${durationText}</span>`;
    }
    
    // Add seasons ONLY if it's a show and we have the count
    if (seasonsText) {
        statsHtml += `<span class="stat-divider">•</span><span class="stat-seasons">${seasonsText}</span>`;  
    }
    
    // ------------------------------------------------------------------
    // POPUP HTML 
    // ------------------------------------------------------------------
quickInfoPopup.innerHTML = `
    <div class="quick-info-content">
        ${certificationIconHtml} 
        
        <div class="info-thumb-wrapper">
            ${watchStatusHtml} 
            <img src="${thumbUrl}" alt="${titleText}" class="info-thumb" draggable="false">
        </div>
        <div class="info-details">
            <div class="info-title">${titleText}</div>
            <div class="info-stats">
                ${statsHtml}
            </div>
            <div class="info-filesize">
                File Size: <strong>${sizeText}</strong>
            </div>
        </div>
    </div>
`;

    // Show the pop-up
    quickInfoPopup.classList.add('visible'); 

}
/**
 * Hides the quick info pop-up.
 */
function hideQuickInfo() {
    quickInfoPopup.classList.remove('visible'); 
}

// =====================================================================
// LIBRARY SELECTOR BUTTONS
// =====================================================================

/**
 * Builds selector buttons from libraries JSON. First is active by default.
 */
function generateSelectors(libraries) {
    const selectorContainer = document.querySelector('.library-selectors');
    selectorContainer.innerHTML = '';
    for (const libraryId in libraries) {
        if (!Object.hasOwnProperty.call(libraries, libraryId)) continue;
        const library = libraries[libraryId];
        const button = createSelectorButton(library.name, libraryId);

        if (libraryId === Object.keys(libraries)[0]) {
            button.classList.add('active');
        }

        button.addEventListener('click', (e) => {
            closeModal();
            searchInput.value = ''; // Clear search when switching libraries

            const active = document.querySelector('.selector-button.active');
            if (active) active.classList.remove('active');
            e.target.classList.add('active');

            // **NEW: Reset scroll position and update the bar instantly**
            window.scrollTo({ top: 0, behavior: 'smooth' }); 
            updateScrollProgress(); // Immediately update the bar to 0%

            filterAndRender(libraryId);
        });

        selectorContainer.appendChild(button);
    }
}


// =====================================================================
// FOOTER TOGGLE: SEARCH VS SELECTORS
// =====================================================================

const searchToggleBtn = document.getElementById('search-toggle-btn');
const selectorsContainer = document.getElementById('library-selectors-container');
const searchContainer = document.querySelector('.search-container');
const toggleIcon = document.getElementById('toggle-icon');
/**
 * Sets toggle icon depending on current state (search/menu) and hover.
 */
function updateToggleIcon(isHovering = false) {
    const isSearchExpanded = searchContainer.classList.contains('expanded');
    const iconBase = isSearchExpanded ? 'menu_icon' : 'search_icon';
    const iconState = isHovering ? '_hover.webp' : '.webp';
    toggleIcon.src = `assets/images/${iconBase}${iconState}`;
    toggleIcon.alt = isSearchExpanded ? 'Menu Icon' : 'Search Icon';
}

/**
 * Switches footer between selectors list and search input.
 * Focuses input when expanding; clears search when collapsing.
 */
function toggleFooterState() {
    const isSearchExpanded = searchContainer.classList.contains('expanded');
    const newStateIsSearch = !isSearchExpanded;

    searchContainer.classList.toggle('expanded', newStateIsSearch);
    selectorsContainer.classList.toggle('collapsed', newStateIsSearch);

    updateToggleIcon(false);
    // If hover persists across state change, apply hover asset immediately
    if (searchToggleBtn.matches(':hover')) {
        updateToggleIcon(true);
    }

    if (newStateIsSearch) {
        // Delay aligns with CSS transition for a natural focus
        setTimeout(() => searchInput.focus(), 400);
    } else {
        // Collapse: clear term and re-render current library
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
    }
}

// Hover feedback for toggle button
searchToggleBtn.addEventListener('mouseover', () => updateToggleIcon(true));
searchToggleBtn.addEventListener('mouseout', () => updateToggleIcon(false));
// Click toggles footer state
searchToggleBtn.addEventListener('click', toggleFooterState);


// =====================================================================
// IMAGE FALLBACK HANDLER
// =====================================================================

/**
 * Handles image loading failure (e.g., browser doesn't support AVIF).
 * Attempts to fall back to the JPEG version of the image.
 */
function handleImageError(img) {
    if (img.src.endsWith('.avif')) {
        // Attempt fallback to JPEG
        img.src = img.src.replace('.avif', '.jpeg');
        // Prevent an infinite loop if JPEG also fails
        img.onerror = null;
    } else {
        // If it wasn't AVIF, or JPEG also failed, use a standard placeholder.
        img.src = 'assets/images/placeholder.avif'; // Ensure you have a generic placeholder
        img.onerror = null;
    }
}

// =====================================================================
// Handles image loading failure for certification icons.
// =====================================================================
function handleCertificationError(img) {
    // Function to hide the image entirely
    const hideImage = () => {
        img.onerror = null; 
        img.src = ''; // Setting src to empty string hides the image
        img.style.display = 'none'; // Ensure it doesn't take up space
    };

    hideImage();
}

// =====================================================================
// MUSIC MODAL LOGIC (New Function)
// =====================================================================

/**
 * Toggles the visibility of the album track listing when the header is clicked.
 * @param {HTMLElement} clickedElement The .album-details-container that was clicked.
 * @param {string} albumId The ID of the tracks container to toggle.
 */
function toggleAlbumTracks(clickedElement, albumId) {
    const tracksContainer = document.getElementById(albumId);
    if (tracksContainer) {
        // Toggle the 'collapsed' class to trigger CSS transition (requires your CSS)
        tracksContainer.classList.toggle('collapsed');

        // Optional: Toggle a class on the header for visual feedback (e.g., changing background/icon)
        clickedElement.classList.toggle('expanded', !tracksContainer.classList.contains('collapsed'));
    }
}


// =====================================================================
// MODAL CONSTRUCTION & LIFECYCLE
// =====================================================================

/**
 * Builds modal content for item type and opens modal.
 */
function showModal(item) {
    let modalContentHTML = '';
    const modalIconUrl = getWatchIconUrl(item.w);
    const modalIconHtml = `<img class="watch-status-overlay" src="${modalIconUrl}" alt="Watched Status ${item.w}" draggable="false">`;
    const modalContent = document.querySelector('.modal-content');

	const modalInfo = modal.querySelector('.modal-info');

    modalContent.classList.remove('modal-type-movie', 'modal-type-show', 'modal-type-artist');


if (item.type === 'music_artist') {
    modalContent.classList.add('modal-type-artist');
} else if (item.type === 'movie') {
    modalContent.classList.add('modal-type-movie');
} else if (item.type === 'show') {
    modalContent.classList.add('modal-type-show');
}

    if (item.type === 'movie') {
        // MOVIE MODAL
        modalContentHTML = `
            <div class="modal-poster modal-poster-container">
                <img src="${item.thumb_url}" alt="${item.title} Poster" onerror="handleImageError(this)" draggable="false">
                ${modalIconHtml}
            </div>
     
            <div class="modal-info">
                <h1>${item.title} (${item.year})</h1>
                ${item.tagline ?
                `<h2>${item.tagline}</h2>` : ''}
                <div class="modal-summary">${item.summary}</div>
                <div class="file-info-group">
                    <p><strong>Rating:</strong> ${item.contentRating}</p>
                    <p><strong>Studio:</strong> ${item.studio}</p>
                    <p><strong>Duration:</strong> ${item.duration}</p>
 
                    <p><strong>Resolution:</strong> ${item.Resolution}</p>
                    <p><strong>Audio:</strong> ${item.audioFormats}</p>
                    <p><strong>File Size:</strong> ${item.fileSizeGB} GB</p>
                    <p><strong>Container:</strong> ${item.fileContainer}</p>
              
             </div>
            </div>
        `;
    } else if (item.type === 'show') {
        // TV SHOW MODAL
        const seasonsHTML = (item.seasons || []).map(season => {
            const episodesHTML = (season.episodes || []).map(episode => {
                const episodeIconUrl = getWatchIconUrl(episode.w);
                const episodeIconHtml = `<img class="episode-watch-icon" src="${episodeIconUrl}" alt="Watched Status ${episode.w}" draggable="false">`;
      
                return `<li>${episodeIconHtml} E${episode.episodeNumber}: ${episode.title} 
                        (${episode.duration}, ${episode.fileSizeGB} GB)</li>`;
            }).join('');

            return `
                <div class="season-block">
            
                    <h3>Season ${season.seasonNumber}</h3>
                    <p>Total Size: <strong>${season.seasonFileSizeGB} GB</strong></p>
                    <p>Total Run Time: <strong>${season.seasonduration}</strong></p>
                    <ul class="episode-list">${episodesHTML}</ul>
          
               </div>
            `;
  
        }).join('');
        const tvShowAggregateInfo = `
            <div class="file-info-group tv-aggregate-info">
                <p><strong>Total Seasons:</strong> ${item.totalSeasons}</p>
                <p><strong>Total Run Time:</strong> ${item.totalduration}</p>
                <p><strong>Total File Size:</strong> ${item.totalFileSizeGB} GB</p>
                <p><strong>Rating:</strong> ${item.contentRating}</p>
  
                <p><strong>Studio:</strong> ${item.studio}</p>
            </div>
        `;
        // Mobile bottom-sheet vs desktop side-by-side layout
        if (window.innerWidth <= 820) {
            modalContentHTML = `
                <div class="modal-poster modal-poster-container">
                    <img src="${item.thumb_url}" alt="${item.title} Poster" onerror="handleImageError(this)" draggable="false">
                    ${modalIconHtml}
      
                </div>
                <div class="modal-info">
                    <h1>${item.title} (${item.year})</h1>
                    ${item.tagline ?
                    `<h2>${item.tagline}</h2>` : ''}
                    <div class="modal-summary">${item.summary}</div>
                    ${tvShowAggregateInfo}
                    <div class="season-list-container">${seasonsHTML}</div>
                </div>
            `;
        } else {
            modalContentHTML = `
                <div class="modal-poster modal-poster-container">
                    <img src="${item.thumb_url}" alt="${item.title} Poster" onerror="handleImageError(this)" draggable="false">
                    ${modalIconHtml} ${tvShowAggregateInfo}
                </div>
     
                <div class="modal-info">
                    <h1>${item.title} (${item.year})</h1>
                    ${item.tagline ?
                    `<h2>${item.tagline}</h2>` : ''}
                    <div class="modal-summary">${item.summary}</div>
                    <div class="season-list-container">${seasonsHTML}</div>
                </div>
            `;
        }
} else if (item.type === 'music_artist') {
        // MUSIC ARTIST MODAL

        const albums = item.albums || [];
        const totalAlbums = albums.length;


        const generateAlbumHtml = (albumList) => {
            let htmlContent = '';

            albumList.forEach((album, index) => {
                // Flatten tracks from either disc-based or flat structure
                const allTracks = [];
                if (Array.isArray(album.seasons)) {
                    album.seasons.forEach(disc => {
                        if (Array.isArray(disc.episodes)) {
                            allTracks.push(...disc.episodes);
                        }
                    });
                }

                if (Array.isArray(album.tracks)) {
                    allTracks.push(...album.tracks);
                }

                if (allTracks.length === 0) {
                    htmlContent += `
                        <div class="album-track-listing">
                            <h2 class="album-title-header">
                                <img src="${album.thumb_url}" alt="${album.albumTitle} Art" class="album-art-thumb" onerror="handleImageError(this)" draggable="false">
                                ${album.albumTitle} (${album.year})
                            </h2>
                            <p class="album-track-summary" style="padding: 10px 0;">No track information available for this album.</p>
                        </div>
                    `;
                    return; // Skip the rest of the loop for this album
                }

                // Group tracks by disc for presentation
                const tracksByDisc = allTracks.reduce((acc, track) => {
                    const discNum = track.discNumber || track.seasonNumber || 1;
                    (acc[discNum] ||= []).push(track);
                    return acc;
                }, {});
                const discsHTML = Object.keys(tracksByDisc).map(discNum => {
                    const tracks = tracksByDisc[discNum];
                    const discTracksHTML = tracks.map(track => {
                        const trackNum = track.trackNumber || track.episodeNumber || 1;
                        return `
                            <li>${trackNum}: ${track.title}
                                <span class="episode-details">(${track.duration || 'N/A'}, ${track.fileSizeMB || 'N/A'} MB)</span>
                            </li>
                        `;
                    }).join('');

                    const discHeader = Object.keys(tracksByDisc).length > 1 ? `<h3>Disc ${discNum}</h3>` : '';
                    return `
                        <div class="season-block">
                            ${discHeader}
                            <ul class="episode-list">${discTracksHTML}</ul>
                        </div>
                    `;
                }).join('');
				
                // The ID creation is safe and correct:
                const cleanAlbumTitleForID = album.albumTitle.replace(/'/g, '').replace(/\s/g, '-');
                const albumId = `album-tracks-${album.ratingKey}-${cleanAlbumTitleForID}`;

                // New: Create HTML-safe variables for displaying the data
                const safeAlbumTitle = escapeHTML(album.albumTitle);
                const safeAlbumYear = escapeHTML(album.year);

                // Header (Visible always, and is the click target)
                const headerContentHTML = `
                    <div class="album-details-container"
                        onclick="toggleAlbumTracks(this, '${albumId}')">
                        <div class="album-art-thumb-wrapper">
                            <img src="${album.thumb_url}" alt="${safeAlbumTitle} Art "
                                                class="album-art-thumb" onerror="handleImageError(this)"
                                                loading="lazy" draggable="false"> </div>
                        <div class="album-text-content">
                            <h2 class="album-title-header">${safeAlbumTitle} (${safeAlbumYear})</h2>
                            <div class="album-summary-stats">
                                <p class="album-track-summary">
                                    Total File Size: <strong>${album.albumFileSizeGB || 'N/A'} GB</strong>
                                </p>
                                <p class="album-track-summary">
                                    Total Run Time: <strong>${album.albumduration || 'N/A'}</strong>
                                </p>
                            </div>
                        </div>
                    </div>
                `;
                // Tracks (Wrapped and collapsed by default)
                const tracksCollapsibleHTML = `
                    <div class="album-tracks-container album-tracks-collapsible grid-layout collapsed" id="${albumId}">
                        <div class="disc-container">
                            ${discsHTML}
                        </div>
                    </div>
                `;
                // Accumulate the final HTML for this album
                htmlContent += `
                    <div class="album-track-listing">
                        ${headerContentHTML}
                        ${tracksCollapsibleHTML}
                    </div>
                `;
            });

            return htmlContent;
        };
		
// ------------------------------------------------------------------
// Album distribution logic (SIMPLIFIED FOR CSS GRID)
// ------------------------------------------------------------------

// All albums are combined into one HTML string. CSS Grid handles the layout.
const allAlbumsHTML = generateAlbumHtml(albums);

const musicAggregateInfo = `
    <div class="file-info-group tv-aggregate-info">
        <p><strong>Total Albums:</strong> ${item.totalAlbums || 0}</p>
        <p><strong>Total Run Time:</strong> ${item.totalduration || 'N/A'}</p>
        <p><strong>Total File Size:</strong> ${item.totalFileSizeGB || 'N/A'} GB</p>
    </div>
`;
const artistYear = item.year ? `(${item.year})` : '';

modalContentHTML = `
  <div class="modal-poster modal-poster-container">
    <img src="${item.thumb_url}" alt="${item.title} Poster"
      onerror="handleImageError(this)" draggable="false">
    <h1>${item.title} ${artistYear}</h1>
    ${modalIconHtml} ${musicAggregateInfo}
  </div>
  <div class="modal-info">
    <div class="season-list-container album-grid-responsive">
      <div class="album-list-grid-container"> 
        ${allAlbumsHTML}
      </div>
    </div>
  </div>
`;


    // The closing brace for the 'music_artist' condition should follow immediately here.
    }

    // Inject content and open modal
    modalDetails.innerHTML = modalContentHTML;
    modal.style.display = 'flex';
    modalDetails.scrollTop = 0;

    // Delay ensures CSS sees initial transform before applying .is-open
    setTimeout(() => {
        modal.classList.add('is-open');
        htmlElement.classList.add('modal-open'); // Lock scroll (html)
        document.body.classList.add('modal-open'); // Lock scroll (body fallback)
    }, 10);
}


// Closes modal with transition and re-enables background scroll.
function closeModal() {
    modal.classList.remove('is-open');
    htmlElement.classList.remove('modal-open');
    document.body.classList.remove('modal-open');
    // Wait for CSS transition before hiding wrapper
    setTimeout(() => {
        modal.style.display = 'none';
        modalDetails.innerHTML = '';
    }, 300);
}


// =====================================================================
// FILTERING & GRID RENDER
// =====================================================================

function filterAndRender(libraryId, searchTerm = '') {
    if (!allData || !allData.items) return;

    activeLibraryId = libraryId;
    const items = allData.items;
    let filteredItems = libraryId === 'all'
        ?
        items
        : items.filter(item => item.libraryId === parseInt(libraryId, 10));

    const term = searchTerm.toLowerCase().trim();
    if (term) {
        filteredItems = filteredItems.filter(item =>
            item.title.toLowerCase().includes(term)
        );
    }

    filteredItems.sort((a, b) => a.title.localeCompare(b.title));

    if (filteredItems.length === 0) {
        const libraryName = libraryId === 'all'
            ?
            'All Libraries'
            : (allData.libraries[libraryId]?.name || 'Unknown Library');
        libraryContainer.innerHTML =
            `<p style="text-align:center; padding: 50px; font-size: 1.2em;">
                No titles found in ${libraryName} matching "${term}".
            </p>`;
        return;
    }

    renderGrid(filteredItems);

    // If search was used, scroll to top
    if (term !== '') {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

/**
 * Renders the grid with clickable items that open modals.
 */
function renderGrid(filteredItems) {
    const container = document.getElementById('library-container');

    const dataMap = filteredItems.map(item => {
        const originalIndex = allData.items.findIndex(d => d === item);
        return { ...item, originalIndex };
    });
    
    const htmlContent = dataMap.map(item => {
        const iconUrl = getWatchIconUrl(item.w);
        const watchIconHtml = `<img class="watch-status-overlay" src="${iconUrl}" alt="Watched Status ${item.w}" draggable="false">`;

        let extraInfo = '';
        if (item.type === 'music_artist') {
            const totalAlbums = item.totalAlbums ?? 0;
            const albumText = totalAlbums === 
                1 ? 'Album' : 'Albums'; 
            extraInfo = `<p class="stats">${totalAlbums} ${albumText}</p>`;
        }
        
        return `
            <div class="item" data-original-index="${item.originalIndex}" data-type="${item.type}">
                <div class="poster-container">
               
                    <img src="${item.thumb_url}" alt="${item.title} Poster" loading="lazy" onerror="handleImageError(this)" draggable="false">
       
                ${watchIconHtml}
                </div>
                <div class="info">
                     <h3>${item.title}</h3>
                    ${extraInfo}
                </div>
            </div>
        `;
    }).join('');

    container.innerHTML = htmlContent;

    // =================================================================
    // Tap-and-Hold Logic to all newly rendered items
    // =================================================================
container.querySelectorAll('.item').forEach(itemElement => {
    const originalIndex = parseInt(itemElement.getAttribute('data-original-index'));
    const item = allData.items[originalIndex]; 

    let startX = 0;
    let startY = 0;
    const MOVE_THRESHOLD = 20;
    let longPressTriggered = false;

    const openItemModal = () => showModal(item); 

    // TOUCHSTART
    itemElement.addEventListener('touchstart', (e) => {
        activePressTimer.clear();
        longPressTriggered = false;

        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;

        activePressTimer.timerId = setTimeout(() => {
            showQuickInfo(item);
            longPressTriggered = true;  
            activePressTimer.timerId = null;
        }, PRESS_DURATION);
    }, { passive: true });

    // TOUCHMOVE
    itemElement.addEventListener('touchmove', (e) => {
        if (activePressTimer.timerId) { 
            const moveX = e.touches[0].clientX;
            const moveY = e.touches[0].clientY;
            if (Math.abs(moveX - startX) > MOVE_THRESHOLD ||
                Math.abs(moveY - startY) > MOVE_THRESHOLD) {
                activePressTimer.clear();
            }
        }
    }, { passive: true });

    // TOUCHEND
    itemElement.addEventListener('touchend', (e) => {
        if (activePressTimer.timerId) {
            // QUICK TAP
            activePressTimer.clear();
            openItemModal();
			e.preventDefault();
        } else if (longPressTriggered) {
		    activePressTimer.clear();
            e.preventDefault();
        }
    }, { passive: false });

    // Cancel paths
    const cancelPress = () => {
        activePressTimer.clear();
        if (!longPressTriggered) { 
            hideQuickInfo();
        }
        longPressTriggered = false;
    };
    itemElement.addEventListener('touchcancel', cancelPress);
    itemElement.addEventListener('mouseleave', cancelPress);

    // Prevent context menu
    itemElement.addEventListener('contextmenu', (e) => e.preventDefault());

    // CLICK handler — suppress if long press was triggered
    itemElement.addEventListener('click', (e) => {
        if (longPressTriggered) {
            e.preventDefault();
            e.stopPropagation();
            longPressTriggered = false; // reset
            return; // don’t open modal
        }
        activePressTimer.clear();
        hideQuickInfo();
        showModal(item);
    });
});


}

// =====================================================================
// LOADER & INITIALIZATION
// =====================================================================

/**
 * Simulates progress to give UI feedback while fetch runs.
 */
function simulateProgress() {
    let progress = 0;
    progressBar.style.width = '20%';
    const interval = setInterval(() => {
        progress += Math.floor(Math.random() * 15) + 5; // Adds 5–20%
        if (progress < 90) {
            progressBar.style.width = `${progress}%`;
        } else {
            clearInterval(interval);
            progressBar.style.width = '90%';
        }
    }, 400);
    return interval; // Allow cancellation when data arrives
}


// =====================================================================
// BOOTSTRAP
// =====================================================================

document.addEventListener('DOMContentLoaded', () => {
    // Close modal via X button or clicking backdrop
    closeButton.addEventListener('click', closeModal);
    window.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });

    // Escape collapses search if open
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && searchContainer.classList.contains('expanded')) {
            toggleFooterState();
     
        }
    });

    // Escape closes modal if open
    window.addEventListener('keydown', (event) => {
        if ((event.key === 'Escape' || event.keyCode === 27) && modal.classList.contains('is-open')) {
            closeModal();
        }
    });

    // Live search input
    searchInput.addEventListener('input', (event) => {
        filterAndRender(activeLibraryId, event.target.value);
    });

    // Data fetch + loader
    const progressInterval = simulateProgress();
    fetch('data/library.json')
        .then(response => {
            if (!response.ok) {
                clearInterval(progressInterval);
                loadingOverlay.classList.add('hidden');
                throw new Error(`HTTP error! status: ${response.status}`);
            }
           
            return response.json();
        })
        .then(data => {
            clearInterval(progressInterval);
            progressBar.style.width = '100%';

            allData = data;

            // Header title from JSON 
            
            const headerElement = document.getElementById('page-header');
        
            if (headerElement && data.websiteHeader) {
                headerElement.textContent = data.websiteHeader;
            }

            // Update search placeholder with export timestamp
            const searchInput = document.getElementById('search-input');
         
            if (searchInput && data.exportDate) {
            
                searchInput.placeholder = `Search titles... (Updated: ${data.exportDate})`;
            }

            // Build selectors and render first library by default
            generateSelectors(allData.libraries);
            const firstLibraryId = Object.keys(allData.libraries)[0];
            filterAndRender(firstLibraryId);

            // Fade out loader
            setTimeout(() => loadingOverlay.classList.add('hidden'), 300);
        })
        .catch(error => {
            console.error('Error loading library data:', error);
            document.getElementById('library-container').innerHTML =
                '<p style="text-align:center; color: red; padding-top: 50px;">Could not load library data. Check the backend script and file path/permissions.</p>';
            loadingOverlay.classList.add('hidden');
        });
	
	// Listen for scrolling on the main window
    window.addEventListener('scroll', updateScrollProgress);

    // Initial calculation in case the page loads scrolled down
    document.addEventListener('DOMContentLoaded', updateScrollProgress);
		
});


// =====================================================================
// GLOBAL DISMISSAL CLEANUP
// =====================================================================

document.addEventListener('mouseup', () => {
    // We only hide the popup if it is currently visible
    if (quickInfoPopup.classList.contains('visible')) {
        hideQuickInfo();
        longPressTriggered = false; // Reset the flag for the next press
    }
});

// Use a touch-specific listener for mobile devices
document.addEventListener('touchend', (e) => {
    // Only fire if the touch event didn't originate from inside the popup (optional: for safety)
    if (quickInfoPopup.classList.contains('visible') && !quickInfoPopup.contains(e.target)) {
        hideQuickInfo();
        longPressTriggered = false; // Reset the flag
    }
});