/**
 * Photo Print Studio -- front-end wizard.
 *
 * Vanilla JS, no build step: talks to the pps/v1 REST routes registered in
 * class-pps-rest.php and renders the multi-step flow into the
 * [photo_print_wizard] shortcode container.
 *
 * Customers can upload up to MAX_PHOTOS photos into one order. Paper,
 * mount and finish are chosen once and shared by every photo; each photo
 * gets its own format/size, quantity, and (if needed) its own crop/
 * rotation, since photos can have different pixel dimensions, aspect
 * ratios, and the customer may want a different print size per photo.
 */
( function () {
	'use strict';

	if ( typeof PPS_CONFIG === 'undefined' ) {
		return;
	}

	var STEP_KEYS = [ 'upload', 'check', 'size', 'mount', 'paper', 'finish', 'summary' ];
	var MAX_PHOTOS = 10;

	// Photos are uploaded in small chunks rather than one request so large
	// files aren't blocked by the host's upload_max_filesize/post_max_size
	// limits (settings this plugin cannot change from PHP at runtime).
	var CHUNK_SIZE_BYTES = 2 * 1024 * 1024;

	var state = {
		step: 'upload',
		photos: [], // see newPhoto() below for shape -- format/size now lives per photo
		catalogue: null,
		activePhotoIndex: -1, // which photo's format+crop panel is expanded (size step)
		dpiCheckInFlight: false,
		mountId: 0,
		paperId: 0,
		finishId: 0,
		deliveryMethod: '', // 'shipping' or 'pickup', chosen in the summary step
		busy: false,
		uploadBusy: false,
		uploadProgress: 0,
		error: '',
	};

	var root;
	var i18n;

	function t( key ) {
		return ( i18n && i18n[ key ] ) || key;
	}

	function apiFetch( path, options ) {
		options = options || {};
		options.headers = options.headers || {};
		options.headers[ 'X-WP-Nonce' ] = PPS_CONFIG.nonce;

		return fetch( PPS_CONFIG.restUrl + path, options ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					var message = ( data && data.message ) || t( 'genericError' );
					return Promise.reject( new Error( message ) );
				}
				return data;
			} );
		} );
	}

	function formatMoney( amount ) {
		var symbol = ( state.catalogue && state.catalogue.currency ) || '€';
		return symbol + ' ' + parseFloat( amount ).toFixed( 2 ).replace( '.', ',' );
	}

	function activeSteps() {
		return STEP_KEYS.filter( function ( key ) {
			if ( 'finish' === key ) {
				return mountRequiresFinish();
			}
			return true;
		} );
	}

	function mountRequiresFinish() {
		if ( ! state.catalogue || ! state.mountId ) {
			return false;
		}
		var mount = findById( state.catalogue.mounts, state.mountId );
		return !! ( mount && mount.requires_finish );
	}

	function findById( list, id ) {
		if ( ! list ) {
			return null;
		}
		for ( var i = 0; i < list.length; i++ ) {
			if ( list[ i ].id === id ) {
				return list[ i ];
			}
		}
		return null;
	}

	function goTo( step ) {
		state.step = step;
		state.error = '';
		render();
	}

	function nextStep() {
		var steps = activeSteps();
		var idx = steps.indexOf( state.step );
		if ( idx > -1 && idx < steps.length - 1 ) {
			goTo( steps[ idx + 1 ] );
		}
	}

	function prevStep() {
		var steps = activeSteps();
		var idx = steps.indexOf( state.step );
		if ( idx > 0 ) {
			goTo( steps[ idx - 1 ] );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Rendering shell                                                     */
	/* ------------------------------------------------------------------ */

	function render() {
		root.innerHTML = '';
		root.appendChild( renderProgress() );

		var panel = document.createElement( 'div' );
		panel.className = 'pps-panel is-active';

		switch ( state.step ) {
			case 'upload':
				panel.appendChild( renderUpload() );
				break;
			case 'check':
				panel.appendChild( renderCheck() );
				break;
			case 'size':
				panel.appendChild( renderSize() );
				break;
			case 'mount':
				panel.appendChild( renderMount() );
				break;
			case 'paper':
				panel.appendChild( renderPaper() );
				break;
			case 'finish':
				panel.appendChild( renderFinish() );
				break;
			case 'summary':
				panel.appendChild( renderSummary() );
				break;
		}

		root.appendChild( panel );
	}

	function renderProgress() {
		var labels = {
			upload: t( 'stepUpload' ),
			check: t( 'stepCheck' ),
			size: t( 'stepSize' ),
			mount: t( 'stepMount' ),
			paper: t( 'stepPaper' ),
			finish: t( 'stepFinish' ),
			summary: t( 'stepSummary' ),
		};

		var steps = activeSteps();
		var currentIdx = steps.indexOf( state.step );

		var ul = document.createElement( 'ul' );
		ul.className = 'pps-steps';

		steps.forEach( function ( key, i ) {
			var li = document.createElement( 'li' );
			li.setAttribute( 'data-step-number', i + 1 );
			li.textContent = labels[ key ];
			if ( i === currentIdx ) {
				li.classList.add( 'is-active' );
			} else if ( i < currentIdx ) {
				li.classList.add( 'is-done' );
			}
			ul.appendChild( li );
		} );

		return ul;
	}

	function actionsBar( opts ) {
		opts = opts || {};
		var bar = document.createElement( 'div' );
		bar.className = 'pps-actions';

		if ( opts.showBack ) {
			var back = document.createElement( 'button' );
			back.type = 'button';
			back.className = 'pps-btn pps-btn--secondary';
			back.textContent = t( 'back' );
			back.addEventListener( 'click', prevStep );
			bar.appendChild( back );
		} else {
			bar.appendChild( document.createElement( 'span' ) );
		}

		if ( opts.nextLabel !== false ) {
			var next = document.createElement( 'button' );
			next.type = 'button';
			next.className = 'pps-btn';
			next.textContent = opts.nextLabel || t( 'continue' );
			next.disabled = !! opts.nextDisabled;
			next.addEventListener( 'click', opts.onNext || nextStep );
			bar.appendChild( next );
		}

		return bar;
	}

	function errorBox() {
		if ( ! state.error ) {
			return document.createDocumentFragment();
		}
		var div = document.createElement( 'div' );
		div.className = 'pps-error';
		div.textContent = state.error;
		return div;
	}

	function clamp( value, min, max ) {
		if ( max < min ) {
			return min;
		}
		return Math.max( min, Math.min( max, value ) );
	}

	function effectiveDpi( px, cm ) {
		if ( cm <= 0 ) {
			return 0;
		}
		return px / ( cm / 2.54 );
	}

	/**
	 * @param {number} widthCm
	 * @param {number} heightCm
	 * @return {number} Square metres -- mirrors PPS_Pricing::area_m2().
	 */
	function areaM2( widthCm, heightCm ) {
		return ( widthCm / 100 ) * ( heightCm / 100 );
	}

	/**
	 * @param {number} widthCm
	 * @param {number} heightCm
	 * @return {number} Running/linear metres -- mirrors PPS_Pricing::perimeter_m().
	 */
	function perimeterM( widthCm, heightCm ) {
		return ( 2 * ( widthCm + heightCm ) ) / 100;
	}

	/**
	 * Sums a per-photo cost function across every uploaded photo (each at
	 * its own chosen size and quantity), so the paper/finish steps can show
	 * the customer a real total for their own order instead of an abstract
	 * per-unit rate.
	 *
	 * @param {function(Object): number} perPhotoCost
	 * @return {number}
	 */
	function sumOverPhotos( perPhotoCost ) {
		return state.photos.reduce( function ( sum, photo ) {
			if ( ! photo.widthCm || ! photo.heightCm ) {
				return sum;
			}
			return sum + perPhotoCost( photo ) * ( photo.quantity || 1 );
		}, 0 );
	}

	/**
	 * Largest sub-rectangle of an image matching the target aspect ratio
	 * (a centred "cover" crop) -- used both as the crop tool's zoom=1
	 * baseline and as the automatic default crop for photos that already
	 * match and never need the tool opened.
	 */
	function computeBaseCrop( imgW, imgH, targetWCm, targetHCm ) {
		var targetRatio = targetWCm / targetHCm;
		var imgRatio = imgW / imgH;
		var sw, sh;
		if ( imgRatio > targetRatio ) {
			sh = imgH;
			sw = sh * targetRatio;
		} else {
			sw = imgW;
			sh = sw / targetRatio;
		}
		return { sw: sw, sh: sh };
	}

	function newPhoto( uploadResult ) {
		return {
			attachmentId: uploadResult.attachment_id,
			imgUrl: uploadResult.url,
			imgWidth: uploadResult.width,
			imgHeight: uploadResult.height,
			suggestedMaxLongCm: uploadResult.suggested_max_long_cm,
			quantity: 1,
			// Format/size: chosen individually per photo.
			formatId: 0,
			widthCm: 0,
			heightCm: 0,
			useCustomSize: false,
			// DPI/crop/rotation, also per photo.
			dpiInfo: null,
			showCropTool: false,
			cropEditorOpen: false,
			crop: null,
			rotation: 0,
			zoom: 1,
			// Per-photo price (format varies per photo, so price does too).
			pricing: null,
		};
	}

	/* ------------------------------------------------------------------ */
	/* Step 1: upload (up to MAX_PHOTOS photos)                            */
	/* ------------------------------------------------------------------ */

	function renderUpload() {
		var wrap = document.createElement( 'div' );

		var h2 = document.createElement( 'h2' );
		h2.textContent = t( 'stepUpload' );
		wrap.appendChild( h2 );

		wrap.appendChild( errorBox() );

		if ( state.photos.length < MAX_PHOTOS ) {
			wrap.appendChild( renderDropzone() );
		} else {
			var maxNotice = document.createElement( 'p' );
			maxNotice.className = 'pps-warning';
			maxNotice.textContent = t( 'maxPhotosReached' );
			wrap.appendChild( maxNotice );
		}

		if ( state.photos.length > 0 ) {
			wrap.appendChild( renderPhotoThumbnails() );
		}

		wrap.appendChild(
			actionsBar( {
				showBack: false,
				nextDisabled: 0 === state.photos.length || state.uploadBusy,
				onNext: function () {
					goTo( 'check' );
				},
			} )
		);

		return wrap;
	}

	function renderDropzone() {
		var zone = document.createElement( 'div' );
		zone.className = 'pps-dropzone';

		var icon = document.createElement( 'div' );
		icon.className = 'pps-dropzone__icon';
		icon.textContent = '↑';
		zone.appendChild( icon );

		var text = document.createElement( 'p' );
		text.textContent = state.uploadBusy ? t( 'uploading' ) : t( 'dropText' );
		zone.appendChild( text );

		var input = document.createElement( 'input' );
		input.type = 'file';
		input.accept = 'image/jpeg,image/png,image/tiff,image/webp';
		input.multiple = true;
		zone.appendChild( input );

		zone.addEventListener( 'click', function () {
			if ( ! state.uploadBusy ) {
				input.click();
			}
		} );

		[ 'dragenter', 'dragover' ].forEach( function ( evt ) {
			zone.addEventListener( evt, function ( e ) {
				e.preventDefault();
				zone.classList.add( 'is-dragover' );
			} );
		} );

		[ 'dragleave', 'drop' ].forEach( function ( evt ) {
			zone.addEventListener( evt, function ( e ) {
				e.preventDefault();
				zone.classList.remove( 'is-dragover' );
			} );
		} );

		zone.addEventListener( 'drop', function ( e ) {
			var files = e.dataTransfer && e.dataTransfer.files;
			if ( files && files.length ) {
				handleFiles( files );
			}
		} );

		input.addEventListener( 'change', function () {
			if ( input.files && input.files.length ) {
				handleFiles( input.files );
			}
		} );

		if ( state.uploadBusy ) {
			var progress = document.createElement( 'div' );
			progress.className = 'pps-upload-progress';
			progress.setAttribute( 'data-pps-upload-progress', '' );
			progress.textContent = t( 'uploading' ) + ' ' + state.uploadProgress + '%';
			zone.appendChild( progress );
		}

		return zone;
	}

	function renderPhotoThumbnails() {
		var section = document.createElement( 'div' );

		var heading = document.createElement( 'h3' );
		heading.textContent = t( 'photosHeading' );
		section.appendChild( heading );

		var grid = document.createElement( 'div' );
		grid.className = 'pps-photo-grid';

		state.photos.forEach( function ( photo, index ) {
			var card = document.createElement( 'div' );
			card.className = 'pps-photo-card';

			var removeBtn = document.createElement( 'button' );
			removeBtn.type = 'button';
			removeBtn.className = 'pps-photo-card__remove';
			removeBtn.textContent = '×';
			removeBtn.setAttribute( 'aria-label', t( 'removePhoto' ) );
			removeBtn.addEventListener( 'click', function () {
				state.photos.splice( index, 1 );
				// Indices shift after removal -- drop any stale reference to
				// a photo's format/crop panel being "open".
				state.activePhotoIndex = -1;
				render();
			} );
			card.appendChild( removeBtn );

			var img = document.createElement( 'img' );
			img.className = 'pps-photo-card__image';
			img.src = photo.imgUrl;
			img.alt = '';
			card.appendChild( img );

			var qtyField = document.createElement( 'label' );
			qtyField.className = 'pps-photo-card__qty';
			var qtySpan = document.createElement( 'span' );
			qtySpan.textContent = t( 'quantityLabel' );
			qtyField.appendChild( qtySpan );
			var qtyInput = document.createElement( 'input' );
			qtyInput.type = 'number';
			qtyInput.min = '1';
			qtyInput.max = '99';
			qtyInput.value = photo.quantity;
			qtyInput.addEventListener( 'change', function () {
				photo.quantity = Math.max( 1, parseInt( qtyInput.value, 10 ) || 1 );
				qtyInput.value = photo.quantity;
			} );
			qtyField.appendChild( qtyInput );
			card.appendChild( qtyField );

			grid.appendChild( card );
		} );

		section.appendChild( grid );
		return section;
	}

	function handleFiles( fileList ) {
		var files = Array.prototype.slice.call( fileList );
		var remaining = MAX_PHOTOS - state.photos.length;

		if ( remaining <= 0 ) {
			state.error = t( 'maxPhotosReached' );
			render();
			return;
		}

		if ( files.length > remaining ) {
			files = files.slice( 0, remaining );
			state.error = t( 'maxPhotosReached' );
		}

		uploadFilesSequentially( files, 0 );
	}

	function uploadFilesSequentially( files, index ) {
		if ( index >= files.length ) {
			return;
		}

		state.uploadBusy = true;
		state.uploadProgress = 0;
		render();

		var file = files[ index ];

		Promise.all( [
			uploadFileChunked( file, function ( pct ) {
				state.uploadProgress = pct;
				var progressEl = root.querySelector( '[data-pps-upload-progress]' );
				if ( progressEl ) {
					progressEl.textContent = t( 'uploading' ) + ' ' + pct + '%';
				}
			} ),
			state.catalogue ? Promise.resolve( state.catalogue ) : apiFetch( '/options' ),
		] )
			.then( function ( results ) {
				state.catalogue = results[ 1 ];
				state.photos.push( newPhoto( results[ 0 ] ) );
				state.uploadBusy = false;
				render();
				uploadFilesSequentially( files, index + 1 );
			} )
			.catch( function ( err ) {
				state.uploadBusy = false;
				state.error = err.message || t( 'uploadError' );
				render();
			} );
	}

	/**
	 * Uploads a file in small sequential chunks: /upload/init starts a
	 * session, each /upload/chunk call appends CHUNK_SIZE_BYTES worth of
	 * data, and /upload/complete assembles + validates the result. Keeping
	 * every individual request small sidesteps hosting limits on the total
	 * size of a single request that would otherwise reject large photos.
	 *
	 * @param {File}     file
	 * @param {Function} onProgress Called with a 0-100 percentage.
	 * @return {Promise}
	 */
	function uploadFileChunked( file, onProgress ) {
		return apiFetch( '/upload/init', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				filename: file.name,
				filesize: file.size,
				mime_type: file.type,
			} ),
		} ).then( function ( initData ) {
			var uploadId = initData.upload_id;
			var totalChunks = Math.max( 1, Math.ceil( file.size / CHUNK_SIZE_BYTES ) );

			function uploadNext( idx ) {
				if ( idx >= totalChunks ) {
					if ( onProgress ) {
						onProgress( 100 );
					}
					return apiFetch( '/upload/complete', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( { upload_id: uploadId } ),
					} );
				}

				var start = idx * CHUNK_SIZE_BYTES;
				var blob = file.slice( start, start + CHUNK_SIZE_BYTES );
				var formData = new FormData();
				formData.append( 'upload_id', uploadId );
				formData.append( 'index', idx );
				formData.append( 'chunk_size', CHUNK_SIZE_BYTES );
				formData.append( 'chunk', blob, 'chunk' );

				return apiFetch( '/upload/chunk', { method: 'POST', body: formData } ).then( function () {
					if ( onProgress ) {
						onProgress( Math.round( ( ( idx + 1 ) / totalChunks ) * 100 ) );
					}
					return uploadNext( idx + 1 );
				} );
			}

			return uploadNext( 0 );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Step 2: quality/format check                                        */
	/* ------------------------------------------------------------------ */

	function renderCheck() {
		var wrap = document.createElement( 'div' );

		var h2 = document.createElement( 'h2' );
		h2.textContent = t( 'stepCheck' );
		wrap.appendChild( h2 );

		var threshold = ( state.catalogue && state.catalogue.settings && state.catalogue.settings.dpi_threshold ) || 200;

		var list = document.createElement( 'div' );
		list.className = 'pps-check-list';

		state.photos.forEach( function ( photo ) {
			var row = document.createElement( 'div' );
			row.className = 'pps-check-row';

			var thumb = document.createElement( 'img' );
			thumb.className = 'pps-check-row__thumb';
			thumb.src = photo.imgUrl;
			thumb.alt = '';
			row.appendChild( thumb );

			var info = document.createElement( 'div' );
			info.className = 'pps-check-row__info';

			var dims = document.createElement( 'p' );
			dims.innerHTML = '<strong>' + photo.imgWidth + ' × ' + photo.imgHeight + ' px</strong>';
			info.appendChild( dims );

			if ( photo.suggestedMaxLongCm > 0 ) {
				var advice = document.createElement( 'p' );
				advice.textContent =
					'Aanbevolen langste zijde tot ongeveer ' +
					Math.round( photo.suggestedMaxLongCm ) +
					' cm voor een scherpe afdruk (' + threshold + ' DPI).';
				info.appendChild( advice );
			}

			row.appendChild( info );
			list.appendChild( row );
		} );

		wrap.appendChild( list );
		wrap.appendChild( actionsBar( { showBack: true } ) );

		return wrap;
	}

	/* ------------------------------------------------------------------ */
	/* Step 3: size selection (shared) + per-photo crop                    */
	/* ------------------------------------------------------------------ */

	function renderSize() {
		var wrap = document.createElement( 'div' );

		var h2 = document.createElement( 'h2' );
		h2.textContent = t( 'stepSize' );
		wrap.appendChild( h2 );
		wrap.appendChild( errorBox() );

		var intro = document.createElement( 'p' );
		intro.textContent = t( 'sizeIntro' );
		wrap.appendChild( intro );

		var list = document.createElement( 'div' );
		list.className = 'pps-format-list';

		state.photos.forEach( function ( photo, index ) {
			list.appendChild( renderPhotoFormatRow( photo, index ) );
		} );
		wrap.appendChild( list );

		var allReady = state.photos.every( function ( photo ) {
			return photo.widthCm > 0 && photo.heightCm > 0 && null !== photo.dpiInfo;
		} );
		var canContinue = allReady && ! state.dpiCheckInFlight;

		wrap.appendChild( actionsBar( { showBack: true, nextDisabled: ! canContinue } ) );

		return wrap;
	}

	/**
	 * One row per photo: a summary of its chosen format (or a prompt to
	 * choose one) and a button that expands/collapses that photo's own
	 * format picker + crop editor below it. Only one photo's panel is
	 * expanded at a time to keep the UI manageable with up to MAX_PHOTOS
	 * photos.
	 */
	function renderPhotoFormatRow( photo, index ) {
		var container = document.createElement( 'div' );

		var row = document.createElement( 'div' );
		row.className = 'pps-crop-status-row';

		var thumb = document.createElement( 'img' );
		thumb.className = 'pps-crop-status-row__thumb';
		thumb.src = photo.imgUrl;
		thumb.alt = '';
		row.appendChild( thumb );

		var label = document.createElement( 'span' );
		label.className = 'pps-crop-status-row__label';

		var summary = 'Foto ' + ( index + 1 ) + ' — ';
		if ( photo.widthCm > 0 && photo.heightCm > 0 ) {
			var formatName = photo.useCustomSize ? t( 'customSize' ) : formatNameById( photo.formatId );
			summary += formatName + ' (' + photo.widthCm + ' × ' + photo.heightCm + ' cm)';
		} else {
			summary += t( 'noFormatChosen' );
		}
		label.textContent = summary;

		if ( photo.showCropTool ) {
			var badge = document.createElement( 'span' );
			badge.className = 'pps-badge pps-badge--warning';
			badge.textContent = t( 'attentionBadge' );
			label.appendChild( badge );
		}
		row.appendChild( label );

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'pps-btn pps-btn--secondary';
		btn.textContent = photo.widthCm > 0 ? t( 'changeFormat' ) : t( 'chooseFormat' );
		btn.addEventListener( 'click', function () {
			state.activePhotoIndex = state.activePhotoIndex === index ? -1 : index;
			render();
		} );
		row.appendChild( btn );

		container.appendChild( row );

		if ( state.activePhotoIndex === index ) {
			container.appendChild( renderPhotoFormatPanel( photo ) );
		}

		return container;
	}

	function formatNameById( formatId ) {
		var format = findById( state.catalogue.formats, formatId );
		return format ? format.name : t( 'customSize' );
	}

	/**
	 * The expanded panel for one photo: standard format grid + custom size
	 * option, and (once a size is set) that photo's DPI/crop status.
	 */
	function renderPhotoFormatPanel( photo ) {
		var panel = document.createElement( 'div' );
		panel.className = 'pps-format-panel';

		var grid = document.createElement( 'div' );
		grid.className = 'pps-option-grid';

		state.catalogue.formats.forEach( function ( format ) {
			var card = buildOptionCard( {
				name: format.name,
				meta: format.width_cm + ' × ' + format.height_cm + ' cm',
				price: format.surcharge ? '+ ' + formatMoney( format.surcharge ) : '',
				selected: ! photo.useCustomSize && photo.formatId === format.id,
			} );
			card.addEventListener( 'click', function () {
				photo.useCustomSize = false;
				photo.formatId = format.id;
				photo.widthCm = format.width_cm;
				photo.heightCm = format.height_cm;
				resetPhotoFormatState( photo );
				render();
				runDpiCheckForPhoto( photo );
			} );
			grid.appendChild( card );
		} );

		var customCard = buildOptionCard( {
			name: t( 'customSize' ),
			meta: '',
			price: '',
			selected: photo.useCustomSize,
		} );
		customCard.addEventListener( 'click', function () {
			photo.useCustomSize = true;
			photo.formatId = 0;
			render();
		} );
		grid.appendChild( customCard );

		panel.appendChild( grid );

		if ( photo.useCustomSize ) {
			panel.appendChild( renderCustomSizeForm( photo ) );
		}

		if ( photo.widthCm > 0 && photo.heightCm > 0 ) {
			panel.appendChild( renderPhotoCropStatus( photo ) );
		}

		return panel;
	}

	function buildOptionCard( opts ) {
		var card = document.createElement( 'div' );
		card.className = 'pps-option-card' + ( opts.selected ? ' is-selected' : '' );

		if ( opts.image ) {
			var img = document.createElement( 'img' );
			img.className = 'pps-option-card__image';
			img.src = opts.image;
			img.alt = '';
			card.appendChild( img );
		}

		var name = document.createElement( 'div' );
		name.className = 'pps-option-card__name';
		name.textContent = opts.name;
		card.appendChild( name );

		if ( opts.meta ) {
			var meta = document.createElement( 'div' );
			meta.className = 'pps-option-card__meta';
			meta.textContent = opts.meta;
			card.appendChild( meta );
		}

		if ( opts.desc ) {
			var desc = document.createElement( 'div' );
			desc.className = 'pps-option-card__meta';
			desc.textContent = opts.desc;
			card.appendChild( desc );
		}

		if ( opts.price ) {
			var price = document.createElement( 'div' );
			price.className = 'pps-option-card__price';
			price.textContent = opts.price;
			card.appendChild( price );
		}

		return card;
	}

	function renderCustomSizeForm( photo ) {
		var box = document.createElement( 'div' );
		box.className = 'pps-custom-size';

		var settings = state.catalogue.settings;

		var row = document.createElement( 'div' );
		row.className = 'pps-custom-size__row';

		var widthField = buildNumberField(
			t( 'widthLabel' ),
			photo.widthCm || '',
			settings.min_size_cm,
			settings.max_width_cm,
			settings.custom_size_step,
			function ( val ) {
				photo.widthCm = val;
			}
		);

		var heightField = buildNumberField(
			t( 'heightLabel' ),
			photo.heightCm || '',
			settings.min_size_cm,
			settings.max_height_cm,
			settings.custom_size_step,
			function ( val ) {
				photo.heightCm = val;
			}
		);

		row.appendChild( widthField );
		row.appendChild( heightField );
		box.appendChild( row );

		var help = document.createElement( 'p' );
		help.textContent =
			'Max. ' + settings.max_width_cm + ' cm breed, ' + settings.max_height_cm + ' cm lang.';
		box.appendChild( help );

		var applyBtn = document.createElement( 'button' );
		applyBtn.type = 'button';
		applyBtn.className = 'pps-btn pps-btn--secondary';
		applyBtn.textContent = t( 'continue' );
		applyBtn.addEventListener( 'click', function () {
			resetPhotoFormatState( photo );
			render();
			runDpiCheckForPhoto( photo );
		} );
		box.appendChild( applyBtn );

		return box;
	}

	function buildNumberField( label, value, min, max, step, onChange ) {
		var field = document.createElement( 'label' );
		field.className = 'pps-field';

		var span = document.createElement( 'span' );
		span.textContent = label;
		field.appendChild( span );

		var input = document.createElement( 'input' );
		input.type = 'number';
		input.min = min;
		input.max = max;
		input.step = step;
		input.value = value;
		input.addEventListener( 'change', function () {
			onChange( parseFloat( input.value ) || 0 );
		} );
		field.appendChild( input );

		return field;
	}

	/**
	 * Clears one photo's per-format state (dpi info, crop, rotation) so a
	 * new format/size choice for that photo starts from a clean slate.
	 *
	 * @param {Object} photo
	 */
	function resetPhotoFormatState( photo ) {
		photo.dpiInfo = null;
		photo.showCropTool = false;
		photo.cropEditorOpen = false;
		photo.crop = null;
		photo.rotation = 0;
		photo.zoom = 1;
		photo.pricing = null;
	}

	/**
	 * The photo is always shown upright, at its own natural orientation --
	 * "rotation" here really means "which way is the chosen format's frame
	 * being read against the photo": 0 keeps width/height as chosen, 90
	 * swaps them (frame turned a quarter turn). Picks whichever of the two
	 * matches the photo's own landscape/portrait shape, so the frame starts
	 * out already lined up with the photo instead of cropping needlessly.
	 *
	 * @param {Object} photo
	 * @return {number} 0 or 90
	 */
	function pickDefaultRotation( photo ) {
		var photoIsLandscape = photo.imgWidth >= photo.imgHeight;
		var targetIsLandscape = photo.widthCm >= photo.heightCm;
		return photoIsLandscape === targetIsLandscape ? 0 : 90;
	}

	/**
	 * The chosen format's width/height, swapped if the frame is currently
	 * turned a quarter turn against the photo.
	 *
	 * @param {Object} photo
	 * @return {{w: number, h: number}}
	 */
	function effectiveTarget( photo ) {
		return 90 === photo.rotation
			? { w: photo.heightCm, h: photo.widthCm }
			: { w: photo.widthCm, h: photo.heightCm };
	}

	/**
	 * Re-derives dpi/needs-crop info for the current frame orientation,
	 * purely client-side (same formula as PPS_Pricing::effective_dpi), so
	 * the quality warning stays accurate after the customer turns the
	 * frame.
	 *
	 * @param {Object} photo
	 */
	function updateDpiInfoForOrientation( photo ) {
		var target = effectiveTarget( photo );
		var dpiW = effectiveDpi( photo.imgWidth, target.w );
		var dpiH = effectiveDpi( photo.imgHeight, target.h );
		var dpi = Math.min( dpiW, dpiH );
		var sourceRatio = photo.imgWidth / photo.imgHeight;
		var targetRatio = target.w / target.h;
		var threshold = photo.dpiInfo ? photo.dpiInfo.threshold : ( ( state.catalogue.settings && state.catalogue.settings.dpi_threshold ) || 200 );

		photo.dpiInfo = {
			dpi: Math.round( dpi * 10 ) / 10,
			threshold: threshold,
			below_threshold: dpi < threshold,
			needs_crop: Math.round( sourceRatio * 1000 ) !== Math.round( targetRatio * 1000 ),
		};
	}

	/**
	 * Runs /dpi-check for one photo against its own chosen format, picks a
	 * default frame orientation matching the photo's own shape, and assigns
	 * it a sensible default crop immediately -- even if the tool never gets
	 * opened, a plain crop is recorded for production.
	 *
	 * @param {Object} photo
	 */
	function runDpiCheckForPhoto( photo ) {
		if ( ! photo.widthCm || ! photo.heightCm ) {
			return;
		}

		state.dpiCheckInFlight = true;
		render();

		apiFetch( '/dpi-check', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				attachment_id: photo.attachmentId,
				width_cm: photo.widthCm,
				height_cm: photo.heightCm,
			} ),
		} )
			.then( function () {
				photo.rotation = pickDefaultRotation( photo );
				updateDpiInfoForOrientation( photo );
				photo.showCropTool = photo.dpiInfo.below_threshold || photo.dpiInfo.needs_crop;
				setDefaultCrop( photo );

				state.dpiCheckInFlight = false;
				render();
			} )
			.catch( function ( err ) {
				state.dpiCheckInFlight = false;
				state.error = err.message || t( 'sizeTooLarge' );
				render();
			} );
	}

	/**
	 * Within one photo's expanded panel: its DPI/crop status, an
	 * always-available "adjust crop" toggle, and (when opened) the crop
	 * editor itself.
	 *
	 * @param {Object} photo
	 */
	function renderPhotoCropStatus( photo ) {
		var wrap = document.createElement( 'div' );

		var row = document.createElement( 'div' );
		row.className = 'pps-crop-toggle-row';

		if ( photo.showCropTool ) {
			var badge = document.createElement( 'span' );
			badge.className = 'pps-badge pps-badge--warning';
			badge.textContent = t( 'attentionBadge' );
			row.appendChild( badge );
		}

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'pps-btn pps-btn--secondary';
		btn.textContent = t( 'adjustCrop' );
		btn.disabled = ! photo.dpiInfo;
		btn.addEventListener( 'click', function () {
			photo.cropEditorOpen = ! photo.cropEditorOpen;
			render();
		} );
		row.appendChild( btn );

		wrap.appendChild( row );

		if ( photo.dpiInfo && photo.dpiInfo.needs_crop ) {
			var mismatch = document.createElement( 'div' );
			mismatch.className = 'pps-warning pps-format-mismatch';
			mismatch.innerHTML = '<strong>' + t( 'formatMismatchTitle' ) + '</strong><br />' + t( 'formatMismatchBody' );
			wrap.appendChild( mismatch );
		}

		if ( photo.cropEditorOpen && photo.dpiInfo ) {
			wrap.appendChild( renderCropSection( photo ) );
		}

		return wrap;
	}

	/* ---- crop tool (operates on one photo object at a time; the photo   */
	/* itself is never rotated -- only the frame's orientation toggles)    */

	var cropCanvas, cropCtx, cropDrag;
	var originalImage;

	function renderCropSection( photo ) {
		var section = document.createElement( 'div' );
		section.className = 'pps-crop-editor';

		if ( photo.dpiInfo.below_threshold ) {
			var warn = document.createElement( 'div' );
			warn.className = 'pps-warning';
			warn.innerHTML = '<strong>' + t( 'dpiWarningTitle' ) + '</strong><br />' + t( 'dpiWarningBody' );
			section.appendChild( warn );
		}

		var body = document.createElement( 'p' );
		body.textContent = t( 'cropTitle' ) + ' — ' + t( 'cropBody' );
		section.appendChild( body );

		var cropBox = document.createElement( 'div' );
		cropBox.className = 'pps-crop';

		cropCanvas = document.createElement( 'canvas' );
		var box = fitImageBox( photo.imgWidth, photo.imgHeight, 560, 480 );
		cropCanvas.width = box.w;
		cropCanvas.height = box.h;
		cropCtx = cropCanvas.getContext( '2d' );
		cropBox.appendChild( cropCanvas );
		section.appendChild( cropBox );

		var controls = document.createElement( 'div' );
		controls.className = 'pps-crop-controls';

		var zoomInput = document.createElement( 'input' );
		zoomInput.type = 'range';
		zoomInput.min = '1';
		zoomInput.max = '4';
		zoomInput.step = '0.05';
		zoomInput.value = photo.zoom;
		zoomInput.addEventListener( 'input', function () {
			photo.zoom = parseFloat( zoomInput.value );
			updateCropFromZoomPan( photo );
			drawCrop( photo );
		} );
		controls.appendChild( zoomInput );

		var rotateBtn = document.createElement( 'button' );
		rotateBtn.type = 'button';
		rotateBtn.className = 'pps-btn pps-btn--secondary pps-rotate-btn';
		rotateBtn.textContent = t( 'rotate' );
		rotateBtn.addEventListener( 'click', function () {
			// Only the frame's orientation toggles here -- the photo itself
			// stays exactly as drawn; see the module comment above.
			photo.rotation = 90 === photo.rotation ? 0 : 90;
			setDefaultCrop( photo );
			updateDpiInfoForOrientation( photo );
			render();
		} );
		controls.appendChild( rotateBtn );

		section.appendChild( controls );

		loadOriginalImage( photo, function () {
			drawCrop( photo );
			attachCropDrag( photo );
		} );

		return section;
	}

	/**
	 * Largest box of the image's own aspect ratio that fits within
	 * maxW x maxH -- the crop tool always shows the whole photo, so the
	 * canvas is sized to contain it rather than to the target print ratio.
	 */
	function fitImageBox( imgW, imgH, maxW, maxH ) {
		var w = maxW;
		var h = ( imgH / imgW ) * w;
		if ( h > maxH ) {
			h = maxH;
			w = ( imgW / imgH ) * h;
		}
		return { w: Math.round( w ), h: Math.round( h ) };
	}

	/**
	 * Loads the untouched uploaded photo once per image URL. The photo is
	 * always drawn at this natural orientation -- it never gets rotated on
	 * screen, only the crop frame's orientation changes.
	 */
	function loadOriginalImage( photo, cb ) {
		if ( originalImage && originalImage.src === photo.imgUrl ) {
			cb();
			return;
		}
		originalImage = new Image();
		originalImage.crossOrigin = 'anonymous';
		originalImage.onload = cb;
		originalImage.src = photo.imgUrl;
	}

	function baseCropSize( photo ) {
		var target = effectiveTarget( photo );
		return computeBaseCrop( photo.imgWidth, photo.imgHeight, target.w, target.h );
	}

	function setDefaultCrop( photo ) {
		var base = baseCropSize( photo );
		photo.zoom = 1;
		photo.crop = {
			sw: base.sw,
			sh: base.sh,
			sx: ( photo.imgWidth - base.sw ) / 2,
			sy: ( photo.imgHeight - base.sh ) / 2,
			rotation: photo.rotation,
		};
	}

	function updateCropFromZoomPan( photo ) {
		var base = baseCropSize( photo );
		var sw = base.sw / photo.zoom;
		var sh = base.sh / photo.zoom;

		// Keep the crop window centred on its previous centre point when
		// the zoom level changes.
		var cx = photo.crop.sx + photo.crop.sw / 2;
		var cy = photo.crop.sy + photo.crop.sh / 2;

		var sx = clamp( cx - sw / 2, 0, photo.imgWidth - sw );
		var sy = clamp( cy - sh / 2, 0, photo.imgHeight - sh );

		photo.crop = { sw: sw, sh: sh, sx: sx, sy: sy, rotation: photo.rotation };
	}

	/**
	 * Draws the whole photo (scaled to fit the canvas, always at its own
	 * natural orientation -- never rotated) with a movable frame overlaid
	 * on top, in the requested format's aspect ratio -- dimmed outside the
	 * frame so it's obvious what will and won't be printed.
	 */
	function drawCrop( photo ) {
		if ( ! cropCtx || ! photo.crop ) {
			return;
		}

		var scale = cropCanvas.width / photo.imgWidth;

		cropCtx.clearRect( 0, 0, cropCanvas.width, cropCanvas.height );
		cropCtx.drawImage( originalImage, 0, 0, photo.imgWidth, photo.imgHeight, 0, 0, cropCanvas.width, cropCanvas.height );

		var frameX = photo.crop.sx * scale;
		var frameY = photo.crop.sy * scale;
		var frameW = photo.crop.sw * scale;
		var frameH = photo.crop.sh * scale;

		cropCtx.fillStyle = 'rgba(0, 0, 0, 0.55)';
		cropCtx.fillRect( 0, 0, cropCanvas.width, frameY ); // above the frame
		cropCtx.fillRect( 0, frameY + frameH, cropCanvas.width, cropCanvas.height - ( frameY + frameH ) ); // below
		cropCtx.fillRect( 0, frameY, frameX, frameH ); // left of the frame
		cropCtx.fillRect( frameX + frameW, frameY, cropCanvas.width - ( frameX + frameW ), frameH ); // right

		cropCtx.strokeStyle = '#ffffff';
		cropCtx.lineWidth = 2;
		cropCtx.strokeRect( frameX + 1, frameY + 1, frameW - 2, frameH - 2 );
	}

	/**
	 * Lets the customer drag the frame around over the (static, fully
	 * visible, never-rotated) photo to choose what falls inside it.
	 */
	function attachCropDrag( photo ) {
		var box = cropCanvas.parentElement;
		cropDrag = null;

		box.addEventListener( 'pointerdown', function ( e ) {
			var rect = cropCanvas.getBoundingClientRect();
			cropDrag = {
				x: e.clientX,
				y: e.clientY,
				sx: photo.crop.sx,
				sy: photo.crop.sy,
				// The canvas is scaled by CSS to fit its container, so
				// pointer coordinates (in CSS pixels) need to be converted
				// back to the canvas's own intrinsic pixel grid first.
				cssToCanvas: cropCanvas.width / rect.width,
			};
			box.setPointerCapture( e.pointerId );
		} );

		box.addEventListener( 'pointermove', function ( e ) {
			if ( ! cropDrag ) {
				return;
			}
			var scale = cropCanvas.width / photo.imgWidth;
			var dxSource = ( ( e.clientX - cropDrag.x ) * cropDrag.cssToCanvas ) / scale;
			var dySource = ( ( e.clientY - cropDrag.y ) * cropDrag.cssToCanvas ) / scale;

			photo.crop.sx = clamp( cropDrag.sx + dxSource, 0, photo.imgWidth - photo.crop.sw );
			photo.crop.sy = clamp( cropDrag.sy + dySource, 0, photo.imgHeight - photo.crop.sh );
			drawCrop( photo );
		} );

		[ 'pointerup', 'pointercancel', 'pointerleave' ].forEach( function ( evt ) {
			box.addEventListener( evt, function () {
				cropDrag = null;
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Step 4: mount (shared)                                              */
	/* ------------------------------------------------------------------ */

	function renderMount() {
		var wrap = document.createElement( 'div' );

		var h2 = document.createElement( 'h2' );
		h2.textContent = t( 'stepMount' );
		wrap.appendChild( h2 );

		var grid = document.createElement( 'div' );
		grid.className = 'pps-option-grid';

		state.catalogue.mounts.forEach( function ( mount ) {
			var card = buildOptionCard( {
				name: mount.name,
				desc: mount.description,
				image: mount.image,
				// Price is shown per photo in the final summary instead --
				// showing a per-m² rate here (before paper/finish are even
				// chosen) doesn't reflect what the customer actually pays.
				price: '',
				selected: state.mountId === mount.id,
			} );
			card.addEventListener( 'click', function () {
				state.mountId = mount.id;
				if ( ! mount.requires_finish ) {
					state.finishId = 0;
				}
				invalidateAllPricing();
				render();
			} );
			grid.appendChild( card );
		} );

		wrap.appendChild( grid );
		wrap.appendChild( actionsBar( { showBack: true, nextDisabled: ! state.mountId } ) );

		return wrap;
	}

	/* ------------------------------------------------------------------ */
	/* Step 5: paper (shared)                                              */
	/* ------------------------------------------------------------------ */

	function renderPaper() {
		var wrap = document.createElement( 'div' );

		var h2 = document.createElement( 'h2' );
		h2.textContent = t( 'stepPaper' );
		wrap.appendChild( h2 );

		var intro = document.createElement( 'p' );
		intro.textContent = 'Hahnemühle Digital FineArt Collection';
		wrap.appendChild( intro );

		var grid = document.createElement( 'div' );
		grid.className = 'pps-option-grid';

		state.catalogue.papers.forEach( function ( paper ) {
			// Shows what this paper actually costs for the photos/formats
			// already chosen, rather than an abstract €/m² rate.
			var cost = sumOverPhotos( function ( photo ) {
				return areaM2( photo.widthCm, photo.heightCm ) * paper.price_per_m2;
			} );

			var card = buildOptionCard( {
				name: paper.name,
				desc: paper.description,
				image: paper.image,
				price: formatMoney( cost ) + ' ' + t( 'costForYourFormat' ),
				selected: state.paperId === paper.id,
			} );
			card.addEventListener( 'click', function () {
				state.paperId = paper.id;
				invalidateAllPricing();
				render();
			} );
			grid.appendChild( card );
		} );

		wrap.appendChild( grid );
		wrap.appendChild( actionsBar( { showBack: true, nextDisabled: ! state.paperId } ) );

		return wrap;
	}

	/* ------------------------------------------------------------------ */
	/* Step 6: finish (conditional, shared)                                */
	/* ------------------------------------------------------------------ */

	function renderFinish() {
		var wrap = document.createElement( 'div' );

		var h2 = document.createElement( 'h2' );
		h2.textContent = t( 'stepFinish' );
		wrap.appendChild( h2 );

		var grid = document.createElement( 'div' );
		grid.className = 'pps-option-grid';

		state.catalogue.finishes.forEach( function ( finish ) {
			// Shows what this finish actually costs for the photos/formats
			// already chosen (lm + m² + fixed combined), rather than its
			// separate per-unit rates.
			var cost = sumOverPhotos( function ( photo ) {
				return (
					perimeterM( photo.widthCm, photo.heightCm ) * finish.price_per_lm +
					areaM2( photo.widthCm, photo.heightCm ) * finish.price_per_m2 +
					finish.price_fixed
				);
			} );

			var card = buildOptionCard( {
				name: finish.name,
				desc: finish.description,
				image: finish.image,
				price: formatMoney( cost ) + ' ' + t( 'costForYourFormat' ),
				selected: state.finishId === finish.id,
			} );
			card.addEventListener( 'click', function () {
				state.finishId = finish.id;
				invalidateAllPricing();
				render();
			} );
			grid.appendChild( card );
		} );

		wrap.appendChild( grid );
		wrap.appendChild( actionsBar( { showBack: true, nextDisabled: ! state.finishId } ) );

		return wrap;
	}

	/* ------------------------------------------------------------------ */
	/* Step 7: summary                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Clears every photo's cached price so the summary step re-fetches
	 * instead of showing stale totals. Needed whenever paper/mount/finish
	 * change, since those are shared across all photos but each photo
	 * caches its own /price result (format-related fields are invalidated
	 * separately, by resetPhotoFormatState()).
	 */
	function invalidateAllPricing() {
		state.photos.forEach( function ( photo ) {
			photo.pricing = null;
		} );
	}

	/**
	 * Prices vary per photo now (each can have its own format), so this
	 * fetches one /price result per photo, sharing only paper/mount/finish.
	 */
	function fetchAllPrices() {
		state.busy = true;
		// Deferred: called from inside renderSummary(), i.e. from inside an
		// in-progress render() call, so re-rendering synchronously here
		// would re-enter render() while it's still building the DOM.
		setTimeout( render, 0 );

		Promise.all(
			state.photos.map( function ( photo ) {
				return apiFetch( '/price', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						width_cm: photo.widthCm,
						height_cm: photo.heightCm,
						format_id: photo.formatId,
						paper_id: state.paperId,
						mount_id: state.mountId,
						finish_id: state.finishId,
					} ),
				} ).then( function ( data ) {
					photo.pricing = data;
				} );
			} )
		)
			.then( function () {
				state.busy = false;
				render();
			} )
			.catch( function ( err ) {
				state.busy = false;
				state.error = err.message || t( 'genericError' );
				render();
			} );
	}

	function renderSummary() {
		var wrap = document.createElement( 'div' );

		var h2 = document.createElement( 'h2' );
		h2.textContent = t( 'stepSummary' );
		wrap.appendChild( h2 );
		wrap.appendChild( errorBox() );

		var needsPricing = state.photos.some( function ( photo ) {
			return ! photo.pricing;
		} );
		if ( needsPricing ) {
			if ( ! state.busy ) {
				fetchAllPrices();
			}
			return wrap;
		}

		var firstPricing = state.photos[ 0 ].pricing;

		var configBox = document.createElement( 'div' );
		configBox.className = 'pps-summary';

		var configRows = [
			[ t( 'stepPaper' ), firstPricing.paper_name ],
			[ t( 'stepMount' ), firstPricing.mount_name ],
		];
		if ( firstPricing.finish_name ) {
			configRows.push( [ t( 'stepFinish' ), firstPricing.finish_name ] );
		}

		configRows.forEach( function ( pair ) {
			configBox.appendChild( summaryRow( pair[ 0 ], pair[ 1 ] ) );
		} );
		wrap.appendChild( configBox );

		var photoList = document.createElement( 'div' );
		photoList.className = 'pps-summary-photos';

		var subtotal = 0;
		state.photos.forEach( function ( photo, index ) {
			var lineTotal = photo.pricing.total * photo.quantity;
			subtotal += lineTotal;

			var row = document.createElement( 'div' );
			row.className = 'pps-summary-photo-row';

			var thumb = document.createElement( 'img' );
			thumb.className = 'pps-summary-photo-row__thumb';
			thumb.src = photo.imgUrl;
			thumb.alt = '';
			row.appendChild( thumb );

			var label = document.createElement( 'span' );
			label.className = 'pps-summary-photo-row__label';
			label.textContent =
				'Foto ' + ( index + 1 ) + ' — ' + photo.pricing.format_name +
				' (' + photo.pricing.width_cm + ' × ' + photo.pricing.height_cm + ' cm) × ' + photo.quantity;
			row.appendChild( label );

			var value = document.createElement( 'span' );
			value.className = 'pps-summary-photo-row__value';
			value.textContent = formatMoney( lineTotal );
			row.appendChild( value );

			photoList.appendChild( row );
		} );
		wrap.appendChild( photoList );

		wrap.appendChild( renderDeliveryChoice() );

		var deliveryFeeSetting = ( state.catalogue.settings && state.catalogue.settings.delivery_fee ) || 0;
		var chargedDeliveryFee = 'shipping' === state.deliveryMethod ? deliveryFeeSetting : 0;

		var totalsBox = document.createElement( 'div' );
		totalsBox.className = 'pps-summary';
		totalsBox.appendChild( summaryRow( t( 'subtotalLabel' ), formatMoney( subtotal ) ) );

		var handlingFee = ( state.catalogue.settings && state.catalogue.settings.handling_fee ) || 0;
		var grandTotal = subtotal + handlingFee + chargedDeliveryFee;
		if ( handlingFee > 0 ) {
			totalsBox.appendChild( summaryRow( t( 'handlingFeeLabel' ), formatMoney( handlingFee ) ) );
		}
		if ( chargedDeliveryFee > 0 ) {
			totalsBox.appendChild( summaryRow( t( 'deliveryFeeLabel' ), formatMoney( chargedDeliveryFee ) ) );
		}
		totalsBox.appendChild( summaryRow( t( 'total' ), formatMoney( grandTotal ), true ) );
		wrap.appendChild( totalsBox );

		if ( ! PPS_CONFIG.hasWooCommerce ) {
			var warn = document.createElement( 'div' );
			warn.className = 'pps-warning';
			warn.textContent = t( 'noWooCommerce' );
			wrap.appendChild( warn );
		}

		wrap.appendChild(
			actionsBar( {
				showBack: true,
				nextLabel: state.busy ? t( 'adding' ) : t( 'addToCart' ),
				nextDisabled: state.busy || ! PPS_CONFIG.hasWooCommerce || ! state.deliveryMethod,
				onNext: submitOrder,
			} )
		);

		return wrap;
	}

	/**
	 * Required choice between having the order shipped or picked up, shown
	 * in the summary step. Chosen before checkout so the shipping fee (if
	 * any) is reflected in the total shown to the customer.
	 */
	function renderDeliveryChoice() {
		var wrap = document.createElement( 'div' );

		var heading = document.createElement( 'h3' );
		heading.textContent = t( 'deliveryHeading' );
		wrap.appendChild( heading );

		var deliveryFeeSetting = ( state.catalogue.settings && state.catalogue.settings.delivery_fee ) || 0;

		var grid = document.createElement( 'div' );
		grid.className = 'pps-option-grid';

		[
			{
				value: 'shipping',
				name: t( 'deliveryShip' ),
				price: deliveryFeeSetting > 0 ? '+ ' + formatMoney( deliveryFeeSetting ) : '',
			},
			{
				value: 'pickup',
				name: t( 'deliveryPickup' ),
				price: '',
			},
		].forEach( function ( option ) {
			var card = buildOptionCard( {
				name: option.name,
				price: option.price,
				selected: state.deliveryMethod === option.value,
			} );
			card.addEventListener( 'click', function () {
				state.deliveryMethod = option.value;
				render();
			} );
			grid.appendChild( card );
		} );

		wrap.appendChild( grid );

		return wrap;
	}

	function summaryRow( label, value, isTotal ) {
		var row = document.createElement( 'div' );
		row.className = 'pps-summary__row' + ( isTotal ? ' pps-summary__row--total' : '' );
		var labelEl = document.createElement( 'span' );
		labelEl.textContent = label;
		var valueEl = document.createElement( 'span' );
		valueEl.textContent = value;
		row.appendChild( labelEl );
		row.appendChild( valueEl );
		return row;
	}

	function submitOrder() {
		state.busy = true;
		state.error = '';
		render();

		apiFetch( '/add-to-cart', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				paper_id: state.paperId,
				mount_id: state.mountId,
				finish_id: state.finishId,
				delivery_method: state.deliveryMethod,
				items: state.photos.map( function ( photo ) {
					return {
						attachment_id: photo.attachmentId,
						width_cm: photo.widthCm,
						height_cm: photo.heightCm,
						format_id: photo.formatId,
						crop: photo.crop || {},
						quantity: photo.quantity,
					};
				} ),
			} ),
		} )
			.then( function ( data ) {
				window.location.href = data.checkout_url;
			} )
			.catch( function ( err ) {
				state.busy = false;
				state.error = err.message || t( 'genericError' );
				render();
			} );
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	document.addEventListener( 'DOMContentLoaded', function () {
		root = document.querySelector( '[data-pps-wizard]' );
		if ( ! root ) {
			return;
		}
		i18n = PPS_CONFIG.i18n || {};
		// If a change to this file doesn't seem to appear on the site, check
		// this against the actual file's last-modified time -- a mismatch
		// means a cache (browser, page cache, or CDN) is serving an old copy.
		if ( window.console && console.log ) {
			console.log( 'Photo Print Studio wizard build: ' + PPS_CONFIG.buildVersion );
		}
		render();
	} );
} )();
