/**
 * Photo Print Studio -- front-end wizard.
 *
 * Vanilla JS, no build step: talks to the pps/v1 REST routes registered in
 * class-pps-rest.php and renders the multi-step flow into the
 * [photo_print_wizard] shortcode container.
 */
( function () {
	'use strict';

	if ( typeof PPS_CONFIG === 'undefined' ) {
		return;
	}

	var STEP_KEYS = [ 'upload', 'check', 'size', 'mount', 'paper', 'finish', 'summary' ];

	// Photos are uploaded in small chunks rather than one request so large
	// files aren't blocked by the host's upload_max_filesize/post_max_size
	// limits (settings this plugin cannot change from PHP at runtime).
	var CHUNK_SIZE_BYTES = 2 * 1024 * 1024;

	var state = {
		step: 'upload',
		file: null,
		attachmentId: null,
		imgUrl: null,
		imgWidth: 0,
		imgHeight: 0,
		suggestedMaxLongCm: 0,
		catalogue: null,
		formatId: 0,
		widthCm: 0,
		heightCm: 0,
		useCustomSize: false,
		dpiInfo: null,
		showCropTool: false,
		crop: null, // { sx, sy, sw, sh, rotation } in rotated-source pixels
		rotation: 0, // 0, 90, 180 or 270 -- degrees the photo is rotated before cropping
		zoom: 1,
		mountId: 0,
		paperId: 0,
		finishId: 0,
		pricing: null,
		busy: false,
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

	/* ------------------------------------------------------------------ */
	/* Step 1: upload                                                      */
	/* ------------------------------------------------------------------ */

	function renderUpload() {
		var wrap = document.createElement( 'div' );

		var h2 = document.createElement( 'h2' );
		h2.textContent = t( 'stepUpload' );
		wrap.appendChild( h2 );

		wrap.appendChild( errorBox() );

		var zone = document.createElement( 'div' );
		zone.className = 'pps-dropzone';

		var icon = document.createElement( 'div' );
		icon.className = 'pps-dropzone__icon';
		icon.textContent = '↑';
		zone.appendChild( icon );

		var text = document.createElement( 'p' );
		text.textContent = state.busy ? t( 'uploading' ) : t( 'dropText' );
		zone.appendChild( text );

		var input = document.createElement( 'input' );
		input.type = 'file';
		input.accept = 'image/jpeg,image/png,image/tiff,image/webp';
		zone.appendChild( input );

		zone.addEventListener( 'click', function () {
			if ( ! state.busy ) {
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
				handleFile( files[ 0 ] );
			}
		} );

		input.addEventListener( 'change', function () {
			if ( input.files && input.files.length ) {
				handleFile( input.files[ 0 ] );
			}
		} );

		wrap.appendChild( zone );

		if ( state.busy ) {
			var progress = document.createElement( 'div' );
			progress.className = 'pps-upload-progress';
			progress.setAttribute( 'data-pps-upload-progress', '' );
			progress.textContent = t( 'uploading' ) + ' ' + state.uploadProgress + '%';
			wrap.appendChild( progress );
		}

		return wrap;
	}

	function handleFile( file ) {
		state.file = file;
		state.busy = true;
		state.uploadProgress = 0;
		state.error = '';
		render();

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
				var uploadResult = results[ 0 ];
				state.catalogue = results[ 1 ];
				state.attachmentId = uploadResult.attachment_id;
				state.imgUrl = uploadResult.url;
				state.imgWidth = uploadResult.width;
				state.imgHeight = uploadResult.height;
				state.suggestedMaxLongCm = uploadResult.suggested_max_long_cm;
				state.busy = false;
				goTo( 'check' );
			} )
			.catch( function ( err ) {
				state.busy = false;
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

			function uploadNext( index ) {
				if ( index >= totalChunks ) {
					if ( onProgress ) {
						onProgress( 100 );
					}
					return apiFetch( '/upload/complete', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( { upload_id: uploadId } ),
					} );
				}

				var start = index * CHUNK_SIZE_BYTES;
				var blob = file.slice( start, start + CHUNK_SIZE_BYTES );
				var formData = new FormData();
				formData.append( 'upload_id', uploadId );
				formData.append( 'index', index );
				formData.append( 'chunk_size', CHUNK_SIZE_BYTES );
				formData.append( 'chunk', blob, 'chunk' );

				return apiFetch( '/upload/chunk', { method: 'POST', body: formData } ).then( function () {
					if ( onProgress ) {
						onProgress( Math.round( ( ( index + 1 ) / totalChunks ) * 100 ) );
					}
					return uploadNext( index + 1 );
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

		var thumb = document.createElement( 'img' );
		thumb.className = 'pps-preview-thumb';
		thumb.src = state.imgUrl;
		thumb.alt = '';
		wrap.appendChild( thumb );

		var info = document.createElement( 'p' );
		info.innerHTML =
			'<strong>' + state.imgWidth + ' × ' + state.imgHeight + ' px</strong>';
		wrap.appendChild( info );

		var advice = document.createElement( 'p' );
		var threshold = ( state.catalogue && state.catalogue.settings && state.catalogue.settings.dpi_threshold ) || 200;
		advice.textContent =
			state.suggestedMaxLongCm > 0
				? 'Op basis van deze resolutie raden we een langste zijde aan tot ongeveer ' +
				  Math.round( state.suggestedMaxLongCm ) +
				  ' cm voor een scherpe afdruk (' + threshold + ' DPI).'
				: '';
		wrap.appendChild( advice );

		wrap.appendChild( actionsBar( { showBack: true } ) );

		return wrap;
	}

	/* ------------------------------------------------------------------ */
	/* Step 3: size selection + conditional crop                           */
	/* ------------------------------------------------------------------ */

	function renderSize() {
		var wrap = document.createElement( 'div' );

		var h2 = document.createElement( 'h2' );
		h2.textContent = t( 'stepSize' );
		wrap.appendChild( h2 );
		wrap.appendChild( errorBox() );

		var grid = document.createElement( 'div' );
		grid.className = 'pps-option-grid';

		state.catalogue.formats.forEach( function ( format ) {
			var card = buildOptionCard( {
				name: format.name,
				meta: format.width_cm + ' × ' + format.height_cm + ' cm',
				price: format.surcharge ? '+ ' + formatMoney( format.surcharge ) : '',
				selected: ! state.useCustomSize && state.formatId === format.id,
			} );
			card.addEventListener( 'click', function () {
				state.useCustomSize = false;
				state.formatId = format.id;
				state.widthCm = format.width_cm;
				state.heightCm = format.height_cm;
				resetCropState();
				render();
				runDpiCheck();
			} );
			grid.appendChild( card );
		} );

		var customCard = buildOptionCard( {
			name: t( 'customSize' ),
			meta: '',
			price: '',
			selected: state.useCustomSize,
		} );
		customCard.addEventListener( 'click', function () {
			state.useCustomSize = true;
			state.formatId = 0;
			resetCropState();
			render();
		} );
		grid.appendChild( customCard );

		wrap.appendChild( grid );

		if ( state.useCustomSize ) {
			wrap.appendChild( renderCustomSizeForm() );
		}

		// The crop tool opens automatically when the photo doesn't match
		// the chosen format/DPI, but customers can also open it themselves
		// at any time to fine-tune framing or rotate the photo.
		if ( state.dpiInfo && ! state.showCropTool ) {
			var manualCropBtn = document.createElement( 'button' );
			manualCropBtn.type = 'button';
			manualCropBtn.className = 'pps-btn pps-btn--secondary pps-manual-crop-btn';
			manualCropBtn.textContent = t( 'adjustCrop' );
			manualCropBtn.addEventListener( 'click', function () {
				state.showCropTool = true;
				render();
			} );
			wrap.appendChild( manualCropBtn );
		}

		if ( state.showCropTool ) {
			wrap.appendChild( renderCropSection() );
		}

		var canContinue = state.widthCm > 0 && state.heightCm > 0 && ( ! state.showCropTool || state.crop );

		wrap.appendChild(
			actionsBar( {
				showBack: true,
				nextDisabled: ! canContinue,
				onNext: function () {
					if ( ! state.crop ) {
						// The crop tool never ran for this selection (photo
						// already matches the target ratio/DPI) -- record a
						// plain, unrotated full-image crop for production.
						state.crop = {
							sw: state.imgWidth,
							sh: state.imgHeight,
							sx: 0,
							sy: 0,
							rotation: 0,
						};
					}
					nextStep();
				},
			} )
		);

		return wrap;
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

	function renderCustomSizeForm() {
		var box = document.createElement( 'div' );
		box.className = 'pps-custom-size';

		var settings = state.catalogue.settings;

		var row = document.createElement( 'div' );
		row.className = 'pps-custom-size__row';

		var widthField = buildNumberField(
			t( 'widthLabel' ),
			state.widthCm || '',
			settings.min_size_cm,
			settings.max_width_cm,
			settings.custom_size_step,
			function ( val ) {
				state.widthCm = val;
			}
		);

		var heightField = buildNumberField(
			t( 'heightLabel' ),
			state.heightCm || '',
			settings.min_size_cm,
			settings.max_height_cm,
			settings.custom_size_step,
			function ( val ) {
				state.heightCm = val;
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
			resetCropState();
			render();
			runDpiCheck();
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

	function runDpiCheck() {
		if ( ! state.widthCm || ! state.heightCm ) {
			return;
		}

		apiFetch( '/dpi-check', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				attachment_id: state.attachmentId,
				width_cm: state.widthCm,
				height_cm: state.heightCm,
			} ),
		} )
			.then( function ( data ) {
				state.dpiInfo = data;
				state.showCropTool = data.below_threshold || data.needs_crop;
				state.zoom = 1;
				render();
			} )
			.catch( function ( err ) {
				state.error = err.message || t( 'sizeTooLarge' );
				render();
			} );
	}

	/**
	 * Clears everything derived from the previous format/size choice so a
	 * new selection starts from a clean slate.
	 */
	function resetCropState() {
		state.crop = null;
		state.dpiInfo = null;
		state.showCropTool = false;
		state.rotation = 0;
		rotatedForAngle = null;
	}

	/* ---- crop tool ---- */

	var cropCanvas, cropCtx, cropDrag;
	var originalImage, rotatedSource, rotatedWidth, rotatedHeight, rotatedForAngle;

	function renderCropSection() {
		var section = document.createElement( 'div' );

		if ( state.dpiInfo.below_threshold ) {
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
		var box = fitBox( state.widthCm / state.heightCm, 560, 480 );
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
		zoomInput.value = state.zoom;
		zoomInput.addEventListener( 'input', function () {
			state.zoom = parseFloat( zoomInput.value );
			updateCropFromZoomPan();
			drawCrop();
		} );
		controls.appendChild( zoomInput );

		var rotateBtn = document.createElement( 'button' );
		rotateBtn.type = 'button';
		rotateBtn.className = 'pps-btn pps-btn--secondary pps-rotate-btn';
		rotateBtn.textContent = t( 'rotate' );
		rotateBtn.addEventListener( 'click', function () {
			state.rotation = ( state.rotation + 90 ) % 360;
			rebuildRotatedSource();
			setDefaultCrop();
			updateDpiInfoForRotation();
			render();
		} );
		controls.appendChild( rotateBtn );

		section.appendChild( controls );

		ensureRotatedSource( function () {
			var isFirstInit = ! state.crop;
			if ( isFirstInit ) {
				setDefaultCrop();
			}
			drawCrop();
			attachCropDrag();
			if ( isFirstInit ) {
				// Re-render once so the "Verder" button (disabled until a
				// crop exists) picks up the newly-initialised crop.
				render();
			}
		} );

		return section;
	}

	function fitBox( ratio, maxW, maxH ) {
		var w = maxW;
		var h = w / ratio;
		if ( h > maxH ) {
			h = maxH;
			w = h * ratio;
		}
		return { w: Math.round( w ), h: Math.round( h ) };
	}

	/**
	 * Loads the untouched uploaded photo once per image URL.
	 *
	 * @param {Function} cb
	 */
	function loadOriginalImage( cb ) {
		if ( originalImage && originalImage.src === state.imgUrl ) {
			cb();
			return;
		}
		originalImage = new Image();
		originalImage.crossOrigin = 'anonymous';
		originalImage.onload = cb;
		originalImage.src = state.imgUrl;
	}

	/**
	 * Ensures the crop tool has a same-orientation-as-selected-rotation
	 * source to draw from: an offscreen canvas with the photo rotated by
	 * state.rotation degrees (0 = the plain image, no extra canvas needed).
	 * Cached per rotation angle so dragging/zooming never re-does this.
	 *
	 * @param {Function} cb Called once the rotated source is ready.
	 */
	function ensureRotatedSource( cb ) {
		loadOriginalImage( function () {
			rebuildRotatedSource();
			cb();
		} );
	}

	/**
	 * Synchronously (re)builds the rotated source for the current
	 * state.rotation. Assumes the original image is already loaded, which
	 * is always true once the crop tool is visible.
	 */
	function rebuildRotatedSource() {
		if ( rotatedForAngle === state.rotation && rotatedSource ) {
			return;
		}

		if ( 0 === state.rotation ) {
			rotatedSource = originalImage;
			rotatedWidth = state.imgWidth;
			rotatedHeight = state.imgHeight;
			rotatedForAngle = 0;
			return;
		}

		var swapped = 90 === state.rotation || 270 === state.rotation;
		var canvas = document.createElement( 'canvas' );
		canvas.width = swapped ? state.imgHeight : state.imgWidth;
		canvas.height = swapped ? state.imgWidth : state.imgHeight;

		var ctx = canvas.getContext( '2d' );
		ctx.save();
		ctx.translate( canvas.width / 2, canvas.height / 2 );
		ctx.rotate( ( state.rotation * Math.PI ) / 180 );
		ctx.drawImage( originalImage, -state.imgWidth / 2, -state.imgHeight / 2 );
		ctx.restore();

		rotatedSource = canvas;
		rotatedWidth = canvas.width;
		rotatedHeight = canvas.height;
		rotatedForAngle = state.rotation;
	}

	/**
	 * Re-derives dpi/needs-crop info for the current rotation, purely
	 * client-side (same formula as PPS_Pricing::effective_dpi), so the
	 * quality warning stays accurate after the customer rotates the photo.
	 */
	function updateDpiInfoForRotation() {
		if ( ! state.dpiInfo ) {
			return;
		}
		var dpiW = effectiveDpi( rotatedWidth, state.widthCm );
		var dpiH = effectiveDpi( rotatedHeight, state.heightCm );
		var dpi = Math.min( dpiW, dpiH );
		var sourceRatio = rotatedWidth / rotatedHeight;
		var targetRatio = state.widthCm / state.heightCm;

		state.dpiInfo = {
			dpi: Math.round( dpi * 10 ) / 10,
			threshold: state.dpiInfo.threshold,
			below_threshold: dpi < state.dpiInfo.threshold,
			needs_crop: Math.round( sourceRatio * 1000 ) !== Math.round( targetRatio * 1000 ),
			source_width: rotatedWidth,
			source_height: rotatedHeight,
		};
	}

	function effectiveDpi( px, cm ) {
		if ( cm <= 0 ) {
			return 0;
		}
		return px / ( cm / 2.54 );
	}

	function baseCropSize() {
		var targetRatio = state.widthCm / state.heightCm;
		var imgRatio = rotatedWidth / rotatedHeight;
		var sw, sh;
		if ( imgRatio > targetRatio ) {
			sh = rotatedHeight;
			sw = sh * targetRatio;
		} else {
			sw = rotatedWidth;
			sh = sw / targetRatio;
		}
		return { sw: sw, sh: sh };
	}

	function setDefaultCrop() {
		var base = baseCropSize();
		state.zoom = 1;
		state.crop = {
			sw: base.sw,
			sh: base.sh,
			sx: ( rotatedWidth - base.sw ) / 2,
			sy: ( rotatedHeight - base.sh ) / 2,
			rotation: state.rotation,
		};
	}

	function updateCropFromZoomPan() {
		var base = baseCropSize();
		var sw = base.sw / state.zoom;
		var sh = base.sh / state.zoom;

		// Keep the crop window centred on its previous centre point when
		// the zoom level changes.
		var cx = state.crop.sx + state.crop.sw / 2;
		var cy = state.crop.sy + state.crop.sh / 2;

		var sx = clamp( cx - sw / 2, 0, rotatedWidth - sw );
		var sy = clamp( cy - sh / 2, 0, rotatedHeight - sh );

		state.crop = { sw: sw, sh: sh, sx: sx, sy: sy, rotation: state.rotation };
	}

	function clamp( value, min, max ) {
		if ( max < min ) {
			return min;
		}
		return Math.max( min, Math.min( max, value ) );
	}

	function drawCrop() {
		if ( ! cropCtx || ! state.crop ) {
			return;
		}
		cropCtx.clearRect( 0, 0, cropCanvas.width, cropCanvas.height );
		cropCtx.drawImage(
			rotatedSource,
			state.crop.sx,
			state.crop.sy,
			state.crop.sw,
			state.crop.sh,
			0,
			0,
			cropCanvas.width,
			cropCanvas.height
		);
	}

	function attachCropDrag() {
		var box = cropCanvas.parentElement;
		cropDrag = null;

		box.addEventListener( 'pointerdown', function ( e ) {
			cropDrag = { x: e.clientX, y: e.clientY, sx: state.crop.sx, sy: state.crop.sy };
			box.setPointerCapture( e.pointerId );
		} );

		box.addEventListener( 'pointermove', function ( e ) {
			if ( ! cropDrag ) {
				return;
			}
			var factor = state.crop.sw / cropCanvas.width;
			var dx = ( e.clientX - cropDrag.x ) * factor;
			var dy = ( e.clientY - cropDrag.y ) * factor;

			state.crop.sx = clamp( cropDrag.sx - dx, 0, rotatedWidth - state.crop.sw );
			state.crop.sy = clamp( cropDrag.sy - dy, 0, rotatedHeight - state.crop.sh );
			drawCrop();
		} );

		[ 'pointerup', 'pointercancel', 'pointerleave' ].forEach( function ( evt ) {
			box.addEventListener( evt, function () {
				cropDrag = null;
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Step 4: mount                                                       */
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
				price: mount.price_per_m2 ? formatMoney( mount.price_per_m2 ) + ' / m²' : '',
				selected: state.mountId === mount.id,
			} );
			card.addEventListener( 'click', function () {
				state.mountId = mount.id;
				if ( ! mount.requires_finish ) {
					state.finishId = 0;
				}
				render();
			} );
			grid.appendChild( card );
		} );

		wrap.appendChild( grid );
		wrap.appendChild( actionsBar( { showBack: true, nextDisabled: ! state.mountId } ) );

		return wrap;
	}

	/* ------------------------------------------------------------------ */
	/* Step 5: paper                                                       */
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
			var card = buildOptionCard( {
				name: paper.name,
				desc: paper.description,
				image: paper.image,
				price: formatMoney( paper.price_per_m2 ) + ' / m²',
				selected: state.paperId === paper.id,
			} );
			card.addEventListener( 'click', function () {
				state.paperId = paper.id;
				render();
			} );
			grid.appendChild( card );
		} );

		wrap.appendChild( grid );
		wrap.appendChild( actionsBar( { showBack: true, nextDisabled: ! state.paperId } ) );

		return wrap;
	}

	/* ------------------------------------------------------------------ */
	/* Step 6: finish (conditional)                                        */
	/* ------------------------------------------------------------------ */

	function renderFinish() {
		var wrap = document.createElement( 'div' );

		var h2 = document.createElement( 'h2' );
		h2.textContent = t( 'stepFinish' );
		wrap.appendChild( h2 );

		var grid = document.createElement( 'div' );
		grid.className = 'pps-option-grid';

		state.catalogue.finishes.forEach( function ( finish ) {
			var priceParts = [];
			if ( finish.price_per_m2 ) {
				priceParts.push( formatMoney( finish.price_per_m2 ) + ' / m²' );
			}
			if ( finish.price_fixed ) {
				priceParts.push( formatMoney( finish.price_fixed ) );
			}

			var card = buildOptionCard( {
				name: finish.name,
				desc: finish.description,
				image: finish.image,
				price: priceParts.join( ' + ' ),
				selected: state.finishId === finish.id,
			} );
			card.addEventListener( 'click', function () {
				state.finishId = finish.id;
				render();
			} );
			grid.appendChild( card );
		} );

		wrap.appendChild( grid );
		wrap.appendChild(
			actionsBar( {
				showBack: true,
				nextDisabled: ! state.finishId,
				onNext: function () {
					fetchPrice();
				},
			} )
		);

		return wrap;
	}

	/* ------------------------------------------------------------------ */
	/* Step 7: summary                                                     */
	/* ------------------------------------------------------------------ */

	function fetchPrice() {
		apiFetch( '/price', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				width_cm: state.widthCm,
				height_cm: state.heightCm,
				format_id: state.formatId,
				paper_id: state.paperId,
				mount_id: state.mountId,
				finish_id: state.finishId,
			} ),
		} )
			.then( function ( data ) {
				state.pricing = data;
				goTo( 'summary' );
			} )
			.catch( function ( err ) {
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

		if ( ! state.pricing ) {
			fetchPrice();
			return wrap;
		}

		var thumb = document.createElement( 'img' );
		thumb.className = 'pps-preview-thumb';
		thumb.src = state.imgUrl;
		thumb.alt = '';
		wrap.appendChild( thumb );

		var box = document.createElement( 'div' );
		box.className = 'pps-summary';

		var rows = [
			[ t( 'stepSize' ), state.pricing.format_name + ' (' + state.pricing.width_cm + ' × ' + state.pricing.height_cm + ' cm)' ],
			[ t( 'stepPaper' ), state.pricing.paper_name ],
			[ t( 'stepMount' ), state.pricing.mount_name ],
		];

		if ( state.pricing.finish_name ) {
			rows.push( [ t( 'stepFinish' ), state.pricing.finish_name ] );
		}

		rows.forEach( function ( pair ) {
			var row = document.createElement( 'div' );
			row.className = 'pps-summary__row';
			var label = document.createElement( 'span' );
			label.textContent = pair[ 0 ];
			var value = document.createElement( 'span' );
			value.textContent = pair[ 1 ];
			row.appendChild( label );
			row.appendChild( value );
			box.appendChild( row );
		} );

		var totalRow = document.createElement( 'div' );
		totalRow.className = 'pps-summary__row pps-summary__row--total';
		var totalLabel = document.createElement( 'span' );
		totalLabel.textContent = t( 'total' );
		var totalValue = document.createElement( 'span' );
		totalValue.textContent = formatMoney( state.pricing.total );
		totalRow.appendChild( totalLabel );
		totalRow.appendChild( totalValue );
		box.appendChild( totalRow );

		wrap.appendChild( box );

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
				nextDisabled: state.busy || ! PPS_CONFIG.hasWooCommerce,
				onNext: submitOrder,
			} )
		);

		return wrap;
	}

	function submitOrder() {
		state.busy = true;
		state.error = '';
		render();

		apiFetch( '/add-to-cart', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				attachment_id: state.attachmentId,
				width_cm: state.widthCm,
				height_cm: state.heightCm,
				format_id: state.formatId,
				paper_id: state.paperId,
				mount_id: state.mountId,
				finish_id: state.finishId,
				crop: state.crop || {},
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
		render();
	} );
} )();
